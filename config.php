<?php
declare(strict_types=1);

/**
 * LocalRouter — configuração. Tudo vive neste arquivo, em define().
 *
 * Cada valor aceita texto puro OU variável de ambiente via env('X'): a função
 * lê o ambiente real (painel do SO, shell, Docker) e, se estiver vazio, cai
 * para data/.env. É onde as chaves ficam fora do Git.
 * Valide com:  php index.php check
 * Teste de verdade com:  php index.php test <modelo>
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

// =====================================================================
// .ENV (opcional) — lido sob demanda por env()
//
// env('X') lê na ordem: (1) variável de ambiente real do SO/shell/Docker,
// se existir e não for vazia; (2) valor declarado em data/.env; (3) ''.
// O ambiente real sempre vence sobre o arquivo, que só preenche o que
// estiver vazio. É onde as chaves ficam fora do Git.
//
// O .env é parseado uma única vez por processo e guardado em estática. O
// parser troca '#' no início das linhas por ';' antes de parse_ini_string(),
// porque o PHP 8.2+ deixou de tratar '#' como comentário no parser INI;
// '#' dentro de valores entre aspas é preservado.
// =====================================================================

/**
 * Lê uma variável de ambiente com fallback automático ao data/.env.
 *
 * Ordem de precedência (a primeira não vazia vence):
 *   1. Variável de ambiente real (getenv) — painel do SO, shell, Docker.
 *   2. Valor declarado em data/.env (carregado e cacheado na 1a chamada).
 *   3. '' (vazio).
 *
 * Uso:  'key' => env('OPENROUTER_KEY')
 */
function env(string $name): string
{
    static $ini = null;
    if ($ini === null) {
        $ini = [];
        $path = __DIR__ . '/data/.env';
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                $parsed = @parse_ini_string(
                    preg_replace('/^(\s*)#/m', '$1;', $raw),
                    false,
                    INI_SCANNER_RAW
                );
                if (is_array($parsed)) {
                    foreach ($parsed as $k => $v) {
                        // INI_SCANNER_RAW não remove aspas: KEY="valor" chega
                        // como "valor" (com aspas literais) e quebraria a auth.
                        // Remove um par de aspas simples/duplas envolvendo o valor.
                        $v = (string) $v;
                        if (strlen($v) >= 2) {
                            $first = $v[0];
                            if (($first === '"' || $first === "'") && $v[strlen($v) - 1] === $first) {
                                $v = substr($v, 1, -1);
                            }
                        }
                        $ini[trim((string) $k)] = $v;
                    }
                }
            }
        }
    }
    // 1. Ambiente real (SO/shell) vence se existir E não for vazio.
    $real = getenv($name);
    if ($real !== false && $real !== '') {
        return $real;
    }
    // 2. Fallback ao .env.
    return $ini[$name] ?? '';
}

// =====================================================================
// ACESSO AO GATEWAY
// =====================================================================

/**
 * Chaves que SEUS apps enviam em "Authorization: Bearer <chave>".
 * Gere uma com: php index.php genkey
 *
 * O gateway recusa servir enquanto a chave de exemplo estiver aqui. Várias
 * chaves na lista permitem revogar uma sem derrubar as outras, e cada uma
 * tem seu próprio balde de rate limit.
 *
 * array_filter remove entradas vazias: sem LR_GATEWAY_KEY definida, env()
 * devolve '' e a lista ficaria [''], o que derrubaria toda autenticação com
 * 401 sem nenhum aviso. Lista vazia é tratada como "não configurado" por
 * gateway_has_placeholder_key().
 */
define('GATEWAY_KEYS', array_values(array_filter([
    env('LR_GATEWAY_KEY'),
], static fn (string $key): bool => trim($key) !== '')));

// =====================================================================
// PROVEDORES
// =====================================================================

// O catálogo de provedores vive em providers.php (na raiz, ao lado do
// config.php). env() já está definido acima, então o require roda limpo.
define('PROVIDERS', require __DIR__ . '/providers.php');

// =====================================================================
// MODELOS
// =====================================================================

// O catálogo de modelos vive em models.php (na raiz). env() já está
// definido acima, então o require roda limpo. Modelos de embedding também
// ficam lá, marcados com 'type' => 'embedding' (ausente = 'chat').
define('MODELS', require __DIR__ . '/models.php');

/** Tipos de modelo válidos (campo 'type' em cada entrada de MODELS).
 *  Ausente = 'chat'. Valores fora desta lista caem em 'chat' em runtime e
 *  são apontados por php index.php check. Adicione aqui novos tipos
 *  (rerank, moderation...) quando surgirem — o resto do código já filtra
 *  por esta lista. */
define('MODEL_TYPES', ['chat', 'embedding']);

// =====================================================================
// OPCIONAIS
// =====================================================================

/** Ordem em que os provedores de um modelo são tentados:
 *  'priority' = ordem do array (coloque os gratuitos primeiro).
 *  'random'   = sorteio ponderado pelo weight de cada provedor.
 *  'fastest'  = menor latência medida (p50 da janela de métricas). Precisa de
 *               METRICS_BACKEND ligado; sem dados, cai em 'priority'. */
define('STRATEGY', 'priority');

/** Teto de provedores tentados por requisição. 0 remove o limite. */
define('MAX_ATTEMPTS', 0);

/** Parâmetros do CLIENTE repassados intactos a provedores openai.
 *  'n' fica de fora: o gateway devolve uma escolha só, então repassá-lo
 *  faria o provedor gerar e cobrar N respostas para o app receber uma. */
define('PASSTHROUGH_OPENAI', [
    'seed', 'response_format', 'parallel_tool_calls', 'presence_penalty',
    'frequency_penalty', 'logit_bias', 'logprobs', 'top_logprobs',
    'reasoning_effort', 'service_tier', 'user',
]);

/** Devolve ao cliente qual provedor atendeu (X-Router-*). */
define('EXPOSE_PROVIDER_HEADER', true);

/** Fallback ENTRE modelos: se todos os provedores de um modelo falharem, continua no modelo indicado. Vazio desativa.
 *  Quando isso acontece o cliente recebe X-Router-Fallback com o modelo que
 *  realmente respondeu — a troca nunca é silenciosa. */
define('MODEL_FALLBACKS', []);

/** Provedor que falhou por motivo passageiro (rate limit, crédito, 5xx, rede)
 *  fica de castigo por N segundos. 0 desativa. */
define('COOLDOWN_SECONDS', 60);

/** Castigo para erro de configuração do provedor: 401/403 (chave rejeitada)
 *  e 404 (modelo inexistente). Maior de propósito — isso não volta sozinho, e
 *  insistir a cada minuto queima uma tentativa de toda requisição seguinte.
 *  Erro 400 nunca gera castigo: a causa está no que o app mandou. */
define('CONFIG_ERROR_COOLDOWN', 900);

/** Circuit breaker formal (além do cooldown). 0 desativa. Quando ativo, um provedor
 *  com N falhas CONSECUTIVAS (qualquer tipo) fica "aberto" por BREAKER_OPEN_SECONDS:
 *  não entra na rotação e só volta via probe (1 req de teste) a cada BREAKER_PROBE_SECONDS.
 *  Falha de rede pura conta como meio-erro (precisa de 2 para somar 1). Exige STATE_FILE. */
define('BREAKER_FAILURES', 0);
define('BREAKER_OPEN_SECONDS', 60);
define('BREAKER_PROBE_SECONDS', 30);

/** Retry dentro do mesmo provedor antes de rotacionar, apenas para falha de rede pura
 *  (timeout, DNS, conexão recusada — sem status HTTP). 0 = rotaciona já. */
define('RETRY_SAME_PROVIDER', 1);

/** Pula provedores remotos com key='' em runtime (em vez de tentar e falhar no 401).
 *  Provedores locais (http://127.* ou http://localhost) são isentos — Ollama roda sem chave.
 *  false = comportamento legado (tenta todos). */
define('SKIP_EMPTY_REMOTE_KEY', true);

/** Teto de requisições/minuto POR CHAVE de GATEWAY_KEYS. 0 desativa.
 *  Por chave, e não global: com vários apps no mesmo gateway, um app em
 *  loop não derruba os outros. */
define('RATE_LIMIT_PER_MINUTE', 0);

/** Arquivo de estado usado por COOLDOWN_SECONDS, BREAKER_*, RATE_LIMIT_PER_MINUTE e
 *  pelo contador diário 'rpd' dos provedores. Em servidor sem .htaccess, aponte para fora do docroot. */
define('STATE_FILE', __DIR__ . '/data/state.json');

/** Allowlist de IPs. Vazio libera todos. Aceita IP exato ou prefixo ('192.168.', 'fd00:'). Usa REMOTE_ADDR. /health ignora. */
define('ALLOWED_IPS', []);

/** Recusa HTTP puro (chave trafegaria em texto claro). Localhost é isento. */
define('REQUIRE_HTTPS', true);

/** Proxies que terminam o TLS na frente do PHP. Só destes IPs o gateway confia em X-Forwarded-Proto. Vazio = nenhum. */
define('TRUSTED_PROXIES', []);

// =====================================================================
// LOG E LIMITES
// =====================================================================

/** Arquivo de log das tentativas. Vazio desativa. Nunca grava chaves nem conteúdo. Em servidor sem .htaccess, aponte para fora do docroot. */
define('LOG_FILE', __DIR__ . '/data/localrouter.log');

/** Tamanho máximo do log antes de virar .1 e recomeçar. */
define('LOG_MAX_BYTES', 5242880);

/** Métricas de uso por provedor (contagem de status, latência, taxa de erro).
 *  'off'  = desligado (só LOG_FILE, se definido).
 *  'file' = grava em METRICS_FILE (JSON rolado a cada METRICS_WINDOW_SECONDS).
 *  Para histórico longo, o próprio LOG_FILE já é TSV: awk/cut resolvem. */
define('METRICS_BACKEND', 'file');
define('METRICS_FILE', __DIR__ . '/data/metrics.json');
/** Janela rolante das métricas (segundos). */
define('METRICS_WINDOW_SECONDS', 3600);
/** Endpoint GET /metrics expõe os contadores. Autenticado como /chat/completions. */
define('METRICS_EXPOSE', false);
/** Formato da saída de /metrics: 'json' (default) ou 'prometheus' (text format). */
define('METRICS_FORMAT', 'json');

/** Limites de rede e de entrada. */
define('CONNECT_TIMEOUT', 10);      // segundos para abrir a conexão
define('REQUEST_TIMEOUT', 180);     // segundos por tentativa (não-streaming)
define('STREAM_STALL_TIME', 60);    // segundos sem receber bytes -> aborta e rotaciona
define('MAX_BODY_BYTES', 8388608);  // 8 MB de corpo aceito do cliente

/** Bundle de CA para o curl validar os certificados TLS dos provedores.
 *  O php.ini do XAMPP aponta curl.cainfo para C:\xampp\apache\bin\
 *  curl-ca-bundle.crt, que some em algumas atualizações do XAMPP — e o
 *  Windows não expõe um bundle de CA ao libcurl. Resultado: TODA chamada
 *  HTTPS falha com "unable to get local issuer certificate".
 *  Vazio = confia no default do libcurl. */
define('CA_BUNDLE', '');

/** Teto de tempo da REQUISIÇÃO INTEIRA, somando todas as tentativas. 0 desativa.
 *  Sem ele o pior caso é MAX_ATTEMPTS x REQUEST_TIMEOUT (com os padrões, 12
 *  minutos), tempo que nenhum cliente espera. O router encurta o timeout de
 *  cada tentativa para caber no que resta e para de rotacionar quando acaba. */
define('TOTAL_DEADLINE_SECONDS', 300);

/** Em streaming, manda um comentário SSE (": keep-alive") a cada N segundos
 *  sem dados do provedor. 0 desativa. Modelos de raciocínio ficam 1-2 minutos
 *  "pensando" antes do primeiro token, e proxy (Cloudflare, nginx) costuma
 *  derrubar conexão ociosa muito antes disso. O comentário é ignorado por
 *  qualquer cliente SSE e mantém o cano vivo. */
define('STREAM_HEARTBEAT_SECONDS', 15);

/** Último recurso para max_tokens (Anthropic exige o campo). */
define('DEFAULT_MAX_TOKENS', 4096);

/** Aplica DEFAULT_MAX_TOKENS também a provedores OpenAI quando ninguém definiu max_tokens.
 *  Alguns provedores OpenAI (modelos de raciocínio, certos endpoints OpenRouter) recusam
 *  chamada sem max_tokens. false = só envia para Anthropic (comportamento legado). */
define('FORCE_MAX_TOKENS_OPENAI', false);

/** Origem permitida para chamadas de navegador. Vazio = sem CORS. */
define('ALLOW_ORIGIN', '');

/** Versão da API Anthropic enviada aos provedores desse dialeto. */
define('ANTHROPIC_VERSION', '2023-06-01');

/** Gera um id por requisição e devolve em X-Request-Id, no log e nas métricas.
 *  Permite rastrear uma chamada do cliente através de todos os provedores tentados. */
define('REQUEST_ID_HEADER', true);

// =====================================================================
// GUARDRAILS (opcionais) — validações de entrada. Tudo desligado por
// padrão; ligar aqui não quebra clientes existentes.
// =====================================================================

/** Valida o Content-Type da requisição contra INPUT_ALLOWED_CONTENT_TYPES. */
define('INPUT_VALIDATE_CONTENT_TYPE', false);

/** Content-Types aceitos quando INPUT_VALIDATE_CONTENT_TYPE está ligado.
 *  [] = aceita qualquer um. */
define('INPUT_ALLOWED_CONTENT_TYPES', ['application/json']);

/** Teto de caracteres somando o texto de todas as mensagens. null = sem limite. */
define('INPUT_MAX_CHARS', null);

/** Aplica trim() no texto de cada mensagem antes de processar. */
define('INPUT_TRIM_WHITESPACE', false);

/** Recusa requisições cujas mensagens não tenham nenhum texto. */
define('INPUT_REJECT_EMPTY_MESSAGE', false);

/** Descarta mensagens system/developer enviadas pelo cliente — o system
 *  prompt do modelo (models.php) é o único que vale. */
define('INPUT_BLOCK_CLIENT_SYSTEM_PROMPT', false);

/** Ignora o campo "model" do cliente e atende sempre no primeiro modelo
 *  de chat do catálogo. Útil quando o gateway serve um único modelo. */
define('INPUT_BLOCK_CLIENT_MODEL_OVERRIDE', false);

/** Lista global de termos proibidos na entrada. */
define('BLOCKED_TERMS', []);

/** Como comparar BLOCKED_TERMS: 'exact', 'contains' ou 'regex'. */
define('BLOCKED_TERMS_MATCH_MODE', 'contains');

/** Ignora maiúsculas/minúsculas na comparação de BLOCKED_TERMS. */
define('BLOCKED_TERMS_CASE_INSENSITIVE', true);

/** Ativa a checagem de BLOCKED_TERMS na entrada. */
define('INPUT_USE_BLOCKED_TERMS', false);
