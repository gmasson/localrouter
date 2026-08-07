<?php
declare(strict_types=1);

/**
 * LocalRouter — núcleo do gateway
 *
 * Fluxo de uma requisição: guards -> autenticação -> normalização ->
 * fila de provedores -> resposta. Também: estado opcional (cooldown,
 * circuit breaker e rate limit), log e saída JSON/erro.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

// =====================================================================
// ENTRADA E GUARDS
// =====================================================================

function main(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive'); // vale mesmo sem .htaccess

    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Health fica antes dos guards para o monitor local nunca ser barrado.
    // Sem autenticação de propósito: a resposta não carrega nada sensível.
    if ($method === 'GET' && str_ends_with($path, '/health')) {
        $ready = !gateway_has_placeholder_key();
        send_json($ready ? 200 : 503, ['status' => $ready ? 'ok' : 'unconfigured']);
        return;
    }

    guard_access();

    if (ALLOW_ORIGIN !== '') {
        header('Access-Control-Allow-Origin: ' . ALLOW_ORIGIN);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: authorization, x-api-key, content-type, anthropic-version');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 600');
        // Sem expose, o navegador esconde os X-Router-* do JS que chamou —
        // e são justamente eles que dizem qual provedor atendeu.
        header('Access-Control-Expose-Headers: X-Router-Provider, X-Router-Model, X-Router-Attempt, X-Request-Id');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }
    }

    guard_configuration();

    // Health detalhado por provedor: requer autenticação (expõe latência e
    // taxa de erro, que são dados operacionais, não secretos, mas não são
    // públicos). Junta as métricas da janela com o consumo diário de quem
    // declarou 'rpd' — que é o número que decide se o provedor ainda está
    // na fila hoje.
    if ($method === 'GET' && str_ends_with($path, '/health/providers')) {
        authenticate();
        $snapshot = quota_annotate(metrics_snapshot());
        $snapshot === []
            ? send_json(503, ['status' => 'metrics_off', 'providers' => []])
            : send_json(200, ['status' => 'ok', 'providers' => $snapshot]);
        return;
    }

    // Métricas agregadas: contagem por status, p50/p95, taxa de erro.
    // Autenticado como /chat/completions. Formato json ou prometheus.
    if ($method === 'GET' && str_ends_with($path, '/metrics')) {
        if (!METRICS_EXPOSE) {
            send_error(404, 'not_found', 'Metricas desligadas (METRICS_EXPOSE).');
            return;
        }
        authenticate();
        http_response_code(200);
        header('Content-Type: ' . (METRICS_FORMAT === 'prometheus'
            ? 'text/plain; version=0.0.4; charset=utf-8'
            : 'application/json; charset=utf-8'));
        echo metrics_render(metrics_snapshot(), METRICS_FORMAT);
        return;
    }

    // Casa pelo sufixo: funciona em /chat/completions (com rewrite),
    // em /index.php/chat/completions (sem rewrite) e em qualquer subpasta.
    // A API exposta é exclusivamente no formato OpenAI; a tradução para
    // provedores Anthropic acontece por dentro.
    if (str_ends_with($path, '/chat/completions')) {
        if ($method !== 'POST') {
            send_error(405, 'invalid_request_error', 'Use POST neste endpoint.');
            return;
        }
        $bucket = authenticate();
        deadline_start();
        handle_completion(read_json_body(), $bucket);
        return;
    }
    if (str_ends_with($path, '/embeddings')) {
        if ($method !== 'POST') {
            send_error(405, 'invalid_request_error', 'Use POST neste endpoint.');
            return;
        }
        $bucket = authenticate();
        deadline_start();
        handle_embedding(read_json_body(), $bucket);
        return;
    }
    if ($method === 'GET' && str_ends_with($path, '/models')) {
        authenticate();
        send_json(200, ['object' => 'list', 'data' => list_models()]);
        return;
    }
    send_error(404, 'not_found', 'Rota desconhecida. A API e no formato OpenAI: use /chat/completions ou /models.');
}

/**
 * Fecha o gateway para quem não apresenta uma chave válida e devolve o
 * identificador do balde de rate limit daquela chave — um hash curto, para
 * o state.json nunca guardar a chave em si.
 */
function authenticate(): string
{
    $auth = server_header('HTTP_AUTHORIZATION') ?: server_header('REDIRECT_HTTP_AUTHORIZATION');
    $sent = $auth !== '' && stripos($auth, 'bearer ') === 0
        ? trim(substr($auth, 7))
        : trim(server_header('HTTP_X_API_KEY'));

    if ($sent !== '') {
        foreach (GATEWAY_KEYS as $valid) {
            if (hash_equals((string) $valid, $sent)) {
                return substr(md5($sent), 0, 8);
            }
        }
    }
    send_error(401, 'authentication_error', 'Chave de API invalida ou ausente.');
    exit;
}

function read_json_body(): array
{
    if (INPUT_VALIDATE_CONTENT_TYPE && INPUT_ALLOWED_CONTENT_TYPES !== []) {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if (!in_array($contentType, INPUT_ALLOWED_CONTENT_TYPES, true)) {
            send_error(415, 'invalid_request_error', 'Content-Type nao aceito.');
            exit;
        }
    }
    // Rejeita cedo pelo tamanho declarado e nunca lê além do teto:
    // um corpo gigante não pode consumir memória antes do 413.
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
    // Profundidade limitada: aninhamento absurdo é barato de enviar e caro
    // de percorrer. 64 níveis cobrem qualquer JSON Schema realista.
    $body = json_decode($raw, true, 64);
    if (!is_array($body)) {
        send_error(400, 'invalid_request_error', 'JSON invalido.');
        exit;
    }
    return $body;
}

function list_models(): array
{
    $created = time();
    $out     = [];
    // 'owned_by' é o único campo livre do formato OpenAI: usamos para dizer
    // qual o type do modelo (em qual endpoint ele atende), sem inventar campo.
    // MODELS é a única tabela; o type de cada entrada decide o agrupamento.
    foreach (MODELS as $name => $config) {
        $out[] = ['id' => $name, 'object' => 'model', 'created' => $created, 'owned_by' => 'localrouter/' . model_type($config)];
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
    // Recusa em vez de redirecionar: se a chave já chegou por HTTP, ela já
    // trafegou exposta — um redirect só faria o cliente reenviá-la.
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
    // Atrás de proxy TLS (Cloudflare etc.) o PHP só vê HTTP; o proxy avisa
    // via X-Forwarded-Proto. Mas qualquer cliente pode forjar esse cabeçalho,
    // então só confiamos nele quando a requisição veio de um proxy listado
    // em TRUSTED_PROXIES (mesma lógica de ALLOWED_IPS: IP exato ou prefixo).
    if (TRUSTED_PROXIES !== [] && ip_allowed($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_PROXIES)) {
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    return false;
}

function request_is_local(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** Chave de fábrica que o gateway se recusa a aceitar em produção. */
function placeholder_key(): string
{
    return 'sk-lr-troque-esta-chave';
}

/**
 * Recusa servir com a configuração de fábrica. Um gateway publicado com a
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

/** Junta todo o texto da requisição (system + mensagens) para validações. */
function request_text(array $request): string
{
    $parts = [];
    if (($request['system'] ?? '') !== '') {
        $parts[] = $request['system'];
    }
    foreach ($request['messages'] ?? [] as $message) {
        foreach ($message['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text' && ($block['text'] ?? '') !== '') {
                $parts[] = $block['text'];
            }
        }
    }
    return implode("\n", $parts);
}

/** Retorna o primeiro termo de BLOCKED_TERMS encontrado em $text, ou null. */
function contains_blocked_term(string $text): ?string
{
    if (BLOCKED_TERMS === []) {
        return null;
    }
    $haystack = BLOCKED_TERMS_CASE_INSENSITIVE ? mb_strtolower($text) : $text;
    foreach (BLOCKED_TERMS as $term) {
        $term = (string) $term;
        if ($term === '') {
            continue;
        }
        $needle = BLOCKED_TERMS_CASE_INSENSITIVE ? mb_strtolower($term) : $term;
        $found = match (BLOCKED_TERMS_MATCH_MODE) {
            'exact'    => $haystack === $needle,
            'regex'    => @preg_match($term, $text) === 1,
            default    => str_contains($haystack, $needle), // 'contains'
        };
        if ($found) {
            return $term;
        }
    }
    return null;
}

// =====================================================================
// FLUXO PRINCIPAL: NORMALIZA -> ROTACIONA -> RESPONDE
// =====================================================================

function handle_completion(array $body, string $bucket): void
{
    // Request ID: correlaciona todas as tentativas (provedores, retries,
    // fallbacks) de uma única chamada do cliente. Devolvido no header
    // X-Request-Id e gravado em log/métricas — essencial para depurar
    // "falhou em algum provedor" sem adivinhar qual.
    $requestId = request_id();
    if (REQUEST_ID_HEADER) {
        header('X-Request-Id: ' . $requestId);
    }

    $model = (string) ($body['model'] ?? '');
    if (INPUT_BLOCK_CLIENT_MODEL_OVERRIDE) {
        $chatModels = models_of_type('chat');
        $model = $chatModels !== [] ? (string) array_key_first($chatModels) : '';
    }
    // /chat/completions só atende modelos de tipo 'chat'. Um modelo marcado
    // 'embedding' (ou outro tipo) aqui devolve 404 — o app pediu no endpoint
    // errado. MODELS é a única tabela; o filtro por type é o que separa.
    if ($model === '' || !isset(MODELS[$model]) || model_type(MODELS[$model]) !== 'chat') {
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

    if (INPUT_REJECT_EMPTY_MESSAGE && request_text($request) === '') {
        send_error(400, 'invalid_request_error', 'Mensagem vazia.');
        return;
    }
    if (INPUT_MAX_CHARS !== null && mb_strlen(request_text($request)) > INPUT_MAX_CHARS) {
        send_error(400, 'invalid_request_error', 'Entrada acima do limite de caracteres.');
        return;
    }
    if (INPUT_USE_BLOCKED_TERMS && ($term = contains_blocked_term(request_text($request))) !== null) {
        send_error(400, 'invalid_request_error', 'Entrada contem termo nao permitido.');
        return;
    }

    if (($retryAfter = rate_limited_seconds($bucket)) > 0) {
        // SDKs que respeitam Retry-After esperam o tempo certo sozinhos.
        header('Retry-After: ' . $retryAfter);
        send_error(429, 'rate_limit_error', 'Limite de requisicoes do gateway atingido. Aguarde ' . $retryAfter . 's.');
        return;
    }

    // Id único do stream para a requisição inteira: numa retomada em outro
    // provedor o cliente continua vendo um único stream coerente.
    $streamId   = new_id('chatcmpl');
    $candidates = collect_candidates($model, MODELS, MODEL_FALLBACKS);

    $lastStatus = 503;
    $lastError  = 'Nenhum provedor configurado para este modelo.';
    $attempt    = 0;
    $partial    = '';    // texto já enviado ao cliente em stream interrompido
    $sseOpen    = false; // já emitimos cabeçalhos e eventos SSE?

    foreach ($candidates as $candidate) {
        $attempt++;
        // Precedência: params do modelo > params do provedor > requisição.
        // O admin que configura o modelo sabe o que o provedor exige; o app
        // não. Por isso a configuração sobrescreve o que o app enviou.
        $effective = apply_params($request, $candidate['provider']['params'] ?? []);
        $effective = apply_params($effective, $candidate['params']);

        // system_prompt do modelo: injeta ANTES das mensagens do app.
        $effective = apply_system_prompt($effective, $candidate['system_prompt']);

        // Retomada de stream interrompido: se já enviamos parte da resposta
        // ao cliente, pedimos ao próximo provedor que CONTINUE a partir de
        // onde parou, em vez de recomeçar do zero (o que duplicaria o texto).
        if ($partial !== '') {
            $effective = inject_continuation($effective, $partial);
        }

        $result = try_provider($candidate['provider'], $effective, $model, $attempt, $candidate['model'], $requestId, $streamId, $partial);

        // Retry do mesmo provedor para falha de rede pura (timeout, DNS,
        // conexão recusada — nenhuma resposta HTTP chegou). Esses erros
        // costumam ser transitórios e baratos de repetir; rotacionar pagaria
        // a mesma latência. Não vale depois que o stream já emitiu bytes:
        // nesse caso a retomada acontece no próximo provedor.
        for ($extra = 0;
             $extra < RETRY_SAME_PROVIDER && !$result['done'] && $result['network'] && !$sseOpen && $partial === '';
             $extra++) {
            $result = try_provider($candidate['provider'], $effective, $model, $attempt, $candidate['model'], $requestId, $streamId, $partial);
        }

        $sseOpen = $sseOpen || $result['opened'];
        if ($result['done']) {
            return; // resposta ja entregue ao cliente
        }
        $lastStatus = $result['status'];
        $lastError  = $result['error'];

        // Stream caiu depois de já ter emitido um tool_call: retomar seria
        // pior que parar. O cliente recebeu um "arguments" JSON pela metade
        // e um segundo provedor abriria outra chamada por cima, deixando
        // duas ferramentas quebradas no lugar de uma resposta.
        if ($result['toolCall']) {
            $lastError = 'stream interrompido no meio de uma chamada de ferramenta';
            break;
        }

        // Stream de texto caiu no meio: guarda o parcial para o próximo
        // provedor continuar e mantém o loop vivo.
        $partial .= $result['partial'];

        // Orçamento de tempo estourado: parar aqui é melhor que abrir mais
        // uma tentativa que já nasceria sem tempo de terminar.
        if (deadline_exceeded()) {
            $lastError = 'tempo limite da requisicao (' . TOTAL_DEADLINE_SECONDS . 's) esgotado. Ultimo erro: ' . $lastError;
            break;
        }
    }

    // Lista esgotada. Com o SSE já aberto não cabe um JSON de erro por cima
    // do stream: manda o erro como evento e fecha. Sem isso o cliente ficaria
    // esperando um terminador que nunca vem, ou leria JSON no meio do SSE.
    $message = 'Todos os provedores falharam. Ultimo erro: ' . $lastError;
    if ($sseOpen) {
        echo sse(['error' => ['message' => $message, 'type' => 'upstream_error', 'code' => $lastStatus]]);
        echo "data: [DONE]\n\n";
        flush();
        return;
    }
    send_final_error($lastStatus, $message, $attempt);
}

/**
 * Erro depois de esgotar a fila. Status 400 vira invalid_request_error
 * porque a causa está no que o cliente mandou, não nos provedores — quem
 * lê o erro precisa saber onde procurar. X-Router-Attempts diz quantos
 * provedores foram tentados antes de desistir.
 */
function send_final_error(int $lastStatus, string $message, int $attempts): void
{
    if (!headers_sent()) {
        header('X-Router-Attempts: ' . $attempts);
    }
    $status = $lastStatus >= 400 && $lastStatus < 600 ? $lastStatus : 502;
    send_error($status, $status === 400 ? 'invalid_request_error' : 'upstream_error', $message);
}

/**
 * POST /embeddings — mesma rotação de provedores do chat, corpo bem mais
 * simples. Não há forma canônica nem streaming: o payload de embeddings é
 * igual em todo provedor de dialeto openai, então normalizamos a entrada,
 * trocamos o id do modelo e repassamos a resposta com o nome do gateway.
 */
function handle_embedding(array $body, string $bucket): void
{
    // /embeddings só atende modelos de tipo 'embedding'. O catálogo é o
    // mesmo MODELS; o filtro por type é o que separa os endpoints.
    $embeddings = models_of_type('embedding');
    if ($embeddings === []) {
        send_error(404, 'not_found', 'Nenhum modelo de embedding configurado (type=embedding em MODELS).');
        return;
    }

    $requestId = request_id();
    if (REQUEST_ID_HEADER) {
        header('X-Request-Id: ' . $requestId);
    }

    $model = (string) ($body['model'] ?? '');
    if ($model === '' || !isset($embeddings[$model])) {
        send_error(404, 'invalid_request_error', 'Modelo de embedding nao configurado neste gateway.');
        return;
    }
    if (!embedding_input_is_valid($body['input'] ?? null)) {
        send_error(400, 'invalid_request_error', 'Campo "input" ausente ou vazio.');
        return;
    }
    if (($retryAfter = rate_limited_seconds($bucket)) > 0) {
        header('Retry-After: ' . $retryAfter);
        send_error(429, 'rate_limit_error', 'Limite de requisicoes do gateway atingido. Aguarde ' . $retryAfter . 's.');
        return;
    }

    $lastStatus = 503;
    $lastError  = 'Nenhum provedor configurado para este modelo.';
    $attempt    = 0;

    foreach (collect_candidates($model, $embeddings, []) as $candidate) {
        $attempt++;
        $provider = $candidate['provider'];
        if ($provider['type'] === 'anthropic') {
            continue; // a API Anthropic não tem endpoint de embeddings
        }
        // Sobrescreve a rota: o mesmo call_provider serve os dois endpoints.
        $provider['endpoint'] = 'embeddings';

        $started = microtime(true);
        $result  = call_provider($provider, build_embedding_payload($body, $provider['model']), null);
        $ms      = (int) round((microtime(true) - $started) * 1000);

        if ($result['status'] === 200) {
            $decoded = json_decode($result['body'], true);
            if (embedding_response_is_usable($decoded)) {
                $decoded['model'] = $model; // o cliente pediu o nome do gateway
                log_attempt($candidate['model'], $provider, 200, $ms, 'ok-embedding', $requestId, [
                    'input'  => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                    'output' => 0,
                ]);
                breaker_success($provider);
                send_router_headers($provider, $attempt);
                send_json(200, $decoded);
                return;
            }
            // Mesmo critério do chat: 200 sem vetor é falha, não resposta.
            $result['status'] = 502;
        }

        $lastError  = failure_reason($result);
        $lastStatus = $result['status'] ?: 502;
        log_attempt($candidate['model'], $provider, $result['status'], $ms, $lastError, $requestId);
        mark_failure($provider, $result);

        if (deadline_exceeded()) {
            $lastError = 'tempo limite da requisicao esgotado. Ultimo erro: ' . $lastError;
            break;
        }
    }

    send_final_error($lastStatus, 'Todos os provedores falharam. Ultimo erro: ' . $lastError, $attempt);
}

/** Despacha para o caminho com ou sem streaming. */
function try_provider(array $provider, array $request, string $model, int $attempt, string $logModel, string $requestId, string $streamId, string $partial = ''): array
{
    return $request['stream']
        ? try_provider_stream($provider, $request, $model, $attempt, $logModel, $requestId, $streamId, $partial)
        : try_provider_buffered($provider, $request, $model, $attempt, $logModel, $requestId);
}

/**
 * Acrescenta o texto já enviado ao cliente como mensagem assistant e pede a
 * continuação, para o próximo provedor retomar de onde o anterior parou.
 *
 * $request já carrega o histórico inteiro; só o parcial entra no fim. A
 * instrução vai no system, não como turno de usuário, para não confundir a
 * alternância de papéis. tool_choice vira 'none': as tools seguem no payload
 * como contexto, mas o provedor não pode abrir uma chamada no meio da emenda.
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

    $request['tool_choice'] = 'none';

    return $request;
}

/** Um modelo aceita a lista simples de provedores ou ['params'=>..., 'providers'=>...]. */
function model_entries(array $config): array
{
    $entries = is_array($config['providers'] ?? null) ? $config['providers'] : $config;
    return array_values(array_filter($entries, 'is_array'));
}

/**
 * Tipo de um modelo (chat, embedding, e futuros). Distinto do dialeto do
 * provedor (openai/anthropic), que vive em PROVIDERS. Ausente = 'chat'.
 * Válido em runtime: qualquer valor não reconhecido cai em 'chat' para não
 * quebrar configurações antigas — php index.php check aponta o typo.
 */
function model_type(array $config): string
{
    $type = (string) ($config['type'] ?? 'chat');
    return in_array($type, MODEL_TYPES, true) ? $type : 'chat';
}

/**
 * Subconjunto de MODELS cujo 'type' casa o pedido. Usado por /chat e
 * /embeddings para rotear só os modelos do tipo certo, e por /models para
 * listar com owned_by distinto por tipo.
 */
function models_of_type(string $type): array
{
    $out = [];
    foreach (MODELS as $name => $config) {
        if (model_type($config) === $type) {
            $out[$name] = $config;
        }
    }
    return $out;
}

/**
 * Monta a fila de tentativas: provedores do modelo pedido, depois os do
 * fallback (se houver), pulando quem está de castigo, até MAX_ATTEMPTS.
 *
 * O catálogo é parâmetro porque /embeddings usa a mesma rotação com o
 * subconjunto de MODELS cujo type é 'embedding' e sem fallback entre modelos.
 */
function collect_candidates(string $model, array $catalog, array $fallbacks): array
{
    $candidates = [];
    $queue      = [$model];
    $visited    = [];

    while ($queue !== []) {
        $current = array_shift($queue);
        if (isset($visited[$current]) || !isset($catalog[$current])) {
            continue; // protege contra ciclo A->B->A no MODEL_FALLBACKS
        }
        $visited[$current] = true;

        $config       = $catalog[$current];
        $modelParams  = is_array($config['params'] ?? null) ? $config['params'] : [];
        $systemPrompt = is_string($config['system_prompt'] ?? null) ? trim($config['system_prompt']) : '';

        // Resolve cada entrada contra PROVIDERS antes de ordenar; entrada
        // com referência quebrada é ignorada (php index.php check aponta).
        // Uma entrada com 'key' em array vira N candidatos idênticos, cada
        // um com sua chave — é como cadastrar várias contas do mesmo
        // serviço sem repetir a entrada. Cada expansão ganha sufixo '#N'.
        $resolved = [];
        foreach (model_entries($config) as $entry) {
            foreach (resolve_provider($entry) as $provider) {
                $resolved[] = $provider;
            }
        }

        // Pula provedores remotos sem chave em runtime: a chamada iria falhar
        // no 401 e gastar uma tentativa à toa. Provedores locais (Ollama)
        // rodam sem chave legitimamente e seguem na fila.
        if (SKIP_EMPTY_REMOTE_KEY) {
            $resolved = array_values(array_filter(
                $resolved,
                static fn (array $p): bool => $p['key'] !== '' || provider_is_local($p['url'])
            ));
        }

        // Ordem DENTRO do modelo; o fallback entre modelos vem sempre depois.
        //   priority -> ordem do array
        //   random   -> sorteio ponderado pelo weight
        //   fastest  -> menor latência medida na janela de métricas
        $ordered = match (STRATEGY) {
            'random'  => weighted_shuffle($resolved),
            'fastest' => fastest_first($resolved),
            default   => $resolved,
        };
        foreach ($ordered as $provider) {
            $candidates[] = [
                'model'         => $current,
                'provider'      => $provider,
                'params'        => $modelParams,
                'system_prompt' => $systemPrompt,
            ];
        }

        $fallback = $fallbacks[$current] ?? null;
        if (is_string($fallback) && $fallback !== '') {
            $queue[] = $fallback;
        }
    }

    // Castigo, circuito aberto e cota diária estourada tiram o provedor da
    // fila. Se TODOS caírem, ignora o filtro: tentar é melhor que falhar
    // parado — o estado é uma estimativa nossa, não a verdade do provedor.
    $state = state_read();
    $alive = array_values(array_filter(
        $candidates,
        static fn (array $c): bool => !cooldown_active($c['provider'], $state)
            && !breaker_is_open($c['provider'], $state)
            && !quota_exhausted($c['provider'], $state)
    ));
    if ($alive !== []) {
        $candidates = $alive;
    }

    // MAX_ATTEMPTS é aplicado POR MODELO, não sobre a fila inteira: assim o
    // fallback entre modelos (MODEL_FALLBACKS) continua valendo mesmo quando
    // o modelo principal tem provedores suficientes para esgotar o teto sozinho.
    if (MAX_ATTEMPTS > 0) {
        $perModel = [];
        foreach ($candidates as $candidate) {
            $perModel[$candidate['model']][] = $candidate;
        }
        $candidates = array_merge(...array_map(
            static fn (array $group): array => array_slice($group, 0, MAX_ATTEMPTS),
            array_values($perModel)
        ) ?: [[]]);
    }
    return $candidates;
}

/**
 * Injeta o system_prompt do modelo ANTES do system que o app enviou.
 * Precedência do app é mantida: o conteúdo que o app passou vem depois
 * (mais forte na prática, pois modelos dão peso à ordem do prompt).
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
 * Aplica parâmetros de configuração (provedor ou modelo) sobre a requisição.
 * SEMPRE sobrescreve o que o app enviou: o admin que configura o modelo
 * sabe o que o provedor exige; o app não. Para deixar o provedor decidir,
 * basta não definir o parâmetro (null = "sem opinião").
 */
function apply_params(array $request, array $params): array
{
    foreach ($params as $name => $value) {
        if ($value === null || $value === []) {
            continue; // "sem opinião": não envia, provedor aplica o padrão dele
        }
        switch ($name) {
            case 'temperature':
            case 'top_p':
                $request['params'][$name] = num_or_null($value);
                break;
            case 'top_k':
            case 'max_tokens':
                $request['params'][$name] = int_or_null($value);
                break;
            case 'stop':
            case 'stop_sequences':
                $request['params']['stop'] = stop_list($value);
                break;
            default:
                // Demais parâmetros seguem para 'extra', injetado no payload
                // dos provedores openai. Diferente do passthrough da
                // REQUISIÇÃO DO CLIENTE — filtrado por PASSTHROUGH_OPENAI por
                // segurança — o que o admin define no config.php aceita
                // qualquer nome: é o que permite campos próprios de provedores
                // exóticos (Modal, vLLM, LM Studio).
                $request['extra'][$name] = $value;
                break;
        }
    }
    return $request;
}

/**
 * Junta a entrada do modelo com a URL e o tipo do provedor nomeado em
 * PROVIDERS. A entrada do modelo traz o id do modelo naquele provedor e,
 * opcionalmente, uma chave própria; url e type vêm sempre do catálogo.
 * Devolve uma LISTA porque 'key' em array expande em N candidatos.
 */
function resolve_provider(array $entry): array
{
    $name = (string) ($entry['provider'] ?? '');
    if ($name !== '') {
        if (!isset(PROVIDERS[$name])) {
            return []; // referencia quebrada
        }
        // A key do provedor é o PADRÃO; a entrada de MODELS pode sobrescrever
        // pontualmente (conta diferente do mesmo serviço).
        $catalog       = PROVIDERS[$name];
        $entry['url']  = trim((string) ($catalog['url'] ?? ''));
        $entry['type'] = (string) ($catalog['type'] ?? 'openai');
        $entry['rpd']  = (int) ($catalog['rpd'] ?? 0);
        $entry['key'] ??= $catalog['key'] ?? '';
        // Retry de cold start (serverless): vem do catálogo, a entrada de
        // MODELS pode sobrescrever se um modelo precisar de outro ritmo.
        $entry['retries']     ??= $catalog['retries'] ?? 0;
        $entry['retry_delay'] ??= $catalog['retry_delay'] ?? 10;
    }
    if (($entry['url'] ?? '') === '' || ($entry['model'] ?? '') === '') {
        return [];
    }
    // type default 'openai' quando o provedor é inline (sem nome em PROVIDERS).
    if (($entry['type'] ?? '') === '') {
        $entry['type'] = 'openai';
    }
    // Rótulo base usado em log, header e cooldown. O nome distingue duas
    // contas no mesmo host (openrouter1 x openrouter2), coisa que a url
    // não faz. Quando 'key' é array, cada expansão ganha um sufixo '#N'.
    $baseLabel = $name !== '' ? $name : (parse_url((string) $entry['url'], PHP_URL_HOST) ?: 'inline');

    $keys = $entry['key'] ?? '';
    if (is_array($keys)) {
        $keys = array_values(array_filter(array_map('strval', $keys), static fn (string $k): bool => $k !== ''));
        // Array só com vazios: em runtime não há nada a expandir. Mas para
        // diagnóstico (php index.php check) preservamos 1 entrada com key=''
        // para o erro apontar "key vazia" em vez de "entrada incompleta".
        $keys = $keys !== [] ? $keys : [''];
    } else {
        $keys = [(string) $keys];
    }

    $expanded = [];
    $multiple = count($keys) > 1;
    foreach ($keys as $index => $key) {
        $expanded[] = ['key' => $key, 'label' => $multiple ? $baseLabel . '#' . ($index + 1) : $baseLabel] + $entry;
    }
    return $expanded;
}

/**
 * Provedores locais (Ollama, LM Studio, vLLM na mesma máquina) rodam sem
 * chave legitimamente. Usado para não pular nem avisar sobre esses quando
 * a chave está vazia — só pulamos/avisamos provedores remotos sem chave.
 */
function provider_is_local(string $url): bool
{
    $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
    return in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
        || str_ends_with($host, '.localhost');
}

/**
 * Sorteio ponderado sem reposição (Efraimidis-Spirakis): cada provedor
 * ganha a chave u^(1/peso) com u uniforme em (0,1]; ordenar por ela em
 * ordem decrescente dá exatamente a distribuição desejada. Um provedor
 * com weight 9 abre a fila em ~9 de cada 10 requisições, mas todos
 * continuam na ordem final — o peso muda a frequência, nunca a
 * disponibilidade. Sem weight, vale 1.
 */
function weighted_shuffle(array $providers): array
{
    $providers = array_values($providers);
    $keys      = [];
    foreach ($providers as $index => $provider) {
        $weight       = max(1, (int) ($provider['weight'] ?? 1));
        $keys[$index] = (random_int(1, PHP_INT_MAX) / PHP_INT_MAX) ** (1 / $weight);
    }
    arsort($keys);
    return array_map(static fn (int $index): array => $providers[$index], array_keys($keys));
}

/**
 * Ordena pelo p50 da janela de métricas, do mais rápido para o mais lento.
 *
 * Provedor ainda sem medição recebe a média dos demais: entra no meio da
 * fila e vai sendo amostrado, sem furar a frente de quem já provou ser
 * rápido nem ficar esquecido no fim. Sem métricas, mantém a ordem do array.
 */
function fastest_first(array $providers): array
{
    $snapshot = metrics_snapshot();
    if ($snapshot === []) {
        return $providers;
    }

    $known = [];
    foreach ($providers as $index => $provider) {
        $p50 = (int) ($snapshot[$provider['label']]['p50_ms'] ?? 0);
        if ($p50 > 0) {
            $known[$index] = $p50;
        }
    }
    if ($known === []) {
        return $providers;
    }
    $average = (int) round(array_sum($known) / count($known));

    $order = [];
    foreach ($providers as $index => $provider) {
        $order[$index] = $known[$index] ?? $average;
    }
    asort($order);
    return array_map(static fn (int $index): array => $providers[$index], array_keys($order));
}

/** Tentativa sem streaming: resposta completa, converte e devolve. */
function try_provider_buffered(array $provider, array $request, string $model, int $attempt, string $logModel, string $requestId): array
{
    $payload = build_payload($request, $provider);
    $started = microtime(true);
    $result  = call_provider($provider, $payload, null);
    $ms      = (int) round((microtime(true) - $started) * 1000);

    if ($result['status'] === 200) {
        $decoded = json_decode($result['body'], true);
        $canonical = is_array($decoded)
            ? ($provider['type'] === 'openai'
                ? canonical_from_openai_response($decoded)
                : canonical_from_anthropic_response($decoded))
            : null;

        if ($canonical !== null && canonical_is_usable($canonical)) {
            log_attempt($logModel, $provider, 200, $ms, 'ok', $requestId, $canonical['usage']);
            breaker_success($provider);
            send_router_headers($provider, $attempt, $logModel, $model);
            send_json(200, render_openai_response($canonical, $model));
            return provider_result(true, 200, '');
        }

        // 200 sem resposta utilizável é falha de provedor, não sucesso:
        // vira 5xx para entrar no cooldown/breaker e rotacionar.
        $result['status'] = 502;
        $result['body']   = $canonical === null
            ? json_encode(['error' => ['message' => 'resposta nao e JSON valido']])
            : ($result['body'] !== '' ? $result['body'] : json_encode(['error' => ['message' => 'resposta vazia']]));
    }

    $reason = failure_reason($result);
    log_attempt($logModel, $provider, $result['status'], $ms, $reason, $requestId);
    mark_failure($provider, $result);
    return provider_result(false, $result['status'] ?: 502, $reason, '', false, $result['status'] === 0);
}

/**
 * Tentativa com streaming. Nada sai para o cliente antes do 200 do provedor
 * E do primeiro evento útil, então rate limit e erro continuam rotacionando.
 *
 * Provedor que cai no meio não aborta a requisição: o parcial volta para o
 * caller tentar o próximo. Só encerra de verdade se foi o CLIENTE que
 * desconectou — nesse caso não há para quem continuar.
 */
function try_provider_stream(array $provider, array $request, string $model, int $attempt, string $logModel, string $requestId, string $streamId, string $partial = ''): array
{
    $payload    = build_payload($request, $provider);
    $translator = make_translator($provider['type'], $model, $streamId, !empty($request['stream_options']['include_usage']));
    if ($partial !== '') {
        // Continuação: o tradutor corta a repetição do final do texto anterior.
        $translator->continuing($partial);
    }
    $started = microtime(true);
    $opened  = false;

    $emit = function (string $output) use (&$opened, $provider, $attempt, $logModel, $model): void {
        if (!$opened) {
            // Headers só na primeira emissão. Numa retomada de stream
            // interrompido eles já foram enviados pela tentativa anterior.
            if (!headers_sent()) {
                send_router_headers($provider, $attempt, $logModel, $model);
                send_stream_headers();
            }
            $opened = true;
        }
        echo $output;
        flush();
    };

    $result = call_provider($provider, $payload, function (string $chunk) use ($translator, $emit): void {
        // Chunk vazio é a batida de heartbeat de call_provider: não veio nada
        // do provedor há um tempo. Manda um comentário SSE para o proxy não
        // derrubar a conexão enquanto o modelo "pensa".
        if ($chunk === '') {
            $emit(sse_heartbeat());
            return;
        }
        $output = $translator->push($chunk);
        if ($output !== '') {
            $emit($output);
        }
    });

    $ms = (int) round((microtime(true) - $started) * 1000);

    // Sucesso exige o terminador do provedor. Sem essa checagem, um provedor
    // que fecha a conexão no meio do stream produziria um curl "bem-sucedido"
    // e o cliente receberia meia resposta achando que era a resposta inteira.
    if ($result['status'] === 200 && $result['error'] === '' && $translator->isComplete()) {
        echo $translator->finish();
        flush();
        log_attempt($logModel, $provider, 200, $ms, 'ok-stream', $requestId, $translator->usage());
        breaker_success($provider);
        return provider_result(true, 200, '', '', $opened);
    }

    // Provedor ignorou stream:true e devolveu uma resposta JSON normal
    // (acontece em setups de vLLM e LM Studio). O conteúdo está correto —
    // só não veio no formato pedido. Reemite como stream em vez de queimar
    // a fila inteira por causa do formato.
    if (!$opened && $result['status'] === 200 && $result['error'] === '' && !$translator->isComplete()) {
        $salvaged = stream_from_json($translator->rawBody(), $provider, $model, $streamId);
        if ($salvaged !== null) {
            if (!headers_sent()) {
                send_router_headers($provider, $attempt, $logModel, $model);
                send_stream_headers();
            }
            echo $salvaged['sse'];
            flush();
            log_attempt($logModel, $provider, 200, $ms, 'ok-stream-convertido', $requestId, $salvaged['usage']);
            breaker_success($provider);
            return provider_result(true, 200, '', '', true);
        }
    }

    // Cliente saiu no meio: ninguém vai ler a continuação. Encerra.
    if ($opened && connection_aborted()) {
        log_attempt($logModel, $provider, $result['status'], $ms, 'cliente-desconectou', $requestId);
        return provider_result(true, 200, '', '', true);
    }

    // Orçamento total estourado no meio do stream: o curl foi abortado por
    // nós, não pelo provedor. Diz isso no log em vez de culpar a rede.
    if (deadline_exceeded()) {
        log_attempt($logModel, $provider, $result['status'], $ms, 'tempo-esgotado', $requestId);
        return provider_result(false, 504, 'tempo limite da requisicao esgotado', $translator->emittedText(), $opened);
    }

    $network = $result['status'] === 0;

    // 200 que não completou não tem status de erro próprio (o 200 já veio).
    // Vira 502 para o resto do fluxo enxergar uma falha de provedor de
    // verdade — e entrar no cooldown e no breaker como qualquer outra.
    if ($result['status'] === 200 && $result['error'] === '') {
        $upstream = $translator->upstreamError();
        $result   = [
            'status' => 502,
            'error'  => '',
            'body'   => json_encode(['error' => ['message' => $upstream !== '' ? $upstream : 'stream encerrado sem terminador']]),
        ];
    }

    $reason = failure_reason($result);
    log_attempt($logModel, $provider, $result['status'], $ms, $opened ? 'stream-interrompido' : $reason, $requestId);
    mark_failure($provider, $result);

    // done=false mantém o loop vivo; partial alimenta a retomada. Não
    // chamamos finish() aqui: o stream SSE segue aberto para o próximo
    // provedor continuar escrevendo de onde este parou.
    return provider_result(
        false,
        $result['status'] ?: 502,
        $reason,
        $translator->emittedText(),
        $opened,
        $network,
        $translator->emittedToolCall()
    );
}

/**
 * Converte uma resposta buferizada em chunks SSE. Devolve null quando o
 * corpo não é uma resposta aproveitável — aí o caminho normal de falha
 * assume e o router rotaciona.
 */
function stream_from_json(string $body, array $provider, string $model, string $streamId): ?array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    $canonical = $provider['type'] === 'openai'
        ? canonical_from_openai_response($decoded)
        : canonical_from_anthropic_response($decoded);
    if (!canonical_is_usable($canonical)) {
        return null;
    }

    $rendered = render_openai_response($canonical, $model);
    $message  = $rendered['choices'][0]['message'];
    $delta    = ['role' => 'assistant'];
    foreach (['content', 'reasoning_content', 'tool_calls'] as $field) {
        if (!empty($message[$field])) {
            $delta[$field] = $message[$field];
        }
    }
    $chunk = static fn (array $d, ?string $stop): string => sse([
        'id'      => $streamId,
        'object'  => 'chat.completion.chunk',
        'created' => time(),
        'model'   => $model,
        'choices' => [['index' => 0, 'delta' => (object) $d, 'finish_reason' => $stop]],
    ]);

    return [
        'sse'   => $chunk($delta, null) . $chunk([], $rendered['choices'][0]['finish_reason']) . "data: [DONE]\n\n",
        'usage' => $canonical['usage'],
    ];
}

/**
 * Forma única do retorno das tentativas, para o loop não adivinhar chaves.
 * 'network' é um campo próprio em vez de "status === 0": o status devolvido
 * já vem normalizado para 502 quando não houve resposta HTTP, então inferir
 * rede pelo status daria sempre falso — foi assim que RETRY_SAME_PROVIDER
 * ficou sem efeito antes.
 */
function provider_result(bool $done, int $status, string $error, string $partial = '', bool $opened = false, bool $network = false, bool $toolCall = false): array
{
    return [
        'done'     => $done,
        'status'   => $status,
        'error'    => $error,
        'partial'  => $partial,
        'opened'   => $opened,
        'network'  => $network,
        'toolCall' => $toolCall,
    ];
}

/** Registra a falha no cooldown e no breaker, com a janela que o tipo de erro pede. */
function mark_failure(array $provider, array $result): void
{
    $seconds = failure_cooldown($result);
    if ($seconds <= 0) {
        return;
    }
    cooldown_mark($provider, $seconds);
    // Erro de configuração conta inteiro no breaker; falha de rede pura conta
    // meio, porque costuma ser transitória.
    breaker_failure($provider, $result['status'] === 0);
}

// =====================================================================
// ESTADO OPCIONAL (COOLDOWN, BREAKER E RATE LIMIT)
// =====================================================================

// Um único arquivo JSON com flock guarda os três. Qualquer falha de disco
// desliga a funcionalidade em silêncio: estado aqui é otimização, nunca
// condição para responder.

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
    return json_file_update(STATE_FILE, $mutator);
}

/**
 * Atualiza um arquivo JSON sob flock exclusivo. Usado pelo estado
 * (cooldown/breaker/rate limit) e pelas métricas em modo 'file' — as duas
 * precisam exatamente do mesmo read-modify-write atômico.
 */
function json_file_update(string $path, callable $mutator): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        // A pasta pode estar dentro do docroot: nega acesso web por dentro também.
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return [];
    }
    // Sem lock exclusivo (ex.: NFS sem flock) não dá para escrever com
    // segurança: aborta e deixa a funcionalidade desligada em silêncio,
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
    // JSON_UNESCAPED_UNICODE porque labels de provedor são nomes livres e
    // podem ter acentos: sem isso o arquivo sai cheio de \uXXXX e fica
    // difícil de inspecionar na mão.
    fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
}

/** Identidade estável do provedor no estado, sem expor url nem chave. */
function provider_key(array $provider): string
{
    return substr(md5($provider['label'] . '|' . $provider['model']), 0, 12);
}

function cooldown_active(array $provider, array $state): bool
{
    return ((int) ($state['cooldown'][provider_key($provider)] ?? 0)) > time();
}

function cooldown_mark(array $provider, int $seconds): void
{
    if ($seconds <= 0) {
        return;
    }
    state_update(static function (array $state) use ($provider, $seconds): array {
        $now = time();
        $state['cooldown'][provider_key($provider)] = $now + $seconds;
        // Limpa expirados para o arquivo não crescer para sempre.
        foreach ($state['cooldown'] as $key => $until) {
            if ($until <= $now) {
                unset($state['cooldown'][$key]);
            }
        }
        return $state;
    });
}

// ---------------------------------------------------------------------
// CIRCUIT BREAKER (opcional, além do cooldown)
// Estado por provedor em state.json:
//   breaker[chave] = { failures: float, opened_until: int, last_probe: int }
// Estados: CLOSED (normal) -> OPEN (N falhas consecutivas, fora da rotação)
// -> HALF_OPEN (probe periódico: 1 req de teste) -> CLOSED se sucesso.
// ---------------------------------------------------------------------

/** Provedor aberto e fora da janela de probe? Não entra na rotação. */
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
        // Circuito aberto: só permite req de probe no intervalo configurado.
        return ($now - $lastProbe) < BREAKER_PROBE_SECONDS;
    }
    // Half-open (janela expirou). Se já houve um probe recente (last_probe
    // após opened_until) que falhou sem reabrir, espera o intervalo antes
    // de permitir outro — sem isto, provedores em half-open seriam
    // afogados em probes em sequência sem esperar.
    return $lastProbe > $openedUntil && ($now - $lastProbe) < BREAKER_PROBE_SECONDS;
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

/** Marca falha: acumula (rede pura conta como 0.5) e abre se passar do teto. */function breaker_failure(array $provider, bool $networkOnly): void
{
    if (BREAKER_FAILURES <= 0) {
        return;
    }
    state_update(static function (array $state) use ($provider, $networkOnly): array {
        $key   = provider_key($provider);
        $entry = $state['breaker'][$key] ?? ['failures' => 0, 'opened_until' => 0, 'last_probe' => 0];
        $entry['failures']  = ($entry['failures'] ?? 0) + ($networkOnly ? 0.5 : 1.0);
        $entry['last_probe'] = time();
        if ($entry['failures'] >= BREAKER_FAILURES) {
            $entry['opened_until'] = time() + BREAKER_OPEN_SECONDS;
            $entry['failures']     = 0;
        }
        $state['breaker'][$key] = $entry;
        return $state;
    });
}

/**
 * Janela fixa de um minuto, um contador POR CHAVE do gateway. Retorna os
 * segundos que faltam para a janela virar quando estourado (>=1), ou 0
 * quando liberado. Por chave e não global porque, com vários apps no mesmo
 * gateway, um app em loop não pode consumir a cota dos outros.
 */
function rate_limited_seconds(string $bucket): int
{
    if (RATE_LIMIT_PER_MINUTE <= 0) {
        return 0;
    }
    $window = (int) floor(time() / 60);

    // Leitura rápida sem lock: se já estourou, devolve 429 sem tocar no
    // lock. Se ainda há folga, só então adquire lock para incrementar —
    // evita serializar todas as requisições num lock global de disco.
    $snapshot = state_read()['rate'][$bucket] ?? [];
    if ((int) ($snapshot['window'] ?? 0) === $window && (int) ($snapshot['count'] ?? 0) >= RATE_LIMIT_PER_MINUTE) {
        return rate_window_remaining();
    }

    $count = 0;
    state_update(static function (array $state) use ($window, $bucket, &$count): array {
        if ((int) ($state['rate'][$bucket]['window'] ?? 0) !== $window) {
            $state['rate'][$bucket] = ['window' => $window, 'count' => 0];
        }
        $count = ++$state['rate'][$bucket]['count'];
        // Baldes de janelas antigas não servem para nada e o arquivo não
        // pode crescer a cada chave que já apareceu por aqui.
        foreach ($state['rate'] as $key => $entry) {
            if ((int) ($entry['window'] ?? 0) < $window) {
                unset($state['rate'][$key]);
            }
        }
        return $state;
    });
    return $count > RATE_LIMIT_PER_MINUTE ? rate_window_remaining() : 0;
}

/** Segundos ate o fim da janela de 1 minuto atual. */
function rate_window_remaining(): int
{
    return max(1, 60 - (time() % 60));
}

// ---------------------------------------------------------------------
// COTA DIÁRIA POR PROVEDOR ('rpd' em PROVIDERS)
// Free tier costuma limitar por requisições/DIA, não por minuto — e o
// cooldown só reage DEPOIS do 429, quando a requisição já foi queimada.
// Contando aqui, o provedor sai da fila antes de custar uma tentativa.
// Estado: quota[chave] = { day: 'Y-m-d', count: int, limit: int }
// ---------------------------------------------------------------------

/** Identidade da CONTA (label), não do par conta+modelo: a cota é da conta. */
function quota_key(string $label): string
{
    return substr(md5($label), 0, 12);
}

/** Dia local do servidor — os provedores costumam zerar em UTC, mas o
 *  contador serve como estimativa conservadora e não como cobrança. */
function quota_today(): string
{
    return gmdate('Y-m-d');
}

function quota_consume(array $provider): void
{
    $limit = (int) ($provider['rpd'] ?? 0);
    if ($limit <= 0) {
        return; // provedor sem teto declarado não paga o custo do state_update
    }
    state_update(static function (array $state) use ($provider, $limit): array {
        $key   = quota_key($provider['label']);
        $today = quota_today();
        $entry = $state['quota'][$key] ?? [];
        if (($entry['day'] ?? '') !== $today) {
            $entry = ['day' => $today, 'count' => 0];
        }
        $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
        $entry['limit'] = $limit;
        $state['quota'][$key] = $entry;

        // Limpa dias anteriores para o arquivo não crescer para sempre.
        foreach ($state['quota'] as $other => $data) {
            if (($data['day'] ?? '') !== $today) {
                unset($state['quota'][$other]);
            }
        }
        return $state;
    });
}

function quota_exhausted(array $provider, array $state): bool
{
    $limit = (int) ($provider['rpd'] ?? 0);
    if ($limit <= 0) {
        return false;
    }
    $entry = $state['quota'][quota_key($provider['label'])] ?? null;
    return $entry !== null
        && ($entry['day'] ?? '') === quota_today()
        && (int) ($entry['count'] ?? 0) >= $limit;
}

/** Junta o consumo diário ao snapshot de métricas de /health/providers. */
function quota_annotate(array $snapshot): array
{
    $quota = state_read()['quota'] ?? [];
    $today = quota_today();
    foreach ($snapshot as $label => $metrics) {
        $entry = $quota[quota_key((string) $label)] ?? null;
        if ($entry === null || ($entry['day'] ?? '') !== $today) {
            continue;
        }
        $snapshot[$label]['rpd_used']  = (int) ($entry['count'] ?? 0);
        $snapshot[$label]['rpd_limit'] = (int) ($entry['limit'] ?? 0);
    }
    return $snapshot;
}

// =====================================================================
// SAÍDA, LOG E UTILITÁRIOS
// =====================================================================

function send_json(int $status, array $data): void
{
    // headers_sent() já verdadeiro significa que um stream está aberto:
    // mexer em status ou Content-Type ali só geraria warning.
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Erro no formato OpenAI — sem expor host, chave ou caminho interno. */
function send_error(int $status, string $type, string $message): void
{
    send_json($status, ['error' => ['message' => $message, 'type' => $type, 'code' => $status]]);
}

/** Diz qual provedor atendeu e em que tentativa. Não expõe a chave. */
function send_router_headers(array $provider, int $attempt, string $servedModel = '', string $requestedModel = ''): void
{
    if (!EXPOSE_PROVIDER_HEADER || headers_sent()) {
        return;
    }
    header('X-Router-Provider: ' . $provider['label']);
    header('X-Router-Model: ' . $provider['model']);
    header('X-Router-Attempt: ' . $attempt);
    // MODEL_FALLBACKS troca o modelo por outro quando o pedido falha inteiro.
    // Isso nunca deve ser silencioso: o app pediu um modelo e recebeu outro.
    if ($servedModel !== '' && $servedModel !== $requestedModel) {
        header('X-Router-Fallback: ' . $servedModel);
    }
}

/** Uma linha por tentativa. Registra rótulo do provedor, nunca a chave. */
function log_attempt(string $model, array $provider, int $status, int $ms, string $outcome, string $requestId = '', array $usage = []): void
{
    $tokensIn  = (int) ($usage['input'] ?? 0);
    $tokensOut = (int) ($usage['output'] ?? 0);

    if (LOG_FILE !== '') {
        // Rotaciona antes de crescer demais: o gateway costuma rodar em host
        // compartilhado. Mantém duas janelas de histórico (.1 e .2).
        if (LOG_MAX_BYTES > 0 && @filesize(LOG_FILE) > LOG_MAX_BYTES) {
            if (is_file(LOG_FILE . '.1')) {
                @rename(LOG_FILE . '.1', LOG_FILE . '.2');
            }
            @rename(LOG_FILE, LOG_FILE . '.1');
        }
        // Formato TSV: uma tentativa por linha, direto para cut/awk/grep.
        // \t e \n saem dos campos livres para não quebrar o alinhamento.
        // Colunas: data, modelo, provedor, modelo-no-provedor, status, ms,
        // resultado, request id, tokens de entrada, tokens de saída.
        // Tokens ficam no fim para não quebrar script que já lê as 8 primeiras.
        $line = implode("\t", array_map(
            static fn (string $field): string => strtr($field, ["\t" => ' ', "\n" => ' ', "\r" => '']),
            [
                date('c'), $model, $provider['label'], $provider['model'],
                (string) $status, $ms . 'ms', $outcome, $requestId,
                (string) $tokensIn, (string) $tokensOut,
            ]
        )) . "\n";
        @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }

    // Métricas agregadas. Falha silenciosa.
    metrics_record($model, $provider, $status, $ms, $outcome, $requestId, $tokensIn, $tokensOut);

    // log_attempt roda exatamente uma vez por tentativa, então é o lugar
    // natural do contador diário — que só escreve para quem declarou 'rpd'.
    // Só conta tentativas que o provedor cobrou (resposta 2xx): falhas de
    // auth/rede/5xx não entregam resposta e não devem queimar a cota diária.
    if ($status >= 200 && $status < 300) {
        quota_consume($provider);
    }
}

function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(12));
}

/**
 * Reaproveita o X-Request-Id que o cliente mandou, quando ele existe e tem
 * formato seguro. Assim o mesmo id aparece no log do app e no do gateway, e
 * um erro deixa de exigir cruzar horário com sorte. Sem cabeçalho, ou com
 * conteúdo estranho, gera um id próprio.
 */
function request_id(): string
{
    $sent = trim(server_header('HTTP_X_REQUEST_ID'));
    return $sent !== '' && strlen($sent) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $sent) === 1
        ? $sent
        : new_id('req');
}

function server_header(string $key): string
{
    return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
}
