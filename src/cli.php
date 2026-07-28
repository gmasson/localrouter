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
    if ($command === 'providers') {
        cli_providers();
        return;
    }
    echo "LocalRouter — comandos:\n";
    echo "  php index.php genkey            gera uma chave para colar em GATEWAY_KEYS\n";
    echo "  php index.php check             valida a configuracao (sai com 1 se houver problema)\n";
    echo "  php index.php providers         lista os provedores configurados (sem revelar chaves)\n";
}

/**
 * Lista os provedores configurados em PROVIDERS, mostrando nome, url,
 * tipo e se a chave esta definida (sim/nao/local) — sem revelar a chave.
 * A chave e detectada procurando em MODELS a primeira entrada que
 * referencia o provedor; se nenhuma referencia, mostra 'nao'.
 */
function cli_providers(): void
{
    if (PROVIDERS === []) {
        echo "Nenhum provedor configurado em PROVIDERS.\n";
        return;
    }

    // Mapa: nome do provedor => tem chave? (procura em MODELS)
    $keyStatus = [];
    foreach (PROVIDERS as $name => $catalog) {
        $keyStatus[$name] = 'nao';
    }
    foreach (MODELS as $config) {
        $entries = is_array($config['providers'] ?? null) ? $config['providers'] : $config;
        foreach ($entries as $entry) {
            $pname = (string) ($entry['provider'] ?? '');
            if ($pname === '' || !isset($keyStatus[$pname])) {
                continue;
            }
            $key = (string) ($entry['key'] ?? '');
            if ($key !== '' && $keyStatus[$pname] === 'nao') {
                $keyStatus[$pname] = 'sim';
            }
        }
    }

    echo str_pad('NOME', 20) . str_pad('TIPO', 10) . str_pad('CHAVE', 8) . "URL\n";
    echo str_repeat('-', 70) . "\n";
    foreach (PROVIDERS as $name => $catalog) {
        $url  = (string) ($catalog['url'] ?? '');
        $type = (string) ($catalog['type'] ?? 'openai');
        $key  = provider_is_local($url) ? 'local' : $keyStatus[$name];
        echo str_pad($name, 20) . str_pad($type, 10) . str_pad($key, 8) . $url . "\n";
    }
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
        } elseif (preg_match('#/(chat/completions|messages)/?$#', $purl) === 1) {
            // Verifica tambem provedores nao referenciados em MODELS —
            // antes so o loop de MODELS pegava este erro, deixando
            // provedores orfaos com URL errada passar em silencio.
            $warn("PROVIDERS '{$pname}': url deve ser a BASE, sem a rota final.");
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
        if (array_key_exists('system_prompt', $config) && !is_string($config['system_prompt'])) {
            $warn($name . ": system_prompt deve ser string (ou omitido).");
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
                // Provedor remoto sem chave e erro de configuracao (a chamada
                // vai falhar 401); provedor local (Ollama, LM Studio) sem
                // chave e legitimo — so aviso.
                if (provider_is_local($provider['url'])) {
                    echo '[?] ' . $label . ": key vazia — ok para provedor local.\n";
                } else {
                    $warn($label . ': key vazia em provedor remoto — a chamada vai falhar.');
                }
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

    // Validacao das novas constantes opcionais.
    if (!in_array(METRICS_BACKEND, ['off', 'file', 'sqlite'], true)) {
        $warn("METRICS_BACKEND deve ser 'off', 'file' ou 'sqlite'.");
    }
    if (METRICS_BACKEND === 'sqlite' && !extension_loaded('pdo_sqlite')) {
        echo "[?] METRICS_BACKEND='sqlite' mas ext-pdo_sqlite nao carregada — vai cair em 'file'.\n";
    }
    if (!in_array(METRICS_FORMAT, ['json', 'prometheus'], true)) {
        $warn("METRICS_FORMAT deve ser 'json' ou 'prometheus'.");
    }
    if (BREAKER_FAILURES < 0) {
        $warn('BREAKER_FAILURES deve ser >= 0.');
    }
    if (BREAKER_FAILURES > 0 && COOLDOWN_SECONDS <= 0) {
        echo "[?] BREAKER_FAILURES ativo mas COOLDOWN_SECONDS=0 — o breaker funciona sem cooldown, mas o cooldown complementar e recomendado.\n";
    }
    if (RETRY_SAME_PROVIDER < 0) {
        $warn('RETRY_SAME_PROVIDER deve ser >= 0.');
    }
    if (LOG_FILE !== '' && str_ends_with(LOG_FILE, '/router.log')) {
        $warn('LOG_FILE ainda aponta para router.log (legado). Considere data/localrouter.log.');
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
