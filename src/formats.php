<?php
declare(strict_types=1);

/**
 * LocalRouter — traducao de formatos
 *
 * Entrada (sempre OpenAI) -> forma canonica -> payload do provedor
 * (openai ou anthropic) -> resposta canonica -> saida OpenAI.
 * Blocos canonicos: text, image, image_url, document, audio, tool_use,
 * tool_result. Audio nao tem equivalente na API Anthropic e e descartado
 * ao traduzir para esse dialeto.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

// =====================================================================
// ENTRADA -> FORMA CANONICA
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

        $blocks  = openai_content_blocks($message['content'] ?? '');
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
                'schema'      => is_array($function['parameters'] ?? null) ? $function['parameters'] : ['type' => 'object', 'properties' => []],
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
        return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
    }
    $blocks = [];
    foreach ((array) $content as $part) {
        if (!is_array($part)) {
            continue;
        }
        $type = (string) ($part['type'] ?? '');
        if ($type === 'text' && ($part['text'] ?? '') !== '') {
            $blocks[] = ['type' => 'text', 'text' => (string) $part['text']];
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
            // Aceita file_data como data-URI base64. file_id (upload previo na
            // OpenAI) nao viaja entre provedores e por isso e ignorado.
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

// =====================================================================
// FORMA CANONICA -> PAYLOAD DO PROVEDOR
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
                    // No dialeto OpenAI o resultado da ferramenta e uma mensagem propria.
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $block['tool_use_id'], 'content' => $block['text']];
                    break;
            }
        }

        if ($parts !== [] || $toolCalls !== []) {
            $entry = ['role' => $message['role']];
            // Texto puro vai como string: alguns provedores nao aceitam array.
            if (count($parts) === 1 && $parts[0]['type'] === 'text') {
                $entry['content'] = $parts[0]['text'];
            } elseif ($parts !== []) {
                $entry['content'] = $parts;
            } else {
                $entry['content'] = null;
            }
            if ($toolCalls !== []) {
                $entry['tool_calls'] = $toolCalls;
            }
            $messages[] = $entry;
        }
    }

    $payload = ['model' => $model, 'messages' => $messages];
    $params  = $request['params'];

    // Alguns provedores OpenAI-compatíveis (ex.: vLLM, LM Studio) rejeitam
    // requisicoes sem max_tokens; outros aceitam mas geram ate o limite
    // do contexto. FORCE_MAX_TOKENS_OPENAI garante um default explicito
    // (DEFAULT_MAX_TOKENS) quando o cliente nao enviou o campo — evita
    // 400 do provedor e custo imprevisto. So se aplica a provedores openai;
    // Anthropic ja exige max_tokens obrigatorio e e tratado em build_anthropic_payload.
    $maxTokens = $params['max_tokens'];
    if ($maxTokens === null && FORCE_MAX_TOKENS_OPENAI) {
        $maxTokens = DEFAULT_MAX_TOKENS;
    }
    if ($maxTokens !== null) { $payload['max_tokens'] = $maxTokens; }
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
    // router ja definiu. Provedores Anthropic nao recebem extras: nao ha
    // equivalente, e inventar um seria mentir sobre o que foi pedido.
    $payload += $request['extra'];
    return $payload;
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
                    // A API Anthropic nao aceita audio de entrada: o bloco e
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
        if ($blocks !== []) {
            $messages[] = ['role' => $message['role'], 'content' => $blocks];
        }
    }

    // A API Anthropic exige alternancia de papeis: funde mensagens vizinhas iguais.
    $merged = [];
    foreach ($messages as $message) {
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last]['role'] === $message['role']) {
            $merged[$last]['content'] = array_merge($merged[$last]['content'], $message['content']);
            continue;
        }
        $merged[] = $message;
    }

    $params  = $request['params'];
    $payload = [
        'model'      => $model,
        'messages'   => $merged,
        'max_tokens' => $params['max_tokens'] ?? DEFAULT_MAX_TOKENS, // obrigatorio nesta API
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

function render_openai_response(array $canonical, string $model): array
{
    $text      = '';
    $toolCalls = [];
    foreach ($canonical['content'] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        } else {
            $toolCalls[] = [
                'id'       => $block['id'] ?: new_id('call'),
                'type'     => 'function',
                'function' => ['name' => $block['name'], 'arguments' => json_encode((object) $block['input'], JSON_UNESCAPED_UNICODE)],
            ];
        }
    }

    $message = ['role' => 'assistant', 'content' => $text === '' ? null : $text];
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
        'length', 'max_tokens'      => 'max_tokens',
        'tool_calls', 'tool_use'    => 'tool_use',
        'stop_sequence'             => 'stop_sequence',
        default                     => 'end_turn',
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
// UTILITARIOS DE CONTEUDO
// =====================================================================

/** Reduz conteudo (string ou blocos) a texto puro. */
function flatten_text(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
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
    return array_values(array_filter(array_map('strval', $stop), static fn (string $s): bool => $s !== ''));
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
