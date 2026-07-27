<?php
declare(strict_types=1);

/**
 * LocalRouter — utilitarios de linha de comando.
 *
 * So e carregado quando PHP_SAPI === 'cli' (ver index.php). Assim o
 * bootstrap web nunca paga o custo destas funcoes. Depende de duas
 * funcoes puras que continuam em gateway.php: placeholder_key() e
 * resolve_provider(). Ambas leem apenas as constantes de config.php.
 *
 *   php index.php genkey   -> gera uma chave para colar em GATEWAY_KEYS
 *   php index.php check    -> valida a configuracao (sai com 1 se houver problema)
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

// =====================================================================
// LINHA DE COMANDO (php index.php genkey | check)
// =====================================================================

function cli_entry(array $argv): void
{
    $command = $argv[1] ?? '';

    if ($command === 'genkey') {
        echo 'sk-lr-' . bin2hex(random_bytes(16)) . PHP_EOL;
        return;
    }
    if ($command === 'check') {
        exit(cli_check());
    }
    echo "LocalRouter — comandos:\n";
    echo "  php index.php genkey            gera uma chave para colar em GATEWAY_KEYS\n";
    echo "  php index.php check             valida a configuracao (sai com 1 se houver problema)\n";
}

/** Percorre a configuracao apontando os erros mais comuns. */
function cli_check(): int
{
    $problems = 0;
    $warn     = static function (string $message) use (&$problems): void {
        echo '[!] ' . $message . "\n";
        $problems++;
    };

    if (GATEWAY_KEYS === []) {
        $warn('GATEWAY_KEYS esta vazio.');
    }
    foreach (GATEWAY_KEYS as $key) {
        if ((string) $key === placeholder_key()) {
            $warn('GATEWAY_KEYS ainda contem a chave de exemplo. Rode: php index.php genkey');
        } elseif (strlen((string) $key) < 20) {
            $warn('Chave do gateway muito curta; gere uma com php index.php genkey.');
        }
    }

    if (PROVIDERS === []) {
        $warn('Nenhum provedor configurado em PROVIDERS.');
    }
    foreach (PROVIDERS as $pname => $pcfg) {
        $ptype = (string) ($pcfg['type'] ?? 'openai');
        if (!in_array($ptype, ['openai', 'anthropic'], true)) {
            $warn("PROVIDERS '{$pname}': type deve ser 'openai' ou 'anthropic' (default 'openai').");
        }
        $purl = (string) ($pcfg['url'] ?? '');
        if ($purl === '') {
            $warn("PROVIDERS '{$pname}': url vazia.");
        }
    }
    if (MODELS === []) {
        $warn('Nenhum modelo configurado em MODELS.');
    }

    foreach (MODELS as $name => $config) {
        $entries = is_array($config['providers'] ?? null) ? $config['providers'] : $config;
        foreach (check_params(is_array($config['params'] ?? null) ? $config['params'] : [], $name) as $message) {
            $warn($message);
        }
        foreach ($entries as $position => $entry) {
            $label    = $name . ' #' . ($position + 1);
            $referred = (string) ($entry['provider'] ?? '');
            if ($referred !== '' && !isset(PROVIDERS[$referred])) {
                $warn($label . ": provider '" . $referred . "' nao existe em PROVIDERS.");
                continue;
            }
            $provider = resolve_provider($entry);
            if ($provider === null) {
                $warn($label . ': entrada incompleta (faltam url ou model).');
                continue;
            }
            if ($referred !== '') {
                $label .= ' (' . $referred . ')';
            }
            $url = (string) ($provider['url'] ?? '');
            if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
                $warn($label . ': url sem protocolo.');
            } elseif (str_starts_with($url, 'http://') && preg_match('#^http://(127\.|localhost)#', $url) !== 1) {
                $warn($label . ': url em http puro fora de localhost — a chave viaja exposta.');
            }
            if (preg_match('#/(chat/completions|messages)/?$#', $url) === 1) {
                $warn($label . ': url deve ser a BASE do provedor, sem a rota final.');
            }
            if (($provider['key'] ?? '') === '') {
                echo '[?] ' . $label . ": key vazia — defina a chave ou a variavel de ambiente do provedor.\n";
            }
            if (isset($provider['weight']) && (!is_numeric($provider['weight']) || (int) $provider['weight'] < 1)) {
                $warn($label . ': weight deve ser inteiro >= 1.');
            }
            foreach (check_params(is_array($entry['params'] ?? null) ? $entry['params'] : [], $label) as $message) {
                $warn($message);
            }
        }
    }
    foreach (MODEL_FALLBACKS as $from => $to) {
        if (!isset(MODELS[$to])) {
            $warn("MODEL_FALLBACKS: '" . $from . "' aponta para modelo inexistente '" . $to . "'.");
        }
    }

    foreach (check_params(DEFAULT_PARAMS, 'DEFAULT_PARAMS') as $message) {
        $warn($message);
    }

    echo $problems === 0 ? "Configuracao ok.\n" : $problems . " problema(s) encontrado(s).\n";
    return $problems === 0 ? 0 : 1;
}

/** Confere nomes e faixas dos parametros; devolve a lista de problemas. */
function check_params(array $params, string $where): array
{
    $known = array_merge(
        ['temperature', 'top_p', 'top_k', 'max_tokens', 'stop', 'stop_sequences'],
        PASSTHROUGH_OPENAI
    );
    $ranges   = ['temperature' => [0, 2], 'top_p' => [0, 1]];
    $problems = [];

    foreach ($params as $name => $value) {
        if (!in_array((string) $name, $known, true)) {
            $problems[] = $where . ": parametro '" . $name . "' desconhecido — sera ignorado.";
            continue;
        }
        if ($value === null) {
            continue;
        }
        if (isset($ranges[$name]) && (!is_numeric($value) || $value < $ranges[$name][0] || $value > $ranges[$name][1])) {
            $problems[] = $where . ": {$name} deve ficar entre {$ranges[$name][0]} e {$ranges[$name][1]}.";
        }
        if (in_array($name, ['top_k', 'max_tokens'], true) && (!is_numeric($value) || (int) $value < 1)) {
            $problems[] = $where . ": {$name} deve ser inteiro >= 1.";
        }
    }
    return $problems;
}
