<?php
declare(strict_types=1);

/**
 * LocalRouter — comunicação com os provedores
 *
 * A única parte do projeto que toca a rede: monta a chamada HTTP, decide
 * o que é falha transitória, classifica erros e apaga segredos de qualquer
 * texto que vá para o cliente ou para o log.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

/**
 * Normaliza a URL base de um provedor, tolerando erros comuns de cópia:
 * espaços nas pontas, barra final e a rota (/chat/completions, /messages,
 * /embeddings) colada junto — o router anexa a rota certa conforme o type,
 * então a URL cadastrada deve ser só a base.
 */
function provider_base_url(string $url): string
{
    $url = trim($url);
    $url = rtrim($url, '/');
    return (string) preg_replace('#/(chat/completions|messages|embeddings)$#i', '', $url);
}

/**
 * Executa a requisição e devolve ['status','body','error'].
 *
 * Com $onChunk != null o corpo é repassado em tempo real, mas só depois do
 * HTTP 200 — é o que preserva o failover. $onChunk também recebe uma STRING
 * VAZIA como batida de heartbeat (nada chegou do provedor há
 * STREAM_HEARTBEAT_SECONDS); o curl nunca chama o callback com chunk vazio,
 * então o sinal é inequívoco e dispensa um segundo parâmetro.
 *
 * Provedores com 'retries' > 0 (serverless que escala a zero, como a
 * Modal) são reconectados no MESMO host quando a falha parece o servidor
 * acordando — ver wakeup_failure().
 */
function call_provider(array $provider, array $payload, ?callable $onChunk): array
{
    $streaming = $onChunk !== null;
    $base      = provider_base_url((string) $provider['url']);
    $endpoint  = $base
        . ($provider['type'] === 'anthropic' ? '/messages' : '/' . ($provider['endpoint'] ?? 'chat/completions'));

    // Serverless (Modal) escala a zero: a primeira chamada encontra o proxy
    // sem container e falha com conexão recusada, 5xx ou o 404 "route not
    // found" enquanto ele sobe. 'retries' reconecta no MESMO provedor antes
    // de rotacionar; padrão 0 (uma tentativa, como sempre foi) nos demais.
    $attempts   = 1 + max(0, (int) ($provider['retries'] ?? 0));
    $retryDelay = (float) ($provider['retry_delay'] ?? 10);

    $status   = 0;
    $body     = '';
    $lastByte = microtime(true);
    $lastPing = microtime(true);

    // O timeout da tentativa nunca passa do que resta do orçamento total da
    // requisição: sem isso, MAX_ATTEMPTS x REQUEST_TIMEOUT viraria o tempo de
    // espera real do cliente.
    $budget  = deadline_remaining();
    $timeout = $streaming ? 0 : (int) min(REQUEST_TIMEOUT, $budget > 0 ? ceil($budget) : REQUEST_TIMEOUT);

    $ch = curl_handle();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => provider_headers($provider, $streaming),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false, // redirect levaria a chave para outro host
        CURLOPT_SSL_VERIFYPEER => true,  // explícito: um default só vale enquanto ninguém o muda
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => min(CONNECT_TIMEOUT, max(1, $timeout ?: CONNECT_TIMEOUT)),
        CURLOPT_TIMEOUT        => $timeout,
        // Em streaming a compressão atrasaria o primeiro byte; fora dele
        // gzip corta o tempo de download de respostas longas sem custo.
        CURLOPT_ACCEPT_ENCODING => $streaming ? null : '',
        // Sem timeout total no streaming, mas aborta se o provedor parar de enviar.
        CURLOPT_LOW_SPEED_LIMIT => $streaming ? 1 : 0,
        CURLOPT_LOW_SPEED_TIME  => $streaming ? STREAM_STALL_TIME : 0,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status): int {
            if (stripos($line, 'HTTP/') === 0) {
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($code >= 200) {
                    $status = $code; // ignora 1xx intermediário
                }
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$status, &$body, &$lastByte, &$lastPing, $onChunk): int {
            $lastByte = $lastPing = microtime(true);
            if ($status === 0) {
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            }
            if ($status === 200 && $onChunk !== null) {
                // Cliente foi embora: aborta o curl para parar de pagar
                // tokens por uma resposta que ninguém vai ler.
                if (connection_aborted()) {
                    return 0;
                }
                $onChunk($chunk);
                return strlen($chunk);
            }
            // Resposta buferizada vem inteira; corpo de erro tem teto de memória.
            if ($status === 200 || strlen($body) < 262144) {
                $body .= $chunk;
            }
            return strlen($chunk);
        },
        // Roda ~1x por segundo mesmo sem tráfego. É o único lugar de onde dá
        // para: cortar pelo orçamento total, perceber que o cliente saiu
        // enquanto o provedor "pensa" e mandar o heartbeat do SSE.
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_XFERINFOFUNCTION => static function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow)
            use (&$status, &$lastByte, &$lastPing, $onChunk, $streaming): int {
            if (deadline_exceeded()) {
                return 1; // aborta: o tempo da requisição inteira acabou
            }
            if (!$streaming) {
                return 0;
            }
            if (connection_aborted()) {
                return 1;
            }
            $now = microtime(true);
            if (STREAM_HEARTBEAT_SECONDS > 0
                && $status === 200
                && $now - $lastByte >= STREAM_HEARTBEAT_SECONDS
                && $now - $lastPing >= STREAM_HEARTBEAT_SECONDS
            ) {
                $lastPing = $now;
                $onChunk(''); // batida de heartbeat, não dados
            }
            return 0;
        },
    ]);

    $ok    = false;
    $error = '';
    for ($try = 1; $try <= $attempts; $try++) {
        $ok    = curl_exec($ch);
        $error = $ok === false ? curl_error($ch) : '';
        $result = ['status' => $status, 'body' => $body, 'error' => $error];
        if ($try >= $attempts || !wakeup_failure($result)) {
            return $result;
        }
        // O servidor está acordando: espera e reconecta no MESMO host. O
        // sleep é fatiado para respeitar o orçamento total da requisição e
        // parar cedo se o cliente desistir.
        $waitUntil = microtime(true) + $retryDelay;
        while (microtime(true) < $waitUntil) {
            if (deadline_exceeded() || connection_aborted()) {
                return $result;
            }
            usleep(200000);
        }
        $status = 0;
        $body   = '';
    }

    return ['status' => $status, 'body' => $body, 'error' => $error];
}

/**
 * GET simples num provedor (usado por "php index.php sync" para listar os
 * modelos disponíveis). Retorna ['status','body','error'] como call_provider.
 */
function get_provider(array $provider, string $path): array
{
    $ch = curl_handle();
    curl_setopt_array($ch, [
        CURLOPT_URL            => provider_base_url((string) $provider['url']) . '/' . ltrim($path, '/'),
        CURLOPT_HTTPHEADER     => provider_headers($provider),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    return [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body'   => is_string($body) ? $body : '',
        'error'  => $body === false ? curl_error($ch) : '',
    ];
}

/**
 * Um handle curl por processo, reaproveitado em todas as tentativas. Os
 * provedores são tentados em sequência, nunca em paralelo, então um basta —
 * e o cache de conexões do libcurl vive dentro do handle, reusando o
 * handshake TCP+TLS entre tentativas no mesmo host. curl_reset limpa as
 * opções da chamada anterior sem descartar esse cache.
 */
function curl_handle(): CurlHandle
{
    static $handle = null;
    if ($handle instanceof CurlHandle) {
        curl_reset($handle);
        return $handle;
    }
    $handle = curl_init();
    if (!$handle instanceof CurlHandle) {
        throw new RuntimeException('curl_init falhou; ext-curl está disponível?');
    }
    // O php.ini do XAMPP aponta curl.cainfo para um arquivo que não existe
    // mais em disco (curl-ca-bundle.crt some em algumas atualizações), e o
    // Windows não expõe um bundle de CA ao libcurl: sem isto, todo HTTPS
    // falha com "unable to get local issuer certificate". CURLOPT_CAINFO é
    // opção de handle — sobrevive ao curl_reset, então basta definir uma
    // vez, na criação. Vazio ou arquivo ausente = default do libcurl.
    if (defined('CA_BUNDLE') && CA_BUNDLE !== '' && is_file(CA_BUNDLE)) {
        curl_setopt($handle, CURLOPT_CAINFO, CA_BUNDLE);
    }
    return $handle;
}

function provider_headers(array $provider, bool $streaming = false): array
{
    // "Expect:" vazio desliga o 100-continue, que atrasa o primeiro byte.
    $headers = ['Content-Type: application/json', 'Expect:'];
    $headers[] = 'Accept: ' . ($streaming ? 'text/event-stream' : 'application/json');

    $key = trim((string) ($provider['key'] ?? '')); // provedor local pode não ter chave; trim tolera espaço colado na cópia
    if (($provider['type'] ?? 'openai') === 'anthropic') {
        $headers[] = 'x-api-key: ' . $key;
        $headers[] = 'anthropic-version: ' . ANTHROPIC_VERSION;
    } else {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    return $headers;
}

// =====================================================================
// ORÇAMENTO DE TEMPO DA REQUISIÇÃO
// =====================================================================

/**
 * Marca o instante em que o tempo da requisição acaba. Chamado uma vez no
 * início do fluxo; as funções de rede só leem. Estático em vez de parâmetro
 * porque o valor é o mesmo para a requisição inteira e atravessaria quatro
 * assinaturas sem nunca mudar.
 */
function deadline_start(): void
{
    deadline_at(TOTAL_DEADLINE_SECONDS > 0 ? microtime(true) + TOTAL_DEADLINE_SECONDS : 0.0);
}

function deadline_at(?float $set = null): float
{
    static $at = 0.0;
    if ($set !== null) {
        $at = $set;
    }
    return $at;
}

/** Segundos que restam (0 quando o orçamento está desligado). */
function deadline_remaining(): float
{
    $at = deadline_at();
    return $at > 0.0 ? max(0.0, $at - microtime(true)) : 0.0;
}

function deadline_exceeded(): bool
{
    $at = deadline_at();
    return $at > 0.0 && microtime(true) >= $at;
}

// =====================================================================
// CLASSIFICACAO DE FALHA
// =====================================================================

/**
 * A falha parece um servidor serverless acordando (Modal e similares):
 * conexão recusada/resetada, 5xx do proxy, ou o 404 "route not found" que
 * o proxy devolve enquanto o container não subiu. Só vale a pena tentar de
 * novo no MESMO host nesses casos — 401/403/429/400 não mudam com retry.
 */
function wakeup_failure(array $result): bool
{
    if ($result['error'] !== '') {
        return (bool) preg_match(
            '/connect|refused|reset|timed? ?out|resolve|ssl|empty reply/i',
            $result['error']
        );
    }
    if ($result['status'] >= 500) {
        return true;
    }
    return $result['status'] === 404
        && stripos($result['body'], 'route not found') !== false;
}

/** Falha que tende a passar sozinha: limite, crédito, 5xx ou rede. */
function transient_failure(array $result): bool
{
    return $result['error'] !== ''
        || in_array($result['status'], [408, 409, 425, 429, 402], true)
        || $result['status'] >= 500;
}

/**
 * Erro de configuração DO PROVEDOR: chave rejeitada ou modelo que não
 * existe lá. Não inclui 400: um 400 quase sempre vem do que o cliente
 * mandou (prompt maior que o contexto, imagem em formato não suportado,
 * parâmetro que aquele modelo não aceita). Castigar o provedor por isso
 * tiraria da fila alguém saudável por causa de uma requisição ruim.
 */
function config_failure(array $result): bool
{
    return in_array($result['status'], [401, 403, 404], true);
}

/**
 * Quanto tempo o provedor fica fora da fila depois desta falha.
 *
 * 429 volta em um minuto. Chave revogada ou id de modelo que mudou não
 * volta sozinho — insistir a cada minuto só queima tentativa de todas as
 * requisições seguintes. Erro do cliente (400) não gera castigo nenhum:
 * a próxima requisição pode estar perfeita.
 */
function failure_cooldown(array $result): int
{
    if (COOLDOWN_SECONDS <= 0) {
        return 0; // cooldown desligado desliga também a quarentena longa
    }
    if (config_failure($result)) {
        return CONFIG_ERROR_COOLDOWN > 0 ? CONFIG_ERROR_COOLDOWN : COOLDOWN_SECONDS;
    }
    return transient_failure($result) ? COOLDOWN_SECONDS : 0;
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
    $message = is_array($decoded)
        ? flatten_text($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['detail'] ?? '')
        : '';
    if ($message === '') {
        $message = $body;
    }
    // strip_tags em todos os caminhos, não só no fallback: se um provedor
    // devolver markup dentro da mensagem, ele não chega a um cliente que
    // renderize o erro na tela. Note que NÃO usamos htmlspecialchars — a
    // saída é JSON e json_encode já escapa o que importa nesse contexto;
    // escapar como HTML só trocaria os sinais da mensagem real
    // ("max_tokens > 4096") por entidades, atrapalhando quem lê o erro.
    $message = preg_replace('/\s+/', ' ', trim(strip_tags($message))) ?? '';
    return truncate_utf8(redact_secrets($message), 180);
}

/**
 * Corta em $limit bytes sem depender de ext-mbstring (que falta em boa
 * parte das hospedagens compartilhadas) e sem deixar meio caractere no
 * fim: preg_match('//u') é falso enquanto a string terminar numa sequência
 * UTF-8 incompleta, então basta recuar os poucos bytes que sobraram.
 */
function truncate_utf8(string $text, int $limit): string
{
    if (strlen($text) <= $limit) {
        return $text;
    }
    $text = substr($text, 0, $limit);
    while ($text !== '' && preg_match('//u', $text) !== 1) {
        $text = substr($text, 0, -1);
    }
    return $text;
}

/**
 * Alguns provedores devolvem a própria chave dentro da mensagem de erro.
 * Como esse texto chega ao cliente e ao log, as chaves saem antes.
 */
function redact_secrets(string $text): string
{
    // Primeiro as chaves reais que conhecemos (cobre formatos que o regex
    // abaixo não alcança), depois os formatos públicos mais comuns.
    foreach (all_configured_keys() as $key) {
        $text = str_replace($key, '[chave omitida]', $text);
    }
    $patterns = [
        '/\b(sk|gsk|csk|xai|key|nvapi|hf|AIza)[-_][A-Za-z0-9_\-]{8,}/i',
        '/\bBearer\s+[A-Za-z0-9._\-]{16,}/i',
    ];
    return preg_replace($patterns, '[chave omitida]', $text) ?? $text;
}

/**
 * Todas as chaves de provedor conhecidas, de onde quer que tenham vindo:
 * o catálogo PROVIDERS (lugar normal) e as sobrescritas pontuais nas
 * entradas de MODELS. Perder uma origem vazaria a chave dentro de uma
 * mensagem de erro.
 */
function all_configured_keys(): array
{
    // A config não muda dentro de uma requisição (é define()). Cache estático
    // evita revarrer tudo a cada log/error — redact_secrets é chamado em toda
    // tentativa e em cada mensagem de erro do provedor.
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $keys    = [];
    $collect = static function (mixed $key) use (&$keys): void {
        foreach (is_array($key) ? $key : [$key] as $one) {
            $keys[] = (string) $one;
        }
    };
    foreach (PROVIDERS as $catalog) {
        $collect($catalog['key'] ?? '');
    }
    foreach (MODELS as $config) {
        foreach (model_entries($config) as $entry) {
            if (array_key_exists('key', $entry)) {
                $collect($entry['key']);
            }
        }
    }
    $cache = array_values(array_unique(array_filter($keys, static fn (string $key): bool => strlen($key) >= 8)));
    return $cache;
}
