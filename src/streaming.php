<?php
declare(strict_types=1);

/**
 * LocalRouter — streaming SSE
 *
 * Os dois dialetos passam pela mesma máquina de estado: as linhas "data:"
 * são lidas uma a uma e reemitidas como chunks no padrão OpenAI. Nada sai
 * para o cliente antes do 200 do provedor, então o failover continua valendo.
 *
 * O dialeto openai também é reescrito, e não repassado cru, por três motivos:
 * só lendo os eventos dá para saber se o terminador chegou (provedor que
 * corta a conexão produz um curl "bem-sucedido"); provedor gratuito costuma
 * abrir o SSE e só então mandar {"error": ...}; e o cliente que pediu
 * "gpt-oss-120b" não deve receber de volta "openai/gpt-oss-120b:free".
 *
 * O id do stream é criado uma vez por requisição: numa retomada em outro
 * provedor o cliente continua vendo um stream só.
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

function make_translator(string $providerType, string $model, string $streamId, bool $includeUsage): StreamTranslator
{
    return $providerType === 'anthropic'
        ? new AnthropicStream($model, $streamId, $includeUsage)
        : new OpenAiStream($model, $streamId);
}

interface StreamTranslator
{
    /** Recebe bytes crus do provedor e devolve os bytes a enviar ao cliente. */
    public function push(string $chunk): string;

    /** Fecha o stream. Emite o terminador uma vez só. */
    public function finish(): string;

    /** Texto de resposta já emitido ao cliente (para retomar em outro provedor). */
    public function emittedText(): string;

    /**
     * Já saiu um tool_call para o cliente? Se saiu, a resposta NÃO pode ser
     * retomada em outro provedor: o cliente recebeu um "arguments" JSON pela
     * metade e um segundo provedor abriria outra chamada por cima, deixando
     * duas ferramentas quebradas no lugar de uma resposta.
     */
    public function emittedToolCall(): bool;

    /**
     * O provedor chegou ao fim de verdade? Falso quando a conexão morreu
     * antes do terminador ou quando o próprio stream trouxe um erro. É o
     * que separa "resposta completa" de "resposta cortada pela metade".
     */
    public function isComplete(): bool;

    /** Mensagem de erro que veio DENTRO do stream, se houver. */
    public function upstreamError(): string;

    /** Tokens contados pelo provedor: ['input' => int, 'output' => int]. */
    public function usage(): array;

    /**
     * Bytes crus recebidos enquanto nada útil foi emitido, com teto.
     * Serve para salvar a requisição quando o provedor ignora stream:true
     * e devolve uma resposta JSON normal.
     */
    public function rawBody(): string;

    /**
     * Avisa que este tradutor está continuando uma resposta interrompida.
     * O começo do texto que ele produzir será comparado com o final do que
     * o cliente já recebeu, e a parte repetida não é emitida duas vezes.
     */
    public function continuing(string $alreadyEmitted): void;
}

/** Parser de linhas SSE compartilhado pelos dois dialetos. */
abstract class SseTranslator implements StreamTranslator
{
    protected string $buffer = '';
    protected string $emittedText = '';
    protected string $upstreamError = '';
    protected bool $complete = false;
    protected bool $closed = false;
    protected bool $toolCall = false;
    protected array $usage = ['input' => 0, 'output' => 0];

    /** Cópia dos bytes crus, só até a primeira emissão e limitada a 256 KB. */
    private string $raw = '';
    private bool $keepRaw = true;

    /** Estado da emenda: cauda do texto anterior, texto segurado e se já resolveu. */
    private string $seamTail = '';
    private string $seamHold = '';
    private bool $seamDone = true;

    public function __construct(protected string $model, protected string $id)
    {
    }

    public function push(string $chunk): string
    {
        if ($this->keepRaw && strlen($this->raw) < 262144) {
            $this->raw .= $chunk;
        }
        $this->buffer .= $chunk;
        $output = '';

        // Consome apenas linhas completas; o resto fica no buffer.
        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line         = rtrim(substr($this->buffer, 0, $position), "\r");
            $this->buffer = substr($this->buffer, $position + 1);

            if (strncmp($line, 'data:', 5) !== 0) {
                continue; // linhas "event:", ":ping" e vazias não carregam dados
            }
            $data = trim(substr($line, 5));
            if ($data !== '') {
                $output .= $this->translate($data);
            }
        }
        if ($output !== '') {
            $this->keepRaw = false; // já é um stream de verdade; a cópia não serve mais
            $this->raw     = '';
        }
        return $output;
    }

    public function emittedText(): string
    {
        return $this->emittedText;
    }

    public function emittedToolCall(): bool
    {
        return $this->toolCall;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function upstreamError(): string
    {
        return $this->upstreamError;
    }

    public function usage(): array
    {
        return $this->usage;
    }

    public function rawBody(): string
    {
        return $this->raw;
    }

    public function continuing(string $alreadyEmitted): void
    {
        // 200 bytes bastam: a repetição, quando acontece, é do final da frase.
        $this->seamTail = substr($alreadyEmitted, -200);
        $this->seamDone = $this->seamTail === '';
    }

    /**
     * Filtra o texto de saída enquanto a emenda não foi resolvida.
     *
     * Modelos costumam recomeçar repetindo o final do que já foi dito, mesmo
     * com instrução em contrário. Seguramos os primeiros bytes da continuação,
     * procuramos o maior sufixo do texto anterior que seja prefixo dela e
     * descartamos só essa sobreposição. Devolve o texto já limpo (pode ser '').
     */
    protected function seam(string $text): string
    {
        if ($this->seamDone) {
            return $text;
        }
        $this->seamHold .= $text;
        if (strlen($this->seamHold) < strlen($this->seamTail)) {
            return ''; // segura até ter material suficiente para comparar
        }
        return $this->seamFlush();
    }

    /** Libera o texto segurado, sem a parte repetida. Chamado também no fim do stream. */
    protected function seamFlush(): string
    {
        if ($this->seamDone) {
            return '';
        }
        $this->seamDone = true;
        $held           = $this->seamHold;
        $this->seamHold = '';

        for ($size = min(strlen($this->seamTail), strlen($held)); $size > 0; $size--) {
            if (substr($this->seamTail, -$size) === substr($held, 0, $size)) {
                return substr($held, $size);
            }
        }
        return $held;
    }

    /** Recebe o conteúdo de uma linha "data:" e devolve o que enviar ao cliente. */
    abstract protected function translate(string $data): string;
}

/**
 * Provedor openai -> cliente openai. Reescreve id e model e observa o
 * terminador; o resto do chunk segue intacto (campos próprios de cada
 * provedor, como reasoning_content, continuam chegando ao cliente).
 */
final class OpenAiStream extends SseTranslator
{
    protected function translate(string $data): string
    {
        if ($data === '[DONE]') {
            $this->complete = true;
            return ''; // reemitido em finish(), uma vez só
        }
        // Sem assoc: {} decodificado como array viraria [] ao reencodar, e
        // "delta":[] quebra clientes que esperam objeto.
        $event = json_decode($data);
        if (!$event instanceof stdClass) {
            return '';
        }
        // Erro dentro de um 200: não repassa. isComplete() segue falso e o
        // gateway rotaciona em vez de entregar o erro como se fosse texto.
        if (isset($event->error)) {
            $message = $event->error->message ?? '';
            $this->upstreamError = is_string($message) ? $message : 'erro do provedor no meio do stream';
            return '';
        }

        $event->id    = $this->id;
        $event->model = $this->model;

        if (isset($event->usage)) {
            $this->usage = [
                'input'  => (int) ($event->usage->prompt_tokens ?? 0),
                'output' => (int) ($event->usage->completion_tokens ?? 0),
            ];
        }

        $hadText  = false;
        $keptText = false;
        foreach ((array) ($event->choices ?? []) as $choice) {
            if (($choice->finish_reason ?? null) !== null) {
                $this->complete = true; // provedor que esquece o [DONE] ainda conta
            }
            if (!empty($choice->delta->tool_calls)) {
                $this->toolCall = true;
            }
            $text = $choice->delta->content ?? null;
            if (is_string($text) && $text !== '') {
                $hadText              = true;
                $clean                = $this->seam($text);
                $choice->delta->content = $clean;
                $this->emittedText   .= $clean;
                $keptText             = $keptText || $clean !== '';
            }
        }
        // O chunk só tinha texto e o texto inteiro era repetição da emenda:
        // não há o que mandar. O finish_reason já foi anotado acima.
        if ($hadText && !$keptText) {
            return '';
        }
        return sse($event);
    }

    public function finish(): string
    {
        if ($this->closed) {
            return '';
        }
        $this->closed = true;
        $output       = '';
        // Stream curto demais para encher o buffer da emenda: solta o que sobrou.
        $pending = $this->seamFlush();
        if ($pending !== '') {
            $this->emittedText .= $pending;
            $output .= sse([
                'id'      => $this->id,
                'object'  => 'chat.completion.chunk',
                'created' => time(),
                'model'   => $this->model,
                'choices' => [['index' => 0, 'delta' => (object) ['content' => $pending], 'finish_reason' => null]],
            ]);
        }
        return $output . "data: [DONE]\n\n";
    }
}

/** Provedor anthropic -> cliente openai. */
final class AnthropicStream extends SseTranslator
{
    private bool $opened = false;
    private string $stop = 'end_turn';
    private array $toolIndex = [];   // índice do bloco Anthropic -> índice do tool_call OpenAI

    public function __construct(string $model, string $id, private bool $includeUsage = false)
    {
        parent::__construct($model, $id);
    }

    protected function translate(string $data): string
    {
        $event = json_decode($data, true);
        if (!is_array($event)) {
            return '';
        }
        $type = (string) ($event['type'] ?? '');
        if ($type === 'error') {
            // Mesmo tratamento do dialeto openai: vira failover.
            $this->upstreamError = (string) ($event['error']['message'] ?? 'erro do provedor no meio do stream');
            return '';
        }

        $output = '';
        if (!$this->opened) {
            $this->opened = true;
            $output      .= $this->chunk(['role' => 'assistant', 'content' => '']);
        }

        switch ($type) {
            case 'content_block_start':
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $this->toolCall = true;
                    $slot           = count($this->toolIndex);
                    $this->toolIndex[(int) ($event['index'] ?? 0)] = $slot;
                    $output .= $this->chunk(['tool_calls' => [[
                        'index'    => $slot,
                        'id'       => (string) ($block['id'] ?? new_id('call')),
                        'type'     => 'function',
                        'function' => ['name' => (string) ($block['name'] ?? ''), 'arguments' => ''],
                    ]]]);
                }
                break;

            case 'content_block_delta':
                $delta = $event['delta'] ?? [];
                $kind  = (string) ($delta['type'] ?? '');
                if ($kind === 'text_delta') {
                    $text = $this->seam((string) ($delta['text'] ?? ''));
                    if ($text !== '') {
                        $this->emittedText .= $text;
                        $output .= $this->chunk(['content' => $text]);
                    }
                } elseif ($kind === 'thinking_delta') {
                    // reasoning_content é a convenção de facto dos provedores
                    // openai-compatíveis para o raciocínio visível (DeepSeek,
                    // OpenRouter). Sem isso o bloco simplesmente sumia.
                    $thought = (string) ($delta['thinking'] ?? '');
                    if ($thought !== '') {
                        $output .= $this->chunk(['reasoning_content' => $thought]);
                    }
                } elseif ($kind === 'input_json_delta') {
                    $slot    = $this->toolIndex[(int) ($event['index'] ?? 0)] ?? 0;
                    $output .= $this->chunk(['tool_calls' => [[
                        'index'    => $slot,
                        'function' => ['arguments' => (string) ($delta['partial_json'] ?? '')],
                    ]]]);
                }
                break;

            case 'message_start':
                $this->usage['input'] = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                break;

            case 'message_delta':
                $this->stop            = canonical_stop((string) ($event['delta']['stop_reason'] ?? 'end_turn'));
                $this->usage['output'] = (int) ($event['usage']['output_tokens'] ?? $this->usage['output']);
                break;

            case 'message_stop':
                $this->complete = true;
                $output .= $this->finish();
                break;
        }
        return $output;
    }

    public function finish(): string
    {
        if ($this->closed) {
            return '';
        }
        $this->closed = true;
        $output       = '';

        $pending = $this->seamFlush();
        if ($pending !== '') {
            $this->emittedText .= $pending;
            $output .= $this->chunk(['content' => $pending]);
        }
        $output .= $this->chunk([], openai_stop($this->stop));

        // Chunk de usage só quando o cliente pediu: emitir sem pedido quebra
        // clientes que assumem choices[0] em todo chunk.
        if ($this->includeUsage) {
            $output .= sse([
                'id'      => $this->id,
                'object'  => 'chat.completion.chunk',
                'created' => time(),
                'model'   => $this->model,
                'choices' => [],
                'usage'   => [
                    'prompt_tokens'     => $this->usage['input'],
                    'completion_tokens' => $this->usage['output'],
                    'total_tokens'      => $this->usage['input'] + $this->usage['output'],
                ],
            ]);
        }
        return $output . "data: [DONE]\n\n";
    }

    private function chunk(array $delta, ?string $finishReason = null): string
    {
        return sse([
            'id'      => $this->id,
            'object'  => 'chat.completion.chunk',
            'created' => time(),
            'model'   => $this->model,
            'choices' => [['index' => 0, 'delta' => (object) $delta, 'finish_reason' => $finishReason]],
        ]);
    }
}

/** Monta um chunk SSE no padrão OpenAI (só linhas "data:"). */
function sse(array|object $data): string
{
    return 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
}

/** Comentário SSE: mantém a conexão viva sem entregar dado nenhum ao cliente. */
function sse_heartbeat(): string
{
    return ": keep-alive\n\n";
}

function send_stream_headers(): void
{
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // impede o nginx de segurar os chunks
}
