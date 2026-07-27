<?php
declare(strict_types=1);

/**
 * LocalRouter — comunicacao com os provedores
 *
 * A unica parte do projeto que toca a rede: monta a chamada HTTP, decide
 * o que e falha transitoria, classifica erros e apaga segredos de qualquer
 * texto que va para o cliente ou para o log.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

/**
 * Executa a requisicao. Com $onChunk != null o corpo e repassado em tempo real,
 * mas so depois de confirmar HTTP 200 — o que preserva o failover.
 * Retorna ['status','body','error'].
 */
function call_provider(array $provider, array $payload, ?callable $onChunk): array
{
    $streaming = $onChunk !== null;
    $endpoint  = rtrim((string) $provider['url'], '/')
        . ($provider['type'] === 'anthropic' ? '/messages' : '/chat/completions');

    $status = 0;
    $body   = '';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => provider_headers($provider),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false, // redirect levaria a chave para outro host
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => $streaming ? 0 : REQUEST_TIMEOUT,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status): int {
            if (stripos($line, 'HTTP/') === 0) {
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($code >= 200) {
                    $status = $code; // ignora 1xx intermediario
                }
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$status, &$body, $onChunk): int {
            if ($status === 0) {
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            }
            if ($status === 200 && $onChunk !== null) {
                // Cliente foi embora: aborta o curl para parar de pagar
                // tokens por uma resposta que ninguem vai ler.
                if (connection_aborted()) {
                    return 0;
                }
                $onChunk($chunk);
                return strlen($chunk);
            }
            // Resposta buferizada vem inteira; corpo de erro tem teto de memoria.
            if ($status === 200 || strlen($body) < 262144) {
                $body .= $chunk;
            }
            return strlen($chunk);
        },
    ]);
    if ($streaming) {
        // Sem timeout total, mas aborta se o provedor parar de enviar bytes.
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, STREAM_STALL_TIME);
    }

    $ok    = curl_exec($ch);
    $error = $ok === false ? curl_error($ch) : '';
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'error' => $error];
}

function provider_headers(array $provider): array
{
    // "Expect:" vazio desliga o 100-continue, que atrasa o primeiro byte.
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Expect:'];
    $key = (string) ($provider['key'] ?? ''); // provedor local pode nao ter chave
    if ($provider['type'] === 'anthropic') {
        $headers[] = 'x-api-key: ' . $key;
        $headers[] = 'anthropic-version: ' . ANTHROPIC_VERSION;
    } else {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    return $headers;
}

/** Falha que tende a passar sozinha: limite, credito, 5xx ou rede. 4xx de configuracao nao entra. */
function transient_failure(array $result): bool
{
    return $result['error'] !== ''
        || in_array($result['status'], [429, 402], true)
        || $result['status'] >= 500;
}

/** Classifica a falha em texto curto para log e para a mensagem final. */
function failure_reason(array $result): string
{
    if ($result['error'] !== '') {
        return 'rede: ' . $result['error'];
    }
    $label = match (true) {
        $result['status'] === 429 => 'rate limit',
        $result['status'] === 402 => 'sem credito',
        $result['status'] === 401 || $result['status'] === 403 => 'chave rejeitada',
        $result['status'] === 404 => 'modelo inexistente no provedor',
        $result['status'] >= 500  => 'erro do provedor',
        default                   => 'HTTP ' . $result['status'],
    };
    $detail = upstream_message($result['body']);
    return $detail === '' ? $label : $label . ' (' . $detail . ')';
}

/** Extrai a mensagem de erro do provedor, truncada — nunca ecoa o corpo inteiro. */
function upstream_message(string $body): string
{
    $decoded = json_decode($body, true);
    $message = '';
    if (is_array($decoded)) {
        $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['detail'] ?? '');
    }
    if ($message === '') {
        $message = trim(strip_tags($body));
    }
    $message = preg_replace('/\s+/', ' ', $message) ?? '';
    $message = redact_secrets($message);
    return function_exists('mb_substr') ? mb_substr($message, 0, 180) : substr($message, 0, 180);
}

/**
 * Alguns provedores devolvem a propria chave dentro da mensagem de erro.
 * Como esse texto chega ao cliente e ao log, as chaves saem antes.
 */
function redact_secrets(string $text): string
{
    // Varre as duas origens possiveis: o catalogo PROVIDERS e qualquer
    // chave declarada direto na entrada do modelo (formato inline ou
    // sobrescrita pontual). Perder uma das duas vazaria a chave no erro.
    foreach (all_configured_keys() as $key) {
        $text = str_replace($key, '[chave omitida]', $text);
    }
    return preg_replace('/\b(sk|gsk|csk|xai|key)-[A-Za-z0-9_\-]{8,}/i', '[chave omitida]', $text) ?? $text;
}

/**
 * Todas as chaves de provedor conhecidas, de onde quer que tenham vindo.
 *
 * Cobertura: entradas de MODELS que trazem 'key' pontualmente. PROVIDERS
 * guarda URL e type, nao ha chave la. Nao varre outros locais futuros
 * (webhooks etc.) — se novas fontes de chave surgirem, incluir aqui para
 * continuar redatando.
 */
function all_configured_keys(): array
{
    $keys = [];
    foreach (MODELS as $config) {
        $entries = is_array($config['providers'] ?? null) ? $config['providers'] : $config;
        foreach ($entries as $entry) {
            $keys[] = (string) ($entry['key'] ?? '');
        }
    }
    return array_values(array_unique(array_filter($keys, static fn (string $key): bool => strlen($key) >= 8)));
}
