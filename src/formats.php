<?php
declare(strict_types=1);

/**
 * LocalRouter — tradução de formatos
 *
 * Entrada (sempre OpenAI) -> forma canônica -> payload do provedor ->
 * resposta canônica -> saída OpenAI.
 *
 * Blocos canônicos: text, thinking, image, image_url, document, audio,
 * tool_use, tool_result. Audio não existe na API Anthropic e é descartado
 * ali; thinking só aparece na saída, nunca volta numa próxima chamada.
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

// =====================================================================
// ENTRADA -> FORMA CANÔNICA
// =====================================================================

function normalize_openai_request(array $body): array
{
    $system   = [];
    $messages = [];

    foreach ($body['messages'] as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = (string) ($message['role'] ?? 'user');

        if ($role === 'system' || $role === 'developer') {
            if (INPUT_BLOCK_CLIENT_SYSTEM_PROMPT) {
                continue;
            }
            $system[] = flatten_text($message['content'] ?? '');
            continue;
        }
        if ($role === 'tool' || $role === 'function') {
            $messages[] = ['role' => 'user', 'content' => [[
                'type'        => 'tool_result',
                'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                'text'        => flatten_text($message['content'] ?? ''),
                'is_error'    => false,
            ]]];
            continue;
        }

        $blocks = openai_content_blocks($message['content'] ?? '');
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $blocks[]  = [
                'type'  => 'tool_use',
                'id'    => (string) ($call['id'] ?? ''),
                'name'  => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($arguments) ? $arguments : [],
            ];
        }
        if ($blocks !== []) {
            $messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $blocks];
        }
    }

    $tools = [];
    foreach ((array) ($body['tools'] ?? []) as $tool) {
        $function = $tool['function'] ?? $tool;
        if (!empty($function['name'])) {
            $tools[] = [
                'name'        => (string) $function['name'],
                'description' => (string) ($function['description'] ?? ''),
                'schema'      => schema_as_object(is_array($function['parameters'] ?? null) ? $function['parameters'] : []),
            ];
        }
    }

    return [
        'system'      => trim(implode("\n\n", array_filter($system))),
        'messages'    => $messages,
        'tools'       => $tools,
        'tool_choice' => normalize_openai_tool_choice($body['tool_choice'] ?? null),
        'params'      => [
            'max_tokens'  => int_or_null($body['max_tokens'] ?? $body['max_completion_tokens'] ?? null),
            'temperature' => num_or_null($body['temperature'] ?? null),
            'top_p'       => num_or_null($body['top_p'] ?? null),
            'top_k'       => int_or_null($body['top_k'] ?? null),
            'stop'        => stop_list($body['stop'] ?? null),
        ],
        'stream'         => !empty($body['stream']),
        'stream_options' => is_array($body['stream_options'] ?? null) ? $body['stream_options'] : null,
        'extra'          => pick_keys($body, PASSTHROUGH_OPENAI),
    ];
}

function openai_content_blocks(mixed $content): array
{
    if (is_string($content)) {
        if (INPUT_TRIM_WHITESPACE) {
            $content = trim($content);
        }
        return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
    }
    $blocks = [];
    foreach ((array) $content as $part) {
        if (!is_array($part)) {
            continue;
        }
        $type = (string) ($part['type'] ?? '');
        if ($type === 'text' && ($part['text'] ?? '') !== '') {
            $text = (string) $part['text'];
            if (INPUT_TRIM_WHITESPACE) {
                $text = trim($text);
            }
            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }
        } elseif ($type === 'image_url') {
            $url = (string) ($part['image_url']['url'] ?? '');
            if ($url !== '') {
                $blocks[] = image_block_from_url($url);
            }
        } elseif ($type === 'input_audio') {
            $audio = is_array($part['input_audio'] ?? null) ? $part['input_audio'] : [];
            if (($audio['data'] ?? '') !== '') {
                $blocks[] = [
                    'type'   => 'audio',
                    'format' => (string) ($audio['format'] ?? 'wav'),
                    'data'   => (string) $audio['data'],
                ];
            }
        } elseif ($type === 'file') {
            // Aceita file_data como data-URI base64. file_id (upload prévio na
            // OpenAI) não viaja entre provedores e por isso é ignorado.
            $file = is_array($part['file'] ?? null) ? $part['file'] : [];
            if (preg_match('#^data:([\w./+-]+);base64,(.*)$#s', (string) ($file['file_data'] ?? ''), $match) === 1) {
                $blocks[] = [
                    'type'       => 'document',
                    'media_type' => $match[1],
                    'data'       => $match[2],
                    'name'       => (string) ($file['filename'] ?? ''),
                ];
            }
        }
    }
    return $blocks;
}

/** data: URI vira bloco base64; URL remota vira bloco de link. */
function image_block_from_url(string $url): array
{
    if (preg_match('#^data:([\w./+-]+);base64,(.*)$#s', $url, $match) === 1) {
        return ['type' => 'image', 'media_type' => $match[1], 'data' => $match[2]];
    }
    return ['type' => 'image_url', 'url' => $url];
}

function normalize_openai_tool_choice(mixed $choice): mixed
{
    if (is_string($choice)) {
        return match ($choice) {
            'none'     => 'none',
            'required' => 'any',
            default    => 'auto',
        };
    }
    if (is_array($choice) && !empty($choice['function']['name'])) {
        return ['name' => (string) $choice['function']['name']];
    }
    return null;
}

/**
 * json_decode(assoc) não distingue {} de []: os dois viram array vazio, e na
 * volta json_encode devolve []. Ferramenta sem argumentos ("parameters": {})
 * chegaria ao provedor como lista, e JSON Schema exige objeto ali.
 *
 * Corrige só onde a especificação pede objeto: o schema em si e os mapas de
 * nome -> sub-schema. Em "required", "enum" e "allOf" o array vazio é
 * legítimo — virá-lo objeto trocaria um erro por outro.
 */
function schema_as_object(array $schema): array|stdClass
{
    if ($schema === []) {
        return new stdClass();
    }
    foreach (['properties', 'patternProperties', 'definitions', '$defs'] as $key) {
        $map = $schema[$key] ?? null;
        if (!is_array($map)) {
            continue;
        }
        // O cast para objeto também cobre o caso de uma propriedade chamada
        // "0": a chave numérica faria o array virar lista no json_encode.
        $schema[$key] = $map === []
            ? new stdClass()
            : (object) array_map(
                static fn (mixed $sub): mixed => is_array($sub) ? schema_as_object($sub) : $sub,
                $map
            );
    }
    return $schema;
}

// =====================================================================
// FORMA CANÔNICA -> PAYLOAD DO PROVEDOR
// =====================================================================

function build_payload(array $request, array $provider): array
{
    return $provider['type'] === 'anthropic'
        ? build_anthropic_payload($request, $provider['model'])
        : build_openai_payload($request, $provider['model']);
}

function build_openai_payload(array $request, string $model): array
{
    $messages = [];

    if ($request['system'] !== '') {
        $messages[] = ['role' => 'system', 'content' => $request['system']];
    }

    foreach ($request['messages'] as $message) {
        $parts     = [];
        $toolCalls = [];

        foreach ($message['content'] as $block) {
            switch ($block['type']) {
                case 'text':
                    $parts[] = ['type' => 'text', 'text' => $block['text']];
                    break;
                case 'thinking':
                    // Raciocínio é saída, não entrada: reenviá-lo confundiria
                    // o modelo e nenhum provedor aceita o bloco de volta.
                    break;
                case 'image':
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $block['media_type'] . ';base64,' . $block['data']]];
                    break;
                case 'image_url':
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $block['url']]];
                    break;
                case 'audio':
                    $parts[] = ['type' => 'input_audio', 'input_audio' => ['data' => $block['data'], 'format' => $block['format']]];
                    break;
                case 'document':
                    $file = ['file_data' => 'data:' . $block['media_type'] . ';base64,' . $block['data']];
                    if ($block['name'] !== '') {
                        $file['filename'] = $block['name'];
                    }
                    $parts[] = ['type' => 'file', 'file' => $file];
                    break;
                case 'tool_use':
                    $toolCalls[] = [
                        'id'       => $block['id'],
                        'type'     => 'function',
                        'function' => ['name' => $block['name'], 'arguments' => json_encode((object) $block['input'], JSON_UNESCAPED_UNICODE)],
                    ];
                    break;
                case 'tool_result':
                    // No dialeto OpenAI o resultado da ferramenta é uma mensagem própria.
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $block['tool_use_id'], 'content' => $block['text']];
                    break;
            }
        }

        if ($parts === [] && $toolCalls === []) {
            continue;
        }
        $entry = ['role' => $message['role']];
        // Texto puro vai como string: alguns provedores não aceitam array.
        if (count($parts) === 1 && $parts[0]['type'] === 'text') {
            $entry['content'] = $parts[0]['text'];
        } else {
            $entry['content'] = $parts !== [] ? $parts : null;
        }
        if ($toolCalls !== []) {
            $entry['tool_calls'] = $toolCalls;
        }
        $messages[] = $entry;
    }

    $payload = ['model' => $model, 'messages' => $messages];
    $params  = $request['params'];

    // Alguns provedores OpenAI-compatíveis (ex.: vLLM, LM Studio) rejeitam
    // requisições sem max_tokens; outros aceitam mas geram até o limite
    // do contexto. FORCE_MAX_TOKENS_OPENAI garante um default explícito
    // quando o cliente não enviou o campo — evita 400 do provedor e custo
    // imprevisto. Anthropic já exige o campo e é tratado no outro builder.
    $maxTokens = $params['max_tokens'] ?? (FORCE_MAX_TOKENS_OPENAI ? DEFAULT_MAX_TOKENS : null);

    if ($maxTokens !== null)             { $payload['max_tokens']  = $maxTokens; }
    if ($params['temperature'] !== null) { $payload['temperature'] = $params['temperature']; }
    if ($params['top_p'] !== null)       { $payload['top_p']       = $params['top_p']; }
    if ($params['stop'] !== [])          { $payload['stop']        = $params['stop']; }

    if ($request['tools'] !== []) {
        $payload['tools'] = array_map(static fn (array $tool): array => [
            'type'     => 'function',
            'function' => [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $tool['schema'],
            ],
        ], $request['tools']);

        $choice = $request['tool_choice'];
        if (is_array($choice)) {
            $payload['tool_choice'] = ['type' => 'function', 'function' => ['name' => $choice['name']]];
        } elseif ($choice === 'any') {
            $payload['tool_choice'] = 'required';
        } elseif ($choice === 'none' || $choice === 'auto') {
            $payload['tool_choice'] = $choice;
        }
    }

    if ($request['stream']) {
        $payload['stream'] = true;
        if (is_array($request['stream_options'])) {
            $payload['stream_options'] = $request['stream_options'];
        }
    }

    // Cliente e provedor falam OpenAI: repassa os extras que o cliente enviou
    // (seed, response_format, reasoning_effort...). O + preserva o que o
    // router já definiu. Provedores Anthropic não recebem extras: não há
    // equivalente, e inventar um seria mentir sobre o que foi pedido.
    return $payload + $request['extra'];
}

function build_anthropic_payload(array $request, string $model): array
{
    $messages = [];
    foreach ($request['messages'] as $message) {
        $blocks = [];
        foreach ($message['content'] as $block) {
            switch ($block['type']) {
                case 'text':
                    $blocks[] = ['type' => 'text', 'text' => $block['text']];
                    break;
                case 'thinking':
                    break; // ver comentário no builder openai
                case 'image':
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $block['media_type'], 'data' => $block['data']]];
                    break;
                case 'image_url':
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $block['url']]];
                    break;
                case 'document':
                    $document = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $block['media_type'], 'data' => $block['data']]];
                    if ($block['name'] !== '') {
                        $document['title'] = $block['name'];
                    }
                    $blocks[] = $document;
                    break;
                case 'audio':
                    // A API Anthropic não aceita audio de entrada: o bloco é
                    // descartado aqui e o resto da mensagem segue normalmente.
                    break;
                case 'tool_use':
                    $blocks[] = ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => (object) $block['input']];
                    break;
                case 'tool_result':
                    $blocks[] = ['type' => 'tool_result', 'tool_use_id' => $block['tool_use_id'], 'content' => $block['text'], 'is_error' => $block['is_error']];
                    break;
            }
        }
        if ($blocks === []) {
            continue;
        }
        // A API Anthropic exige alternância de papéis: funde mensagens vizinhas iguais.
        $last = count($messages) - 1;
        if ($last >= 0 && $messages[$last]['role'] === $message['role']) {
            $messages[$last]['content'] = array_merge($messages[$last]['content'], $blocks);
            continue;
        }
        $messages[] = ['role' => $message['role'], 'content' => $blocks];
    }

    $params  = $request['params'];
    $payload = [
        'model'      => $model,
        'messages'   => $messages,
        'max_tokens' => $params['max_tokens'] ?? DEFAULT_MAX_TOKENS, // obrigatório nesta API
    ];

    if ($request['system'] !== '')       { $payload['system']         = $request['system']; }
    if ($params['temperature'] !== null) { $payload['temperature']    = $params['temperature']; }
    if ($params['top_p'] !== null)       { $payload['top_p']          = $params['top_p']; }
    if ($params['top_k'] !== null)       { $payload['top_k']          = $params['top_k']; }
    if ($params['stop'] !== [])          { $payload['stop_sequences'] = $params['stop']; }

    if ($request['tools'] !== []) {
        $payload['tools'] = array_map(static fn (array $tool): array => [
            'name'         => $tool['name'],
            'description'  => $tool['description'],
            'input_schema' => $tool['schema'],
        ], $request['tools']);

        $choice = $request['tool_choice'];
        if (is_array($choice)) {
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $choice['name']];
        } elseif (in_array($choice, ['auto', 'any', 'none'], true)) {
            $payload['tool_choice'] = ['type' => $choice];
        }
    }

    if ($request['stream']) {
        $payload['stream'] = true;
    }
    return $payload;
}

// =====================================================================
// RESPOSTA DO PROVEDOR -> SAIDA OPENAI
// =====================================================================

function canonical_from_openai_response(array $response): array
{
    $message = $response['choices'][0]['message'] ?? [];
    $blocks  = [];

    // Raciocínio visível: reasoning_content (DeepSeek, OpenRouter) ou
    // reasoning (alguns gateways). Vem antes do texto porque foi produzido antes.
    $reasoning = flatten_text($message['reasoning_content'] ?? $message['reasoning'] ?? '');
    if ($reasoning !== '') {
        $blocks[] = ['type' => 'thinking', 'text' => $reasoning];
    }
    if (($message['content'] ?? null) !== null && $message['content'] !== '') {
        $blocks[] = ['type' => 'text', 'text' => flatten_text($message['content'])];
    }
    foreach ((array) ($message['tool_calls'] ?? []) as $call) {
        $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
        $blocks[]  = [
            'type'  => 'tool_use',
            'id'    => (string) ($call['id'] ?? ''),
            'name'  => (string) ($call['function']['name'] ?? ''),
            'input' => is_array($arguments) ? $arguments : [],
        ];
    }

    return [
        'id'      => (string) ($response['id'] ?? new_id('msg')),
        'content' => $blocks,
        'stop'    => canonical_stop((string) ($response['choices'][0]['finish_reason'] ?? 'stop')),
        'usage'   => [
            'input'  => (int) ($response['usage']['prompt_tokens'] ?? 0),
            'output' => (int) ($response['usage']['completion_tokens'] ?? 0),
        ],
    ];
}

function canonical_from_anthropic_response(array $response): array
{
    $blocks = [];
    foreach ((array) ($response['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $blocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
        } elseif (($block['type'] ?? '') === 'thinking') {
            $blocks[] = ['type' => 'thinking', 'text' => (string) ($block['thinking'] ?? '')];
        } elseif (($block['type'] ?? '') === 'tool_use') {
            $blocks[] = [
                'type'  => 'tool_use',
                'id'    => (string) ($block['id'] ?? ''),
                'name'  => (string) ($block['name'] ?? ''),
                'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
            ];
        }
    }

    return [
        'id'      => (string) ($response['id'] ?? new_id('msg')),
        'content' => $blocks,
        'stop'    => canonical_stop((string) ($response['stop_reason'] ?? 'end_turn')),
        'usage'   => [
            'input'  => (int) ($response['usage']['input_tokens'] ?? 0),
            'output' => (int) ($response['usage']['output_tokens'] ?? 0),
        ],
    ];
}

/**
 * HTTP 200 não garante resposta. Provedores gratuitos devolvem 200 com
 * {"error": ...}, com "choices": [] ou com content vazio quando a quota
 * acaba — entregar isso ao cliente seria transformar uma falha do provedor
 * numa resposta em branco. Aqui o gateway prefere rotacionar.
 */
function canonical_is_usable(array $canonical): bool
{
    foreach ($canonical['content'] as $block) {
        // Bloco 'thinking' sozinho não conta: o modelo raciocinou e não
        // respondeu, e o cliente ficaria com content vazio.
        if ($block['type'] === 'tool_use' || ($block['type'] === 'text' && trim($block['text']) !== '')) {
            return true;
        }
    }
    return false;
}

function render_openai_response(array $canonical, string $model): array
{
    $text      = '';
    $reasoning = '';
    $toolCalls = [];
    foreach ($canonical['content'] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        } elseif ($block['type'] === 'thinking') {
            $reasoning .= $block['text'];
        } else {
            $toolCalls[] = [
                'id'       => $block['id'] ?: new_id('call'),
                'type'     => 'function',
                'function' => ['name' => $block['name'], 'arguments' => json_encode((object) $block['input'], JSON_UNESCAPED_UNICODE)],
            ];
        }
    }

    $message = ['role' => 'assistant', 'content' => $text === '' ? null : $text];
    // Campo fora do padrão OpenAI original, mas já é convenção entre os
    // provedores de modelos de raciocínio — e clientes que não o conhecem
    // simplesmente o ignoram.
    if ($reasoning !== '') {
        $message['reasoning_content'] = $reasoning;
    }
    if ($toolCalls !== []) {
        $message['tool_calls'] = $toolCalls;
    }

    return [
        'id'      => $canonical['id'],
        'object'  => 'chat.completion',
        'created' => time(),
        'model'   => $model,
        'choices' => [[
            'index'         => 0,
            'message'       => $message,
            'finish_reason' => openai_stop($canonical['stop']),
        ]],
        'usage'   => [
            'prompt_tokens'     => $canonical['usage']['input'],
            'completion_tokens' => $canonical['usage']['output'],
            'total_tokens'      => $canonical['usage']['input'] + $canonical['usage']['output'],
        ],
    ];
}

function canonical_stop(string $reason): string
{
    return match ($reason) {
        'length', 'max_tokens'   => 'max_tokens',
        'tool_calls', 'tool_use' => 'tool_use',
        'stop_sequence'          => 'stop_sequence',
        default                  => 'end_turn',
    };
}

function openai_stop(string $canonical): string
{
    return match ($canonical) {
        'max_tokens' => 'length',
        'tool_use'   => 'tool_calls',
        default      => 'stop',
    };
}

// =====================================================================
// UTILITÁRIOS DE CONTEÚDO
// =====================================================================

/** Reduz conteúdo (string ou blocos) a texto puro. */
function flatten_text(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return is_scalar($content) ? (string) $content : '';
    }
    $parts = [];
    foreach ($content as $item) {
        if (is_string($item)) {
            $parts[] = $item;
        } elseif (is_array($item) && isset($item['text'])) {
            $parts[] = (string) $item['text'];
        }
    }
    return implode("\n", $parts);
}

function stop_list(mixed $stop): array
{
    if (is_string($stop) && $stop !== '') {
        return [$stop];
    }
    if (!is_array($stop)) {
        return [];
    }
    return array_values(array_filter(
        array_map('strval', array_filter($stop, 'is_scalar')),
        static fn (string $s): bool => $s !== ''
    ));
}

/** Copia de $source apenas as chaves listadas em $allowed que existirem. */
function pick_keys(array $source, array $allowed): array
{
    $picked = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $source) && $source[$key] !== null) {
            $picked[$key] = $source[$key];
        }
    }
    return $picked;
}

function int_or_null(mixed $value): ?int
{
    return is_numeric($value) ? (int) $value : null;
}

function num_or_null(mixed $value): int|float|null
{
    return is_numeric($value) ? $value + 0 : null;
}

// =====================================================================
// EMBEDDINGS
// =====================================================================

/**
 * POST /embeddings tem um corpo simples e igual em todo provedor de dialeto
 * openai, então não há forma canônica: normalizamos a entrada, trocamos o id
 * do modelo pelo do provedor e repassamos. A API Anthropic não tem endpoint
 * equivalente — provedores desse dialeto são recusados no check.
 */
function build_embedding_payload(array $body, string $model): array
{
    $payload = ['model' => $model, 'input' => $body['input']];

    if (isset($body['encoding_format']) && in_array($body['encoding_format'], ['float', 'base64'], true)) {
        $payload['encoding_format'] = $body['encoding_format'];
    }
    if (($dimensions = int_or_null($body['dimensions'] ?? null)) !== null && $dimensions > 0) {
        $payload['dimensions'] = $dimensions;
    }
    if (isset($body['user']) && is_string($body['user'])) {
        $payload['user'] = $body['user'];
    }
    return $payload;
}

/** O corpo do cliente traz um input aproveitável? String, ou lista de strings/tokens. */
function embedding_input_is_valid(mixed $input): bool
{
    if (is_string($input)) {
        return trim($input) !== '';
    }
    if (!is_array($input) || $input === []) {
        return false;
    }
    foreach ($input as $item) {
        if (!is_string($item) && !is_int($item) && !is_array($item)) {
            return false;
        }
    }
    return true;
}

/**
 * A resposta do provedor tem ao menos um vetor? Mesmo motivo da versão de
 * chat: free tier devolve 200 com corpo vazio quando a cota acaba.
 */
function embedding_response_is_usable(mixed $decoded): bool
{
    return is_array($decoded)
        && is_array($decoded['data'] ?? null)
        && $decoded['data'] !== []
        && !empty($decoded['data'][0]['embedding']);
}
