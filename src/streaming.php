<?php
declare(strict_types=1);

/**
 * LocalRouter — streaming SSE
 *
 * Provedor openai -> repasse cru byte a byte (zero risco de traducao).
 * Provedor anthropic -> maquina de estado converte os eventos para o
 * padrao de chunks da OpenAI em tempo real. Nada chega ao cliente antes
 * do HTTP 200 do provedor, entao o failover continua funcionando.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

function make_translator(string $providerType, string $model, bool $includeUsage): StreamTranslator
{
    return $providerType === 'openai'
        ? new PassThroughStream()
        : new AnthropicToOpenAiStream($model, $includeUsage);
}

interface StreamTranslator
{
    /** Recebe bytes crus do provedor e devolve os bytes a enviar ao cliente. */
    public function push(string $chunk): string;

    /** Fecha o stream (usado quando o provedor termina sem terminador proprio). */
    public function finish(): string;

    /** Encerra com evento de erro apos a resposta ja ter comecado. */
    public function abort(string $message): string;

    /** Texto de resposta ja emitido ao cliente (para retomar em outro provedor). */
    public function emittedText(): string;
}

/** Repasse literal: o provedor ja fala o dialeto do cliente. */
final class PassThroughStream implements StreamTranslator
{
    private string $emitted = '';

    public function push(string $chunk): string
    {
        $this->emitted .= $chunk;
        return $chunk;
    }

    public function finish(): string
    {
        return '';
    }

    public function abort(string $message): string
    {
        return sse(['error' => ['type' => 'upstream_error', 'message' => $message]]) . "data: [DONE]\n\n";
    }

    public function emittedText(): string
    {
        // Os chunks SSE do provedor OpenAI ja foram repassados crus ao
        // cliente. Aqui extraimos o texto dos "delta.content" para poder
        // montar a mensagem de continuacao no proximo provedor.
        // $this->emitted e imutavel apos o stream cair, entao processamos
        // direto — sem acumular em buffer (chamadas repetidas nao duplicam).
        $text = '';
        foreach (preg_split('/\r?\n/', $this->emitted) as $line) {
            if (strncmp($line, 'data:', 5) !== 0) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $event = json_decode($data, true);
            if (is_array($event)) {
                $text .= (string) ($event['choices'][0]['delta']['content'] ?? '');
            }
        }
        return $text;
    }
}

/** Base com o parser de linhas SSE, compartilhado pelos dois tradutores. */
abstract class BaseTranslator implements StreamTranslator
{
    protected string $buffer = '';
    protected string $model;
    protected string $id;

    public function __construct(string $model)
    {
        $this->model = $model;
        $this->id    = new_id('msg');
    }

    public function push(string $chunk): string
    {
        $this->buffer .= $chunk;
        $output = '';

        // Consome apenas linhas completas; o resto fica no buffer.
        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line         = rtrim(substr($this->buffer, 0, $position), "\r");
            $this->buffer = substr($this->buffer, $position + 1);

            if (strncmp($line, 'data:', 5) !== 0) {
                continue; // linhas "event:", ":ping" e vazias nao carregam dados
            }
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $event = json_decode($data, true);
            if (is_array($event)) {
                $output .= $this->translate($event);
            }
        }
        return $output;
    }

    abstract protected function translate(array $event): string;
}

/** Provedor Anthropic -> cliente OpenAI. */
final class AnthropicToOpenAiStream extends BaseTranslator
{
    private bool $opened = false;
    private string $stop = 'end_turn';
    private array $toolIndex = [];   // indice do bloco Anthropic -> indice do tool_call OpenAI
    private bool $closed = false;
    private array $usage = ['input' => 0, 'output' => 0];
    private bool $includeUsage;
    private string $emittedText = '';

    public function __construct(string $model, bool $includeUsage = false)
    {
        parent::__construct($model);
        $this->includeUsage = $includeUsage;
    }

    protected function translate(array $event): string
    {
        $output = '';
        $type   = (string) ($event['type'] ?? '');

        if (!$this->opened) {
            $this->opened = true;
            $output      .= $this->chunk(['role' => 'assistant', 'content' => '']);
        }

        switch ($type) {
            case 'content_block_start':
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $slot = count($this->toolIndex);
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
                if (($delta['type'] ?? '') === 'text_delta') {
                    $text = (string) ($delta['text'] ?? '');
                    $this->emittedText .= $text;
                    $output .= $this->chunk(['content' => $text]);
                } elseif (($delta['type'] ?? '') === 'input_json_delta') {
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
                $this->stop           = canonical_stop((string) ($event['delta']['stop_reason'] ?? 'end_turn'));
                $this->usage['output'] = (int) ($event['usage']['output_tokens'] ?? $this->usage['output']);
                break;

            case 'message_stop':
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
        $output       = $this->chunk([], openai_stop($this->stop));

        // Chunk de usage so quando o cliente pediu: emitir sem pedido quebra
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

    public function abort(string $message): string
    {
        return sse(['error' => ['type' => 'upstream_error', 'message' => $message]]) . "data: [DONE]\n\n";
    }

    public function emittedText(): string
    {
        return $this->emittedText;
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

/** Monta um chunk SSE no padrao OpenAI (so linhas "data:"). */
function sse(array $data): string
{
    return 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
}

function send_stream_headers(): void
{
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // impede o nginx de segurar os chunks
}
