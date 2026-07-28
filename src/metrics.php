<?php
declare(strict_types=1);

/**
 * LocalRouter — metricas de uso por provedor.
 *
 * Tres backends, todos opcionais (METRICS_BACKEND = 'off' desliga tudo):
 *   - 'file'   : JSON rolado em METRICS_FILE, janela de METRICS_WINDOW_SECONDS.
 *   - 'sqlite' : historico persistente em METRICS_DB (ext-pdo sqlite).
 *   - 'off'    : nada acontece; so LOG_FILE (se definido) continua gravando.
 *
 * O que e registrado por tentativa: provedor (label), modelo pedido, status
 * HTTP, duracao em ms, resultado textual. Nunca chaves nem conteudo.
 *
 * A leitura e feita por GET /metrics (gateway.php) e por health detalhado.
 * */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

/**
 * Registra uma tentativa no backend configurado. Falha silenciosa: metricas
 * sao otimizacao, nunca condicao para responder.
 */
function metrics_record(string $model, array $provider, int $status, int $ms, string $outcome, string $request_id = ''): void
{
    if (METRICS_BACKEND === 'off') {
        return;
    }
    if (METRICS_BACKEND === 'sqlite') {
        metrics_sqlite_record($model, $provider, $status, $ms, $outcome, $request_id);
        return;
    }
    metrics_file_record($model, $provider, $status, $ms, $outcome, $request_id);
}

/**
 * Snapshot agregado por provedor: contagem por status, latencia p50/p95,
 * taxa de erro, ultima tentativa. Usado por /metrics e /health/providers.
 * Retorna [] se metricas estiverem desligadas ou vazias.
 */
function metrics_snapshot(): array
{
    if (METRICS_BACKEND === 'off') {
        return [];
    }
    if (METRICS_BACKEND === 'sqlite') {
        return metrics_sqlite_snapshot();
    }
    return metrics_file_snapshot();
}

// =====================================================================
// BACKEND FILE (JSON rolado)
// =====================================================================

/** Estrutura: { window: int, providers: { label: { status: count, ms: [..], last: int } } } */
function metrics_file_record(string $model, array $provider, int $status, int $ms, string $outcome, string $request_id = ''): void
{
    $label = (string) ($provider['label'] ?? 'inline');
    $now   = time();
    $window = (int) floor($now / METRICS_WINDOW_SECONDS);

    metrics_file_update(static function (array $state) use ($label, $status, $ms, $outcome, $window, $now, $request_id): array {
        if (($state['window'] ?? 0) !== $window) {
            $state = ['window' => $window, 'providers' => []];
        }
        $entry = $state['providers'][$label] ?? ['status' => [], 'ms' => [], 'last' => 0, 'last_outcome' => '', 'last_request_id' => ''];
        $entry['status'][(string) $status] = ($entry['status'][(string) $status] ?? 0) + 1;
        $entry['ms'][] = $ms;
        if (count($entry['ms']) > 200) {
            $entry['ms'] = array_slice($entry['ms'], -200);
        }
        $entry['last'] = $now;
        $entry['last_outcome'] = $outcome;
        $entry['last_request_id'] = $request_id;
        $state['providers'][$label] = $entry;
        return $state;
    });
}

function metrics_file_update(callable $mutator): array
{
    $dir = dirname(METRICS_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $handle = @fopen(METRICS_FILE, 'c+');
    if ($handle === false) {
        return [];
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return [];
    }
    $raw   = stream_get_contents($handle);
    $state = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    $state = $mutator($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
}

function metrics_file_snapshot(): array
{
    if (!is_file(METRICS_FILE)) {
        return [];
    }
    $raw = @file_get_contents(METRICS_FILE);
    $state = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    $window = (int) floor(time() / METRICS_WINDOW_SECONDS);
    if (($state['window'] ?? 0) !== $window) {
        return []; // janela expirada, nada util
    }
    return metrics_aggregate($state['providers'] ?? []);
}

// =====================================================================
// BACKEND SQLITE (historico persistente)
// =====================================================================

function metrics_sqlite_db(): ?PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        return null; // caller cai em file
    }
    $dir = dirname(METRICS_DB);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    try {
        $pdo = new PDO('sqlite:' . METRICS_DB);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                model TEXT NOT NULL,
                provider TEXT NOT NULL,
                provider_model TEXT NOT NULL,
                status INTEGER NOT NULL,
                ms INTEGER NOT NULL,
                outcome TEXT NOT NULL,
                request_id TEXT NOT NULL DEFAULT ""
            )'
        );
        // Coluna adicionada depois: ALTER TABLE em banco ja existente.
        try {
            $pdo->exec('ALTER TABLE attempts ADD COLUMN request_id TEXT NOT NULL DEFAULT ""');
        } catch (Throwable $e) {
            // coluna ja existe — silencioso
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_ts ON attempts(ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_provider ON attempts(provider, ts)');
    } catch (Throwable $e) {
        return null;
    }
    return $pdo;
}

function metrics_sqlite_record(string $model, array $provider, int $status, int $ms, string $outcome, string $request_id = ''): void
{
    $pdo = metrics_sqlite_db();
    if ($pdo === null) {
        // Fallback silencioso para file se sqlite nao estiver disponivel.
        metrics_file_record($model, $provider, $status, $ms, $outcome, $request_id);
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO attempts (ts, model, provider, provider_model, status, ms, outcome, request_id)
         VALUES (:ts, :model, :provider, :pmodel, :status, :ms, :outcome, :reqid)'
    );
    if ($stmt === false) {
        return;
    }
    $stmt->execute([
        ':ts'       => time(),
        ':model'    => $model,
        ':provider' => (string) ($provider['label'] ?? 'inline'),
        ':pmodel'   => (string) ($provider['model'] ?? ''),
        ':status'   => $status,
        ':ms'       => $ms,
        ':outcome'  => $outcome,
        ':reqid'    => $request_id,
    ]);
}

function metrics_sqlite_snapshot(): array
{
    $pdo = metrics_sqlite_db();
    if ($pdo === null) {
        return metrics_file_snapshot();
    }
    $since = time() - METRICS_WINDOW_SECONDS;

    // Contagem por status + ultima tentativa por provedor, em uma query so.
    // Reagrupar em PHP evita GROUP_CONCAT e mantem o driver leve.
    $counts = $pdo->query(
        "SELECT provider, status, COUNT(*) AS c, MAX(ts) AS last
         FROM attempts WHERE ts >= {$since}
         GROUP BY provider, status"
    );
    if ($counts === false) {
        return [];
    }
    $byProvider = [];
    foreach ($counts as $row) {
        $label = (string) $row['provider'];
        if (!isset($byProvider[$label])) {
            $byProvider[$label] = ['status' => [], 'ms' => [], 'last' => 0, 'last_outcome' => ''];
        }
        $byProvider[$label]['status'][(string) $row['status']] = (int) $row['c'];
        $byProvider[$label]['last'] = max($byProvider[$label]['last'], (int) $row['last']);
    }

    // Latencias por provedor (p50/p95 precisam da lista completa).
    $lat = $pdo->query("SELECT provider, ms FROM attempts WHERE ts >= {$since}");
    if ($lat !== false) {
        foreach ($lat as $row) {
            $byProvider[(string) $row['provider']]['ms'][] = (int) $row['ms'];
        }
    }
    return metrics_aggregate($byProvider);
}

// =====================================================================
// AGREGACAO COMUM (file + sqlite convergem aqui)
// =====================================================================

function metrics_aggregate(array $providers): array
{
    $out = [];
    foreach ($providers as $label => $entry) {
        $statusCounts = $entry['status'] ?? [];
        $total = 0;
        $errors = 0;
        foreach ($statusCounts as $code => $count) {
            $total += $count;
            $code = (int) $code;
            if ($code === 0 || $code >= 400) {
                $errors += $count;
            }
        }
        $ms = $entry['ms'] ?? [];
        sort($ms);
        $out[$label] = [
            'total'          => $total,
            'errors'         => $errors,
            'error_rate'     => $total > 0 ? round($errors / $total, 4) : 0,
            'status'         => $statusCounts,
            'p50_ms'         => metrics_percentile($ms, 0.5),
            'p95_ms'         => metrics_percentile($ms, 0.95),
            'last_at'        => (int) ($entry['last'] ?? 0),
            'last_outcome'   => (string) ($entry['last_outcome'] ?? ''),
            'last_request_id'=> (string) ($entry['last_request_id'] ?? ''),
        ];
    }
    return $out;
}

function metrics_percentile(array $sorted, float $p): int
{
    if ($sorted === []) {
        return 0;
    }
    $idx = (int) floor($p * (count($sorted) - 1));
    return (int) $sorted[$idx];
}

/**
 * Renderiza o snapshot no formato pedido (json ou prometheus text format).
 */
function metrics_render(array $snapshot, string $format): string
{
    if ($format === 'prometheus') {
        return metrics_render_prometheus($snapshot);
    }
    return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function metrics_render_prometheus(array $snapshot): string
{
    $lines = [];
    $lines[] = '# HELP localrouter_attempts_total Total de tentativas por provedor.';
    $lines[] = '# TYPE localrouter_attempts_total counter';
    $lines[] = '# HELP localrouter_errors_total Total de erros por provedor.';
    $lines[] = '# TYPE localrouter_errors_total counter';
    $lines[] = '# HELP localrouter_latency_p95_ms Latencia p95 em ms por provedor.';
    $lines[] = '# TYPE localrouter_latency_p95_ms gauge';
    foreach ($snapshot as $label => $m) {
        $l = metrics_prom_label($label);
        $lines[] = "localrouter_attempts_total{$l} {$m['total']}";
        $lines[] = "localrouter_errors_total{$l} {$m['errors']}";
        $lines[] = "localrouter_latency_p95_ms{$l} {$m['p95_ms']}";
        foreach ($m['status'] as $code => $count) {
            $cl = metrics_prom_label($label, ['status' => (string) $code]);
            $lines[] = "localrouter_status_total{$cl} {$count}";
        }
    }
    return implode("\n", $lines) . "\n";
}

function metrics_prom_label(string $label, array $extra = []): string
{
    $pairs = ['provider' => $label];
    foreach ($extra as $k => $v) {
        $pairs[$k] = (string) $v;
    }
    $parts = [];
    foreach ($pairs as $k => $v) {
        $v = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v);
        $parts[] = $k . '="' . $v . '"';
    }
    return '{' . implode(',', $parts) . '}';
}