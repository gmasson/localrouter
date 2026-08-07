<?php
declare(strict_types=1);

/**
 * LocalRouter — métricas de uso por provedor.
 *
 * METRICS_BACKEND 'file' grava um JSON rolado em METRICS_FILE, com janela de
 * METRICS_WINDOW_SECONDS; 'off' desliga tudo. Não há backend de banco: o
 * LOG_FILE já é TSV com uma tentativa por linha, e awk responde qualquer
 * recorte de histórico sem pedir extensão extra ao PHP.
 *
 * Registra por tentativa: provedor, status, duração, resultado e tokens.
 * Nunca chaves nem conteúdo. Lido por GET /metrics e GET /health/providers.
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

/**
 * Registra uma tentativa no backend configurado. Falha silenciosa: métricas
 * são otimização, nunca condição para responder.
 */
function metrics_record(string $model, array $provider, int $status, int $ms, string $outcome, string $requestId = '', int $tokensIn = 0, int $tokensOut = 0): void
{
    if (METRICS_BACKEND !== 'off') {
        metrics_file_record($provider, $status, $ms, $outcome, $requestId, $tokensIn, $tokensOut);
    }
}

/**
 * Snapshot agregado por provedor: contagem por status, latência p50/p95,
 * taxa de erro, última tentativa. Retorna [] se as métricas estiverem
 * desligadas ou se a janela não tiver nada.
 */
function metrics_snapshot(): array
{
    return METRICS_BACKEND === 'off' ? [] : metrics_file_snapshot();
}

// =====================================================================
// BACKEND FILE (JSON rolado)
// =====================================================================

/** Estrutura: { window: int, providers: { label: { status, ms[], last, ... } } } */
function metrics_file_record(array $provider, int $status, int $ms, string $outcome, string $requestId, int $tokensIn, int $tokensOut): void
{
    $label  = (string) ($provider['label'] ?? 'inline');
    $now    = time();
    $window = (int) floor($now / METRICS_WINDOW_SECONDS);

    json_file_update(METRICS_FILE, static function (array $state) use ($label, $status, $ms, $outcome, $window, $now, $requestId, $tokensIn, $tokensOut): array {
        if (($state['window'] ?? 0) !== $window) {
            $state = ['window' => $window, 'providers' => []];
        }
        $entry = $state['providers'][$label] ?? ['status' => [], 'ms' => [], 'last' => 0, 'last_outcome' => '', 'last_request_id' => '', 'tokens_in' => 0, 'tokens_out' => 0];
        $entry['status'][(string) $status] = ($entry['status'][(string) $status] ?? 0) + 1;
        // Teto na lista de latências: p50/p95 não ficam melhores com mais de
        // 200 amostras, e o arquivo não pode crescer sem limite.
        $entry['ms'][] = $ms;
        if (count($entry['ms']) > 200) {
            $entry['ms'] = array_slice($entry['ms'], -200);
        }
        $entry['tokens_in']       = (int) ($entry['tokens_in'] ?? 0) + $tokensIn;
        $entry['tokens_out']      = (int) ($entry['tokens_out'] ?? 0) + $tokensOut;
        $entry['last']            = $now;
        $entry['last_outcome']    = $outcome;
        $entry['last_request_id'] = $requestId;
        $state['providers'][$label] = $entry;
        return $state;
    });
}

function metrics_file_snapshot(): array
{
    if (!is_file(METRICS_FILE)) {
        return [];
    }
    $raw   = @file_get_contents(METRICS_FILE);
    $state = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    if (($state['window'] ?? 0) !== (int) floor(time() / METRICS_WINDOW_SECONDS)) {
        return []; // janela expirada, nada útil
    }
    return metrics_aggregate($state['providers'] ?? []);
}

// =====================================================================
// AGREGAÇÃO
// =====================================================================

function metrics_aggregate(array $providers): array
{
    $out = [];
    foreach ($providers as $label => $entry) {
        $statusCounts = $entry['status'] ?? [];
        $total        = 0;
        $errors       = 0;
        foreach ($statusCounts as $code => $count) {
            $total += $count;
            if ((int) $code === 0 || (int) $code >= 400) {
                $errors += $count;
            }
        }
        $ms = $entry['ms'] ?? [];
        sort($ms);
        $out[$label] = [
            'total'           => $total,
            'errors'          => $errors,
            'error_rate'      => $total > 0 ? round($errors / $total, 4) : 0,
            'status'          => $statusCounts,
            'p50_ms'          => metrics_percentile($ms, 0.5),
            'p95_ms'          => metrics_percentile($ms, 0.95),
            'tokens_in'       => (int) ($entry['tokens_in'] ?? 0),
            'tokens_out'      => (int) ($entry['tokens_out'] ?? 0),
            'last_at'         => (int) ($entry['last'] ?? 0),
            'last_outcome'    => (string) ($entry['last_outcome'] ?? ''),
            'last_request_id' => (string) ($entry['last_request_id'] ?? ''),
        ];
    }
    return $out;
}

function metrics_percentile(array $sorted, float $p): int
{
    if ($sorted === []) {
        return 0;
    }
    return (int) $sorted[(int) floor($p * (count($sorted) - 1))];
}

/** Renderiza o snapshot no formato pedido (json ou prometheus text format). */
function metrics_render(array $snapshot, string $format): string
{
    if ($format !== 'prometheus') {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    $lines = [
        '# HELP localrouter_attempts_total Total de tentativas por provedor.',
        '# TYPE localrouter_attempts_total counter',
        '# HELP localrouter_errors_total Total de erros por provedor.',
        '# TYPE localrouter_errors_total counter',
        '# HELP localrouter_latency_p95_ms Latencia p95 em ms por provedor.',
        '# TYPE localrouter_latency_p95_ms gauge',
        '# HELP localrouter_tokens_total Tokens contabilizados por provedor.',
        '# TYPE localrouter_tokens_total counter',
    ];
    foreach ($snapshot as $label => $metric) {
        $tags    = metrics_prom_label($label);
        $lines[] = "localrouter_attempts_total{$tags} {$metric['total']}";
        $lines[] = "localrouter_errors_total{$tags} {$metric['errors']}";
        $lines[] = "localrouter_latency_p95_ms{$tags} {$metric['p95_ms']}";
        $lines[] = 'localrouter_tokens_total' . metrics_prom_label($label, null, 'input') . ' ' . $metric['tokens_in'];
        $lines[] = 'localrouter_tokens_total' . metrics_prom_label($label, null, 'output') . ' ' . $metric['tokens_out'];
        foreach ($metric['status'] as $code => $count) {
            $lines[] = 'localrouter_status_total' . metrics_prom_label($label, (string) $code) . ' ' . $count;
        }
    }
    return implode("\n", $lines) . "\n";
}

/** Monta {provider="x"} com status ou direction opcionais, escapando o formato. */
function metrics_prom_label(string $label, ?string $status = null, ?string $direction = null): string
{
    $escape = static fn (string $value): string => str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    $parts  = ['provider="' . $escape($label) . '"'];
    if ($status !== null) {
        $parts[] = 'status="' . $escape($status) . '"';
    }
    if ($direction !== null) {
        $parts[] = 'direction="' . $escape($direction) . '"';
    }
    return '{' . implode(',', $parts) . '}';
}
