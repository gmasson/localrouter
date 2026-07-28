<?php
declare(strict_types=1);

/**
 * LocalRouter — nucleo do gateway
 *
 * Fluxo de uma requisicao: guards -> autenticacao -> normalizacao ->
 * fila de provedores -> resposta. Tambem: estado opcional (cooldown e
 * rate limit), log, saida JSON/erro e a linha de comando.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

// =====================================================================
// ENTRADA E GUARDS
// =====================================================================

function main(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive'); // vale mesmo sem .htaccess

    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Health fica antes dos guards para o monitor local nunca ser barrado.
    // Sem autenticacao de proposito: a resposta nao carrega nada sensivel.
    if ($method === 'GET' && str_ends_with($path, '/health')) {
        $ready = !gateway_has_placeholder_key();
        send_json($ready ? 200 : 503, ['status' => $ready ? 'ok' : 'unconfigured']);
        return;
    }

    guard_access();
    guard_configuration();

    // Health detalhado por provedor: requer autenticacao (expoe latencia e
    // taxa de erro, que sao dados operacionais, nao secretos, mas nao sao
    // publicos). Retorna 503 com corpo vazio se metricas estiverem off.
    // Com ?probe=1 dispara o probe ativo (ver health_probe abaixo).
    if ($method === 'GET' && str_ends_with($path, '/health/providers')) {
        authenticate();
        if (isset($_GET['probe']) && HEALTH_PROBE_ENABLED) {
            $probed = health_probe();
            send_json(200, ['status' => 'ok', 'probe' => $probed]);
            return;
        }
        $snapshot = metrics_snapshot();
        if ($snapshot === []) {
            send_json(503, ['status' => 'metrics_off', 'providers' => []]);
        } else {
            send_json(200, ['status' => 'ok', 'providers' => $snapshot]);
        }
        return;
    }

    // Metricas agregadas: contagem por status, p50/p95, taxa de erro.
    // Autenticado como /chat/completions. Formato json ou prometheus.
    if ($method === 'GET' && str_ends_with($path, '/metrics')) {
        if (!METRICS_EXPOSE) {
            send_error(404, 'not_found', 'Metricas desligadas (METRICS_EXPOSE).');
            return;
        }
        authenticate();
        $snapshot = metrics_snapshot();
        $body = metrics_render($snapshot, METRICS_FORMAT);
        http_response_code(200);
        header('Content-Type: ' . (METRICS_FORMAT === 'prometheus'
            ? 'text/plain; version=0.0.4; charset=utf-8'
            : 'application/json; charset=utf-8'));
        echo $body;
        return;
    }

    if (ALLOW_ORIGIN !== '') {
        header('Access-Control-Allow-Origin: ' . ALLOW_ORIGIN);
        header('Access-Control-Allow-Headers: authorization, x-api-key, content-type, anthropic-version');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }
    }

    // Casa pelo sufixo: funciona em /chat/completions (com rewrite),
    // em /index.php/chat/completions (sem rewrite) e em qualquer subpasta.
    // A API exposta e exclusivamente no formato OpenAI; a traducao para
    // provedores Anthropic acontece por dentro.
    if (str_ends_with($path, '/chat/completions')) {
        if ($method !== 'POST') {
            send_error(405, 'invalid_request_error', 'Use POST neste endpoint.');
            return;
        }
        authenticate();
        handle_completion(read_json_body());
        return;
    }
    if (str_ends_with($path, '/models')) {
        authenticate();
        send_json(200, ['object' => 'list', 'data' => list_models()]);
        return;
    }
    send_error(404, 'not_found', 'Rota desconhecida. A API e no formato OpenAI: use /chat/completions ou /models.');
}

/** Fecha o gateway para quem nao apresenta uma chave valida. */
function authenticate(): void
{
    $sent = '';
    $auth = server_header('HTTP_AUTHORIZATION') ?: server_header('REDIRECT_HTTP_AUTHORIZATION');
    if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
        $sent = trim(substr($auth, 7));
    } elseif (server_header('HTTP_X_API_KEY') !== '') {
        $sent = trim(server_header('HTTP_X_API_KEY'));
    }

    foreach (GATEWAY_KEYS as $valid) {
        if ($sent !== '' && hash_equals((string) $valid, $sent)) {
            return;
        }
    }
    send_error(401, 'authentication_error', 'Chave de API invalida ou ausente.');
    exit;
}

function read_json_body(): array
{
    // Rejeita cedo pelo tamanho declarado e nunca le alem do teto:
    // um corpo gigante nao pode consumir memoria antes do 413.
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > MAX_BODY_BYTES) {
        send_error(413, 'invalid_request_error', 'Corpo da requisicao acima do limite.');
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || $raw === '') {
        send_error(400, 'invalid_request_error', 'Corpo da requisicao vazio.');
        exit;
    }
    if (strlen($raw) > MAX_BODY_BYTES) {
        send_error(413, 'invalid_request_error', 'Corpo da requisicao acima do limite.');
        exit;
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        send_error(400, 'invalid_request_error', 'JSON invalido.');
        exit;
    }
    return $body;
}

function list_models(): array
{
    $out = [];
    foreach (array_keys(MODELS) as $name) {
        $out[] = ['id' => $name, 'object' => 'model', 'created' => 0, 'owned_by' => 'ai-router'];
    }
    return $out;
}

/** Filtros de rede que rodam antes de qualquer processamento. */
function guard_access(): void
{
    if (ALLOWED_IPS !== [] && !ip_allowed($_SERVER['REMOTE_ADDR'] ?? '')) {
        send_error(403, 'permission_error', 'Origem nao autorizada.');
        exit;
    }
    // Recusa em vez de redirecionar: se a chave ja chegou por HTTP, ela ja
    // trafegou exposta — um redirect so faria o cliente reenvia-la.
    if (REQUIRE_HTTPS && !request_is_https() && !request_is_local()) {
        send_error(403, 'permission_error', 'Use HTTPS: chaves de API nao trafegam em HTTP puro.');
        exit;
    }
}

function ip_allowed(string $ip, array $list = ALLOWED_IPS): bool
{
    foreach ($list as $allowed) {
        $allowed = (string) $allowed;
        if ($allowed === $ip) {
            return true;
        }
        // Prefixo de faixa: '192.168.' (IPv4) ou 'fd00:' (IPv6).
        if ((str_ends_with($allowed, '.') || str_ends_with($allowed, ':')) && str_starts_with($ip, $allowed)) {
            return true;
        }
    }
    return false;
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    // Atras de proxy TLS (Cloudflare etc.) o PHP so ve HTTP; o proxy avisa
    // via X-Forwarded-Proto. Mas qualquer cliente pode forjar esse cabecalho,
    // entao so confiamos nele quando a requisicao veio de um proxy listado
    // em TRUSTED_PROXIES (mesma logica de ALLOWED_IPS: IP exato ou prefixo).
    if (TRUSTED_PROXIES !== [] && ip_allowed($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_PROXIES)) {
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    return false;
}

function request_is_local(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** Chave de fabrica que o gateway se recusa a aceitar em producao. */
function placeholder_key(): string
{
    return 'sk-lr-troque-esta-chave';
}

/**
 * Recusa servir com a configuracao de fabrica. Um gateway publicado com a
 * chave de exemplo entrega todas as chaves de provedor para quem achar a URL.
 */
function gateway_has_placeholder_key(): bool
{
    if (GATEWAY_KEYS === []) {
        return true;
    }
    foreach (GATEWAY_KEYS as $key) {
        if ((string) $key === placeholder_key()) {
            return true;
        }
    }
    return false;
}

function guard_configuration(): void
{
    if (gateway_has_placeholder_key()) {
        send_error(503, 'configuration_error', 'Defina uma chave propria em GATEWAY_KEYS antes de usar (php index.php genkey).');
        exit;
    }
}

// =====================================================================
// FLUXO PRINCIPAL: NORMALIZA -> ROTACIONA -> RESPONDE
// =====================================================================

function handle_completion(array $body): void
{
    // Request ID: correlaciona todas as tentativas (provedores, retries,
    // fallbacks) de uma unica chamada do cliente. Devolvido no header
    // X-Request-Id para o cliente e gravado em log/metricas — essencial
    // para depurar "falhou em algum provedor" sem adivinhar qual.
    $requestId = new_id('req');
    if (REQUEST_ID_HEADER && !headers_sent()) {
        header('X-Request-Id: ' . $requestId);
    }

    $model = (string) ($body['model'] ?? '');
    if ($model === '' || !isset(MODELS[$model])) {
        send_error(404, 'invalid_request_error', 'Modelo nao configurado neste gateway.');
        return;
    }
    if (!isset($body['messages']) || !is_array($body['messages']) || $body['messages'] === []) {
        send_error(400, 'invalid_request_error', 'Campo "messages" ausente ou vazio.');
        return;
    }

    $request = normalize_openai_request($body);

    if ($request['messages'] === []) {
        send_error(400, 'invalid_request_error', 'Nenhuma mensagem utilizavel apos a normalizacao.');
        return;
    }

    if (($retryAfter = rate_limited_seconds()) > 0) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo json_encode(['error' => [
            'message' => 'Limite de requisicoes do gateway atingido. Aguarde ' . $retryAfter . 's.',
            'type' => 'rate_limit_error',
            'code' => 429,
        ]], JSON_UNESCAPED_UNICODE);
        return;
    }

    $candidates = collect_candidates($model);

    $lastStatus = 503;
    $lastError  = 'Nenhum provedor configurado para este modelo.';
    $attempt    = 0;
    $partial    = ''; // texto ja enviado ao cliente em stream interrompido

    foreach ($candidates as $candidate) {
        $attempt++;
        // Cada camada so preenche o que ainda esta em branco, entao aplicar
        // da mais especifica para a mais generica produz a precedencia:
        // requisicao > provedor > modelo > DEFAULT_PARAMS.
        $effective = apply_params($request, $candidate['provider']['params'] ?? []);
        $effective = apply_params($effective, $candidate['params']);
        $effective = apply_params($effective, DEFAULT_PARAMS);

        // system_prompt do modelo: injeta ANTES das mensagens do app. Se o
        // app tambem enviar system, o do modelo vem primeiro e o do app e
        // anexado em seguida (precedencia do app mantida no conteudo).
        $effective = apply_system_prompt($effective, $candidate['system_prompt'] ?? '');

        // Retomada de stream interrompido: se ja enviamos parte da resposta
        // ao cliente, pedimos ao proximo provedor que CONTINUE a partir de
        // onde parou, em vez de recomecar do zero (o que duplicaria o texto).
        if ($partial !== '') {
            $effective = inject_continuation($effective, $partial);
        }

        $result = $effective['stream']
            ? try_provider_stream($candidate['provider'], $effective, $model, $attempt, $candidate['model'], $requestId)
            : try_provider_buffered($candidate['provider'], $effective, $model, $attempt, $candidate['model'], $requestId);

        // Retry do mesmo provedor para falha de rede pura (timeout, DNS,
        // conexao recusada — status 0, sem HTTP). Esses erros costumam
        // ser transitivos e baratos de tentar de novo; rotacionar ja
        // pagaria a latencia de qualquer jeito. So vale para nao-streaming
        // ja em andamento: se o stream ja emitia bytes, o parcial foi
        // guardado e a retomada acontece no proximo provedor (nao aqui).
        if (!$result['done']
            && $result['status'] === 0
            && RETRY_SAME_PROVIDER > 0
            && $partial === ''
        ) {
            for ($extra = 1; $extra <= RETRY_SAME_PROVIDER; $extra++) {
                $result = $effective['stream']
                    ? try_provider_stream($candidate['provider'], $effective, $model, $attempt, $candidate['model'], $requestId)
                    : try_provider_buffered($candidate['provider'], $effective, $model, $attempt, $candidate['model'], $requestId);
                if ($result['done'] || $result['status'] !== 0) {
                    break;
                }
            }
        }

        if ($result['done']) {
            return; // resposta ja entregue ao cliente
        }
        $lastStatus = $result['status'];
        $lastError  = $result['error'];

        // Stream caiu no meio: guarda o parcial para o proximo provedor
        // continuar e mantem o loop vivo — nao aborta a execucao.
        if (isset($result['partial']) && $result['partial'] !== '') {
            $partial .= $result['partial'];
        }
    }

    // Lista esgotada. Se ja haviamos emitido bytes em stream, o cliente
    // recebeu o que deu; fechamos o SSE com [DONE] (nao cabe um JSON de
    // erro por cima de um stream em andamento). Sem stream, devolve erro.
    if ($partial !== '') {
        echo "data: [DONE]\n\n";
        flush();
        return;
    }
    send_error(
        $lastStatus >= 400 && $lastStatus < 600 ? $lastStatus : 502,
        'upstream_error',
        'Todos os provedores falharam. Ultimo erro: ' . $lastError
    );
}

/**
 * Injeta o conteudo parcial ja enviado ao cliente como uma mensagem
 * assistant, seguida de uma instrucao de sistema pedindo a continuacao.
 * Assim o proximo provedor retoma de onde o anterior parou, sem duplicar.
 *
 * Detalhes que importam para o provedor receber a sessao inteira:
 *  - $request ja carrega TODAS as mensagens originais (system + historico
 *    + pergunta atual); so acrescentamos o parcial no final.
 *  - A instrucao de continuacao vai no system, nao como turno de usuario,
 *    para nao poluir o contexto nem confundir a alternancia de papeis.
 *  - tool_choice e forçado para 'none': queremos prosa, nao tool call.
 *    As tools seguem no payload (caso o modelo precise de contexto), mas
 *    o provedor nao pode decidir chamar uma ferramenta no meio da retomada.
 */
function inject_continuation(array $request, string $partial): array
{
    $request['messages'][] = [
        'role'    => 'assistant',
        'content' => [['type' => 'text', 'text' => $partial]],
    ];

    $instruction = 'A resposta anterior foi interrompida exatamente aqui. '
        . 'Continue a partir deste ponto, retomando no meio da palavra '
        . 'ou frase se necessario. Nao repita o que ja foi dito.';
    $request['system'] = $request['system'] === ''
        ? $instruction
        : $request['system'] . "\n\n" . $instruction;

    // Forca texto: a retomada nao pode virar chamada de ferramenta.
    $request['tool_choice'] = 'none';

    return $request;
}

/**
 * Monta a fila de tentativas: provedores do modelo pedido, depois os do
 * fallback (se houver), pulando quem esta de castigo, ate MAX_ATTEMPTS.
 */
function collect_candidates(string $model): array
{
    $candidates = [];
    $queue      = [$model];
    $visited    = [];

    while ($queue !== []) {
        $current = array_shift($queue);
        if (isset($visited[$current]) || !isset(MODELS[$current])) {
            continue; // protege contra ciclo A->B->A no MODEL_FALLBACKS
        }
        $visited[$current] = true;

        // O modelo aceita duas formas: lista simples de provedores, ou
        // ['params' => [...], 'providers' => [...]].
        $config       = MODELS[$current];
        $entries      = is_array($config['providers'] ?? null) ? $config['providers'] : $config;
        $modelParams  = is_array($config['params'] ?? null) ? $config['params'] : [];
        $systemPrompt = is_string($config['system_prompt'] ?? null) ? trim($config['system_prompt']) : '';

        // Resolve cada entrada contra PROVIDERS antes de ordenar; entrada
        // com referencia quebrada e ignorada (php index.php check aponta).
        $resolved = [];
        foreach ($entries as $entry) {
            $provider = resolve_provider($entry);
            if ($provider !== null) {
                $resolved[] = $provider;
            }
        }

        // Pula provedores remotos sem chave em runtime: a chamada iria falhar
        // no 401 e gastar uma tentativa a toa. Provedores locais (Ollama)
        // rodam sem chave legitimamente e seguem na fila.
        if (SKIP_EMPTY_REMOTE_KEY) {
            $resolved = array_values(array_filter(
                $resolved,
                static fn (array $p): bool => $p['key'] !== '' || provider_is_local($p['url'])
            ));
        }

        // 'random' sorteia DENTRO do modelo respeitando o peso de cada
        // provedor; o fallback vem sempre depois. 'priority' segue o array.
        $ordered = STRATEGY === 'random' ? weighted_shuffle($resolved) : $resolved;
        foreach ($ordered as $provider) {
            $candidates[] = [
                'model'         => $current,
                'provider'      => $provider,
                'params'        => $modelParams,
                'system_prompt' => $systemPrompt,
            ];
        }

        $fallback = MODEL_FALLBACKS[$current] ?? null;
        if (is_string($fallback) && $fallback !== '') {
            $queue[] = $fallback;
        }
    }

    if (COOLDOWN_SECONDS > 0 || BREAKER_FAILURES > 0) {
        $state = state_read();
        $alive = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => !cooldown_active($c['provider'], $state)
                && !breaker_is_open($c['provider'], $state)
        ));
        // Todos de castigo/abertos? Ignora: tentar e melhor que falhar parado.
        if ($alive !== []) {
            $candidates = $alive;
        }
    }

    // MAX_ATTEMPTS e aplicado POR MODELO, nao sobre a fila inteira: assim o
    // fallback entre modelos (MODEL_FALLBACKS) continua valendo mesmo quando
    // o modelo principal tem provedores suficientes para esgotar o teto sozinho.
    if (MAX_ATTEMPTS > 0) {
        $perModel = [];
        foreach ($candidates as $candidate) {
            $perModel[$candidate['model']][] = $candidate;
        }
        $candidates = [];
        foreach ($perModel as $group) {
            foreach (array_slice($group, 0, MAX_ATTEMPTS) as $candidate) {
                $candidates[] = $candidate;
            }
        }
    }
    return $candidates;
}

/**
 * Injeta o system_prompt do modelo ANTES do system que o app enviou.
 * Se o modelo nao definir system_prompt, devolve $request intacto.
 * Precedencia do app e mantida: o conteudo que o app passou vem depois
 * (mais forte na pratica, pois modelos dao peso a ordem do prompt).
 */
function apply_system_prompt(array $request, string $systemPrompt): array
{
    if ($systemPrompt === '') {
        return $request;
    }
    $request['system'] = $request['system'] === ''
        ? $systemPrompt
        : $systemPrompt . "\n\n" . $request['system'];
    return $request;
}

/**
 * Preenche parametros que ainda nao foram definidos. Nunca sobrescreve:
 * o que o app enviou vale mais que a configuracao, sempre. Para forcar um
 * valor independentemente do que o app pedir, troque as verificacoes de
 * "esta em branco" por atribuicao direta.
 */
function apply_params(array $request, array $params): array
{
    foreach ($params as $name => $value) {
        if ($value === null || $value === []) {
            continue; // "sem opiniao": deixa a proxima camada decidir
        }
        switch ($name) {
            case 'temperature':
            case 'top_p':
                if ($request['params'][$name] === null) {
                    $request['params'][$name] = num_or_null($value);
                }
                break;
            case 'top_k':
            case 'max_tokens':
                if ($request['params'][$name] === null) {
                    $request['params'][$name] = int_or_null($value);
                }
                break;
            case 'stop':
            case 'stop_sequences':
                if ($request['params']['stop'] === []) {
                    $request['params']['stop'] = stop_list($value);
                }
                break;
            default:
                // Demais parametros seguem a regra do passthrough: so valem
                // para provedores openai e so se estiverem na allowlist.
                if (in_array($name, PASSTHROUGH_OPENAI, true) && !array_key_exists($name, $request['extra'])) {
                    $request['extra'][$name] = $value;
                }
                break;
        }
    }
    return $request;
}

/**
 * Junta a entrada do modelo com a URL e o tipo do provedor nomeado em
 * PROVIDERS. A entrada do modelo traz key e model; a URL e o type vem do
 * catalogo PROVIDERS. A entrada tem a ultima palavra, entao da para
 * sobrescrever a URL pontualmente sem duplicar o provedor.
 */
function resolve_provider(array $entry): ?array
{
    $name = (string) ($entry['provider'] ?? '');
    if ($name !== '') {
        if (!isset(PROVIDERS[$name])) {
            return null; // referencia quebrada
        }
        // PROVIDERS[$name] e ['url' => ..., 'type' => 'openai'|'anthropic'].
        // type default 'openai' quando omitido no catalogo.
        $catalog     = PROVIDERS[$name];
        $entry['url']  = (string) ($catalog['url'] ?? '');
        $entry['type'] = (string) ($catalog['type'] ?? 'openai');
    }
    if (($entry['url'] ?? '') === '' || ($entry['model'] ?? '') === '') {
        return null;
    }
    // type default 'openai' quando o provedor e inline (sem nome em PROVIDERS).
    if (($entry['type'] ?? '') === '') {
        $entry['type'] = 'openai';
    }
    // Rotulo usado em log, header e cooldown. O nome distingue duas contas
    // no mesmo host (openrouter1 x openrouter2), coisa que a url nao faz.
    $entry['label'] = $name !== '' ? $name : (parse_url((string) $entry['url'], PHP_URL_HOST) ?: 'inline');
    return $entry;
}

/**
 * Provedores locais (Ollama, LM Studio, vLLM na mesma maquina) rodam sem
 * chave legitimamente. Usado para nao pular/avisar esses quando a chave
 * esta vazia — so pulamos/avisamos provedores remotos sem chave.
 */
function provider_is_local(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host === '127.0.0.1'
        || $host === '::1'
        || $host === 'localhost'
        || str_ends_with($host, '.localhost');
}

/**
 * Sorteio ponderado sem reposicao: um provedor com weight 9 abre a fila em
 * ~9 de cada 10 requisicoes, mas todos sempre entram na ordem final —
 * o peso muda a frequencia, nunca a disponibilidade. Sem weight, vale 1.
 */
function weighted_shuffle(array $providers): array
{
    $pool    = array_values($providers);
    $ordered = [];
    while ($pool !== []) {
        $total = 0;
        foreach ($pool as $provider) {
            $total += max(1, (int) ($provider['weight'] ?? 1));
        }
        $pick        = random_int(1, $total);
        $accumulated = 0;
        foreach ($pool as $index => $provider) {
            $accumulated += max(1, (int) ($provider['weight'] ?? 1));
            if ($pick <= $accumulated) {
                $ordered[] = $provider;
                unset($pool[$index]);
                $pool = array_values($pool);
                break;
            }
        }
    }
    return $ordered;
}

/** Tentativa sem streaming: resposta completa, converte e devolve. */
function try_provider_buffered(array $provider, array $request, string $model, int $attempt, string $logModel, string $requestId = ''): array
{
    $payload = build_payload($request, $provider);
    $started = microtime(true);
    $result  = call_provider($provider, $payload, null);
    $ms      = (int) round((microtime(true) - $started) * 1000);

    if ($result['status'] === 200) {
        $decoded = json_decode($result['body'], true);
        if (is_array($decoded)) {
            $canonical = $provider['type'] === 'openai'
                ? canonical_from_openai_response($decoded)
                : canonical_from_anthropic_response($decoded);
            log_attempt($logModel, $provider, 200, $ms, 'ok', $requestId);
            breaker_success($provider);
            send_router_headers($provider, $attempt);
            send_json(200, render_openai_response($canonical, $model));
            return ['done' => true, 'status' => 200, 'error' => ''];
        }
        $result['status'] = 502;
        $result['error']  = 'resposta nao e JSON valido';
    }

    $reason = failure_reason($result);
    log_attempt($logModel, $provider, $result['status'], $ms, $reason, $requestId);
    if (transient_failure($result)) {
        cooldown_mark($provider);
        breaker_failure($provider, $result['status'] === 0);
    }
    return ['done' => false, 'status' => $result['status'] ?: 502, 'error' => $reason];
}

/**
 * Tentativa com streaming. Nada e enviado ao cliente antes do HTTP 200 do
 * provedor, entao rate limit e erro continuam rotacionando normalmente.
 * Se o provedor cair no meio do stream, NAO aborta: devolve o parcial para
 * o caller tentar o proximo provedor (que continua de onde parou). So
 * aborta de verdade se foi o CLIENTE que desconectou — nesse caso nao ha
 * sentido em tentar de novo, ninguem esta lendo.
 */
function try_provider_stream(array $provider, array $request, string $model, int $attempt, string $logModel, string $requestId = ''): array
{
    $payload    = build_payload($request, $provider);
    $translator = make_translator(
        $provider['type'],
        $model,
        !empty($request['stream_options']['include_usage'])
    );
    $started    = microtime(true);
    $emitting   = false;

    $result = call_provider($provider, $payload, function (string $chunk) use (&$emitting, $translator, $provider, $attempt): void {
        if (!$emitting) {
            // Headers so na primeira emissao; numa retomada de stream
            // interrompido os headers SSE ja foram enviados pelo provedor
            // anterior e chamar header() de novo so geraria warning.
            if (!headers_sent()) {
                send_router_headers($provider, $attempt);
                send_stream_headers();
            }
            $emitting = true;
        }
        echo $translator->push($chunk);
        flush();
    });

    $ms = (int) round((microtime(true) - $started) * 1000);

    if ($emitting) {
        if ($result['status'] === 200 && $result['error'] === '') {
            // Stream completo: fecha e encerra.
            echo $translator->finish();
            log_attempt($logModel, $provider, 200, $ms, 'ok-stream', $requestId);
            breaker_success($provider);
            flush();
            return ['done' => true, 'status' => 200, 'error' => ''];
        }

        // Stream caiu no meio. Se foi o cliente que saiu, nao ha o que
        // fazer — ninguem vai ler a continuacao. Encerra e fecha o fluxo.
        if (connection_aborted()) {
            log_attempt($logModel, $provider, $result['status'], $ms, 'cliente-desconectou', $requestId);
            flush();
            return ['done' => true, 'status' => 200, 'error' => ''];
        }

        // Provedor caiu mas o cliente continua: NAO fecha o stream. Pega
        // o texto ja emitido e devolve para o caller injetar como
        // continuacao no proximo provedor. O loop em handle_completion
        // cuida de tentar de novo sem duplicar o que ja foi enviado.
        $partial = $translator->emittedText();
        log_attempt($logModel, $provider, $result['status'], $ms, 'stream-interrompido', $requestId);
        if (transient_failure($result)) {
            cooldown_mark($provider);
            breaker_failure($provider, $result['status'] === 0);
        }
        // done=false mantem o loop vivo; partial alimenta a retomada.
        // Nao chamamos flush() nem finish()/abort() aqui: o stream SSE
        // segue aberto para o proximo provedor continuar escrevendo.
        return [
            'done'    => false,
            'status'  => $result['status'] ?: 502,
            'error'   => 'stream-interrompido',
            'partial' => $partial,
        ];
    }

    $reason = failure_reason($result);
    log_attempt($logModel, $provider, $result['status'], $ms, $reason, $requestId);
    if (transient_failure($result)) {
        cooldown_mark($provider);
        breaker_failure($provider, $result['status'] === 0);
    }
    return ['done' => false, 'status' => $result['status'] ?: 502, 'error' => $reason];
}

// =====================================================================
// ESTADO OPCIONAL (COOLDOWN E RATE LIMIT)
// =====================================================================

// Um unico arquivo JSON com flock guarda os dois. Qualquer falha de disco
// desliga a funcionalidade em silencio: estado aqui e otimizacao, nunca
// condicao para responder.

/** Leitura rapida sem lock; JSON truncado durante escrita vira estado vazio. */
function state_read(): array
{
    if (!is_file(STATE_FILE)) {
        return [];
    }
    $raw = @file_get_contents(STATE_FILE);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $state = json_decode($raw, true);
    return is_array($state) ? $state : [];
}

/** Read-modify-write atomico com flock exclusivo. */
function state_update(callable $mutator): array
{
    $dir = dirname(STATE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        // A pasta pode estar dentro do docroot: nega acesso web por dentro tambem.
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }

    $handle = @fopen(STATE_FILE, 'c+');
    if ($handle === false) {
        return [];
    }
    // Sem lock exclusivo (ex.: NFS sem flock) nao da para escrever com
    // seguranca: aborta e deixa a funcionalidade desligada em silencio,
    // como prometido no contrato do estado.
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return [];
    }
    $raw   = stream_get_contents($handle);
    $state = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    $state = $mutator($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
}

/** Identidade estavel do provedor no estado, sem expor url nem chave. */
function provider_key(array $provider): string
{
    return substr(md5($provider['label'] . '|' . $provider['model']), 0, 12);
}

function cooldown_active(array $provider, array $state): bool
{
    return ((int) ($state['cooldown'][provider_key($provider)] ?? 0)) > time();
}

function cooldown_mark(array $provider): void
{
    if (COOLDOWN_SECONDS <= 0) {
        return;
    }
    state_update(static function (array $state) use ($provider): array {
        $now = time();
        $state['cooldown'][provider_key($provider)] = $now + COOLDOWN_SECONDS;
        // Limpa expirados para o arquivo nao crescer para sempre.
        foreach ($state['cooldown'] as $key => $until) {
            if ($until <= $now) {
                unset($state['cooldown'][$key]);
            }
        }
        return $state;
    });
}

// ---------------------------------------------------------------------
// CIRCUIT BREAKER (opcional, alem do cooldown)
// Estado por provedor em state.json:
//   breaker[label] = { failures: int, opened_until: int, last_probe: int }
// Estados: CLOSED (normal) -> OPEN (N falhas consecutivas, fora da rotacao)
// -> HALF_OPEN (probe periodico: 1 req de teste) -> CLOSED se sucesso.
// ---------------------------------------------------------------------

/** Provedor aberto e fora da janela de probe? Nao entra na rotacao. */
function breaker_is_open(array $provider, array $state): bool
{
    if (BREAKER_FAILURES <= 0) {
        return false;
    }
    $entry = $state['breaker'][provider_key($provider)] ?? null;
    if ($entry === null) {
        return false;
    }
    $openedUntil = (int) ($entry['opened_until'] ?? 0);
    $lastProbe   = (int) ($entry['last_probe'] ?? 0);
    $now         = time();

    if ($openedUntil > $now) {
        // Circuito aberto: so permite req de probe no intervalo configurado.
        return ($now - $lastProbe) < BREAKER_PROBE_SECONDS;
    }
    // Half-open (janela expirou). Se ja houve um probe recente (last_probe
    // apos opened_until) que falhou sem reabrir, espera o intervalo antes
    // de permitir outro — sem isto, provedores em half-open seriam
    // afogados em probes em sequencia sem esperar.
    if ($lastProbe > $openedUntil && ($now - $lastProbe) < BREAKER_PROBE_SECONDS) {
        return true;
    }
    return false;
}

/** Marca sucesso: fecha o circuito e zera falhas. */
function breaker_success(array $provider): void
{
    if (BREAKER_FAILURES <= 0) {
        return;
    }
    state_update(static function (array $state) use ($provider): array {
        unset($state['breaker'][provider_key($provider)]);
        return $state;
    });
}

/** Marca falha: acumula (rede pura conta como 0.5) e abre se passar do teto. */
function breaker_failure(array $provider, bool $networkOnly): void
{
    if (BREAKER_FAILURES <= 0) {
        return;
    }
    state_update(static function (array $state) use ($provider, $networkOnly): array {
        $key = provider_key($provider);
        $entry = $state['breaker'][$key] ?? ['failures' => 0, 'opened_until' => 0, 'last_probe' => 0];
        $entry['failures'] = ($entry['failures'] ?? 0) + ($networkOnly ? 0.5 : 1.0);
        $entry['last_probe'] = time();
        if ($entry['failures'] >= BREAKER_FAILURES) {
            $entry['opened_until'] = time() + BREAKER_OPEN_SECONDS;
            $entry['failures'] = 0;
        }
        $state['breaker'][$key] = $entry;
        return $state;
    });
}

/** Janela fixa de um minuto, contador global. Retorna segundos restantes
 *  na janela quando estourado (>=1), ou 0 quando liberado. SDKs que respeitam
 *  o header Retry-After conseguem esperar o tempo certo automaticamente. */
function rate_limited_seconds(): int
{
    if (RATE_LIMIT_PER_MINUTE <= 0) {
        return 0;
    }
    $window = (int) floor(time() / 60);

    // Leitura rapida sem lock: se ja estourou, devolve 429 sem tocar no
    // lock. Se ainda ha folga, so entao adquire lock para incrementar —
    // evita serializar todas as requisicoes num lock global de disco.
    $snapshot = state_read();
    if ((int) ($snapshot['rate']['window'] ?? 0) === $window && (int) ($snapshot['rate']['count'] ?? 0) >= RATE_LIMIT_PER_MINUTE) {
        return rate_window_remaining();
    }

    $count = 0;
    state_update(static function (array $state) use ($window, &$count): array {
        if ((int) ($state['rate']['window'] ?? 0) !== $window) {
            $state['rate'] = ['window' => $window, 'count' => 0];
        }
        $state['rate']['count']++;
        $count = $state['rate']['count'];
        return $state;
    });
    return $count > RATE_LIMIT_PER_MINUTE ? rate_window_remaining() : 0;
}

/** Segundos ate o fim da janela de 1 minuto atual. */
function rate_window_remaining(): int
{
    $remaining = 60 - (time() % 60);
    return $remaining > 0 ? $remaining : 1;
}

// =====================================================================
// SAIDA, LOG E UTILITARIOS
// =====================================================================

function send_json(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Erro no formato OpenAI — sem expor host, chave ou caminho interno. */
function send_error(int $status, string $type, string $message): void
{
    send_json($status, ['error' => ['message' => $message, 'type' => $type, 'code' => $status]]);
}

/** Diz qual provedor atendeu e em que tentativa. Nao expoe a chave. */
function send_router_headers(array $provider, int $attempt): void
{
    if (!EXPOSE_PROVIDER_HEADER) {
        return;
    }
    header('X-Router-Provider: ' . $provider['label']);
    header('X-Router-Model: ' . $provider['model']);
    header('X-Router-Attempt: ' . $attempt);
}

/** Uma linha por tentativa. Registra host do provedor, nunca a chave. */
function log_attempt(string $model, array $provider, int $status, int $ms, string $outcome, string $request_id = ''): void
{
    if (LOG_FILE === '') {
        return;
    }
    // Rotaciona antes de crescer demais: o gateway costuma rodar em host
    // compartilhado. Mantem duas janelas de historico (.1 e .2).
    if (LOG_MAX_BYTES > 0 && @filesize(LOG_FILE) > LOG_MAX_BYTES) {
        if (is_file(LOG_FILE . '.1')) {
            @rename(LOG_FILE . '.1', LOG_FILE . '.2');
        }
        @rename(LOG_FILE, LOG_FILE . '.1');
    }

    $line = sprintf(
        "%s\t%s\t%s\t%s\t%d\t%dms\t%s\t%s\n",
        date('c'),
        $model,
        $provider['label'],
        $provider['model'],
        $status,
        $ms,
        $outcome,
        $request_id
    );
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);

    // Metricas agregadas (file ou sqlite). Falha silenciosa.
    metrics_record($model, $provider, $status, $ms, $outcome, $request_id);
}

function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(12));
}

function server_header(string $key): string
{
    return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
}

/**
 * Probe ativo: faz uma chamada leve (HEALTH_PROBE_TOKENS) a cada provedor
 * marcado 'probe'=>true em PROVIDERS, respeitando HEALTH_PROBE_INTERVAL
 * via state.json (nao re-probe dentro da janela). Retorna um mapa
 * label => [status, ms, error]. Provedores sem chave sao pulados (o probe
 * so faz sentido para quem tem credenciais validas). A chamada usa o
 * pool de curl e o mesmo call_provider do fluxo normal, entao o que o
 * probe ve e o que o gateway ve de verdade.
 */
function health_probe(): array
{
    $state = state_read();
    $now   = time();
    $last  = (int) ($state['health_probe_ts'] ?? 0);
    if ($now - $last < HEALTH_PROBE_INTERVAL) {
        return ['skipped' => true, 'reason' => 'interval', 'next_in' => HEALTH_PROBE_INTERVAL - ($now - $last)];
    }
    state_update(static function (array $s) use ($now): array {
        $s['health_probe_ts'] = $now;
        return $s;
    });

    $results = [];
    foreach (PROVIDERS as $name => $catalog) {
        $probe = $catalog['probe'] ?? false;
        if (!$probe) {
            continue;
        }
        // Monta um provider sintetico so para o probe: sem chave real se
        // nao houver (o probe de provedores sem credencial so falharia).
        $provider = [
            'url'    => (string) ($catalog['url'] ?? ''),
            'type'   => (string) ($catalog['type'] ?? 'openai'),
            'key'    => '',
            'model'  => '',
            'label'  => $name,
        ];
        // Procura a chave em MODELS: primeira entrada que referencia este
        // provedor fornece a chave (e o model) para o probe.
        foreach (MODELS as $config) {
            $entries = is_array($config['providers'] ?? null) ? $config['providers'] : $config;
            foreach ($entries as $entry) {
                if (($entry['provider'] ?? '') === $name) {
                    $provider['key']   = (string) ($entry['key'] ?? '');
                    $provider['model'] = (string) ($entry['model'] ?? '');
                    break 2;
                }
            }
        }
        if ($provider['key'] === '' || $provider['model'] === '') {
            $results[$name] = ['status' => 0, 'ms' => 0, 'error' => 'sem chave/modelo configurado'];
            continue;
        }

        $probeBody = [
            'model'       => $provider['model'],
            'messages'     => [['role' => 'user', 'content' => 'ping']],
            'max_tokens'   => HEALTH_PROBE_TOKENS,
            'stream'       => false,
        ];
        $probeRequest = normalize_openai_request($probeBody);
        $payload      = build_payload($probeRequest, $provider);

        $started = microtime(true);
        $result  = call_provider($provider, $payload, null);
        $ms      = (int) round((microtime(true) - $started) * 1000);
        $results[$name] = [
            'status' => $result['status'],
            'ms'     => $ms,
            'error'  => $result['error'] !== '' ? $result['error'] : ($result['status'] === 200 ? '' : upstream_message($result['body'])),
        ];
    }
    return $results;
}

// Os utilitarios de linha de comando (cli_entry, cli_check, check_params)
// vivem em src/cli.php, carregado apenas quando PHP_SAPI === 'cli' no
// index.php. As funcoes puras abaixo continuam aqui porque a web tambem
// as usa: placeholder_key() (guard_configuration) e resolve_provider()
// (fluxo principal).
