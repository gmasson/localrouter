<?php
declare(strict_types=1);

/**
 * LocalRouter — comandos de terminal. Só carregado em PHP_SAPI 'cli', então
 * o bootstrap web nunca paga por estas funções.
 *
 *   genkey | check | providers | test <modelo> | sync
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo não roda sozinho

function cli_entry(array $argv): void
{
    switch ($argv[1] ?? '') {
        case 'genkey':
            echo 'sk-lr-' . bin2hex(random_bytes(16)) . PHP_EOL;
            return;
        case 'check':
            exit(cli_check());
        case 'providers':
            cli_providers();
            return;
        case 'test':
            exit(cli_test((string) ($argv[2] ?? '')));
        case 'sync':
            exit(cli_sync());
        default:
            echo 'LocalRouter ' . LOCALROUTER . " — comandos:\n";
            echo "  php index.php genkey          gera uma chave para colar em GATEWAY_KEYS\n";
            echo "  php index.php check           valida a configuração (sai com 1 se houver problema)\n";
            echo "  php index.php providers       lista os provedores configurados (sem revelar chaves)\n";
            echo "  php index.php test <modelo>   faz uma chamada real e mostra qual provedor atendeu\n";
            echo "  php index.php sync            confere os ids de modelo contra o catálogo de cada provedor\n";
    }
}

// =====================================================================
// providers — o que está configurado
// =====================================================================

/**
 * Lista os provedores de PROVIDERS: nome, tipo, se há chave e a URL.
 * A chave nunca é impressa — só o fato de existir. 'local' marca os
 * provedores que rodam sem chave por natureza (Ollama e afins).
 */
function cli_providers(): void
{
    if (PROVIDERS === []) {
        echo "Nenhum provedor configurado em PROVIDERS.\n";
        return;
    }

    // Uma sobrescrita pontual de key em MODELS também conta como "tem chave".
    $overridden = [];
    foreach (MODELS as $config) {
        foreach (model_entries($config) as $entry) {
            if (array_key_exists('key', $entry) && cli_has_key($entry['key'])) {
                $overridden[(string) ($entry['provider'] ?? '')] = true;
            }
        }
    }

    $width = max(array_map('strlen', array_keys(PROVIDERS))) + 2;
    printf("%-{$width}s%-12s%-8s%-8s%s\n", 'NOME', 'TIPO', 'CHAVE', 'RPD', 'URL');
    echo str_repeat('-', $width + 48), "\n";
    foreach (PROVIDERS as $name => $catalog) {
        $url = (string) ($catalog['url'] ?? '');
        $key = match (true) {
            provider_is_local($url) => 'local',
            cli_has_key($catalog['key'] ?? '') || isset($overridden[$name]) => 'sim',
            default => 'nao',
        };
        $rpd = (int) ($catalog['rpd'] ?? 0);
        printf("%-{$width}s%-12s%-8s%-8s%s\n", $name, (string) ($catalog['type'] ?? 'openai'), $key, $rpd > 0 ? (string) $rpd : '-', $url);
    }
}

/** Há ao menos uma chave não vazia? Aceita string ou array de strings. */
function cli_has_key(mixed $key): bool
{
    foreach (is_array($key) ? $key : [$key] as $one) {
        if ((string) $one !== '') {
            return true;
        }
    }
    return false;
}

// =====================================================================
// check — validação da configuração
// =====================================================================

/** Percorre a configuração apontando os erros mais comuns. */
function cli_check(): int
{
    $problems = 0;
    $warn     = static function (string $message) use (&$problems): void {
        echo '[!] ' . $message . "\n";
        $problems++;
    };
    $note = static fn (string $message): int => printf("[?] %s\n", $message);

    // --- acesso ao gateway ---
    if (GATEWAY_KEYS === []) {
        $warn('GATEWAY_KEYS está vazio.');
    }
    foreach (GATEWAY_KEYS as $key) {
        if ((string) $key === placeholder_key()) {
            $warn('GATEWAY_KEYS ainda contém a chave de exemplo. Rode: php index.php genkey');
        } elseif (strlen((string) $key) < 20) {
            $warn('Chave do gateway muito curta; gere uma com php index.php genkey.');
        }
    }

    // --- catálogo de provedores ---
    if (PROVIDERS === []) {
        $warn('Nenhum provedor configurado em PROVIDERS.');
    }
    foreach (PROVIDERS as $name => $catalog) {
        if (!in_array((string) ($catalog['type'] ?? 'openai'), ['openai', 'anthropic'], true)) {
            $warn("PROVIDERS '{$name}': type deve ser 'openai' ou 'anthropic' (default 'openai').");
        }
        $url = trim((string) ($catalog['url'] ?? ''));
        if ($url === '') {
            $warn("PROVIDERS '{$name}': url vazia.");
        } elseif (!preg_match('#^https?://#', $url)) {
            $warn("PROVIDERS '{$name}': url sem protocolo.");
        } elseif (str_starts_with($url, 'http://') && !provider_is_local($url)) {
            $warn("PROVIDERS '{$name}': http puro fora de localhost — a chave viaja exposta.");
        }
        if (isset($catalog['rpd']) && (!is_numeric($catalog['rpd']) || (int) $catalog['rpd'] < 1)) {
            $warn("PROVIDERS '{$name}': rpd deve ser inteiro >= 1 (ou omitido).");
        }
    }

    // --- modelos ---
    if (MODELS === []) {
        $warn('Nenhum modelo configurado em MODELS.');
    }
    // Provedor sem chave gera UM aviso por provedor, com a lista de modelos
    // que dependem dele. Antes era um aviso por entrada de modelo, o que
    // enchia a tela de 30 linhas dizendo a mesma coisa de seis maneiras.
    $missingKey = [];

    foreach (MODELS as $name => $config) {
        foreach (check_params(is_array($config['params'] ?? null) ? $config['params'] : [], $name) as $message) {
            $warn($message);
        }
        if (array_key_exists('system_prompt', $config) && !is_string($config['system_prompt'])) {
            $warn($name . ': system_prompt deve ser string (ou omitido).');
        }
        foreach (model_entries($config) as $position => $entry) {
            $label    = 'MODELS ' . $name . ' #' . ($position + 1);
            $referred = (string) ($entry['provider'] ?? '');
            if ($referred !== '' && !isset(PROVIDERS[$referred])) {
                $warn($label . ": provider '{$referred}' não existe em PROVIDERS.");
                continue;
            }
            $expanded = resolve_provider($entry);
            if ($expanded === []) {
                $warn($label . ': entrada incompleta (faltam url ou model).');
                continue;
            }
            // type do modelo (chat/embedding/...) é distinto do dialeto do
            // provedor (openai/anthropic). Embeddings só rodam em dialeto
            // openai — a API Anthropic não tem endpoint de embeddings.
            if (model_type($config) === 'embedding' && $expanded[0]['type'] === 'anthropic') {
                $warn($label . ': a API Anthropic não tem endpoint de embeddings.');
            }
            // type declarado mas fora da allowlist MODEL_TYPES: vira 'chat'
            // em runtime, o que quase nunca é o que o admin quis.
            $declaredType = (string) ($config['type'] ?? 'chat');
            if ($declaredType !== 'chat' && !in_array($declaredType, MODEL_TYPES, true)) {
                $warn($name . ": type '{$declaredType}' não reconhecido (válidos: " . implode(', ', MODEL_TYPES) . "). Tratado como 'chat'.");
            }
            foreach ($expanded as $provider) {
                if ($provider['key'] === '' && !provider_is_local($provider['url'])) {
                    $missingKey[$provider['label']][] = $name;
                }
                if (isset($provider['weight']) && (!is_numeric($provider['weight']) || (int) $provider['weight'] < 1)) {
                    $warn($label . ' ' . $provider['label'] . ': weight deve ser inteiro >= 1.');
                }
                foreach (check_params(is_array($entry['params'] ?? null) ? $entry['params'] : [], $label) as $message) {
                    $warn($message);
                }
            }
        }
    }
    // Provedor remoto sem chave não é erro: em runtime (SKIP_EMPTY_REMOTE_KEY)
    // ele é pulado da fila e o gateway segue com os demais provedores do modelo.
    // Só vira nota informativa — o gateway funciona sem ele.
    $noViable = []; // modelo => bool (tem ao menos 1 provedor viável em runtime?)
    foreach (MODELS as $name => $config) {
        $noViable[$name] = false;
        foreach (model_entries($config) as $entry) {
            foreach (resolve_provider($entry) as $provider) {
                $local = provider_is_local($provider['url']);
                if ($provider['key'] !== '' || $local) {
                    $noViable[$name] = true;
                    break 2;
                }
            }
        }
    }
    foreach ($missingKey as $provider => $models) {
        $unique = array_unique($models);
        $note(sprintf(
            "provedor '%s' sem chave — será ignorado; %d modelo(s) o listam (%s). Defina no data/.env para usá-lo.",
            $provider,
            count($unique),
            implode(', ', array_slice($unique, 0, 4)) . (count($unique) > 4 ? ', ...' : '')
        ));
    }

    // Modelo sem NENHUM provedor viável em runtime (todos sem chave e nenhum
    // local): esse sim é erro — o modelo não tem quem o atenda, toda chamada
    // vai devolver 503 "Nenhum provedor configurado para este modelo".
    foreach ($noViable as $name => $viable) {
        if (!$viable) {
            $warn("modelo '{$name}' sem provedor viável — todos sem chave e nenhum local. Defina uma chave no data/.env ou remova o modelo.");
        }
    }

    foreach (MODEL_FALLBACKS as $from => $to) {
        if (!isset(MODELS[$from])) {
            $warn("MODEL_FALLBACKS: origem '{$from}' não existe em MODELS.");
        }
        if (!isset(MODELS[$to])) {
            $warn("MODEL_FALLBACKS: '{$from}' aponta para modelo inexistente '{$to}'.");
        }
    }

    // --- constantes opcionais ---
    if (!in_array(STRATEGY, ['priority', 'random', 'fastest'], true)) {
        $warn("STRATEGY deve ser 'priority', 'random' ou 'fastest'.");
    }
    if (STRATEGY === 'fastest' && METRICS_BACKEND === 'off') {
        $note("STRATEGY='fastest' precisa de METRICS_BACKEND ligado para ter latência medida; sem isso vale a ordem do array.");
    }
    if (!in_array(METRICS_BACKEND, ['off', 'file'], true)) {
        $warn("METRICS_BACKEND deve ser 'off' ou 'file'.");
    }
    if (!in_array(METRICS_FORMAT, ['json', 'prometheus'], true)) {
        $warn("METRICS_FORMAT deve ser 'json' ou 'prometheus'.");
    }
    if (BREAKER_FAILURES < 0 || RETRY_SAME_PROVIDER < 0 || MAX_ATTEMPTS < 0 || TOTAL_DEADLINE_SECONDS < 0) {
        $warn('BREAKER_FAILURES, RETRY_SAME_PROVIDER, MAX_ATTEMPTS e TOTAL_DEADLINE_SECONDS devem ser >= 0.');
    }
    if (TOTAL_DEADLINE_SECONDS > 0 && TOTAL_DEADLINE_SECONDS < REQUEST_TIMEOUT) {
        $note('TOTAL_DEADLINE_SECONDS menor que REQUEST_TIMEOUT: nenhuma tentativa vai usar o timeout inteiro.');
    }
    if (TOTAL_DEADLINE_SECONDS === 0) {
        $note('TOTAL_DEADLINE_SECONDS=0: o pior caso é MAX_ATTEMPTS x REQUEST_TIMEOUT (' . (MAX_ATTEMPTS ?: 1) * REQUEST_TIMEOUT . 's).');
    }
    if (BREAKER_FAILURES > 0 && COOLDOWN_SECONDS <= 0) {
        $note('BREAKER_FAILURES ativo com COOLDOWN_SECONDS=0 — funciona, mas o cooldown complementar é recomendado.');
    }
    if (REQUIRE_HTTPS === false) {
        $note('REQUIRE_HTTPS desligado: a chave do gateway pode trafegar em texto claro.');
    }
    if (!extension_loaded('curl')) {
        $warn('Extensão ext-curl ausente — o gateway não consegue chamar provedor nenhum.');
    }

    if ($problems === 0) {
        echo "Configuração ok.\n";
        echo "Próximo passo: php index.php test " . (array_key_first(MODELS) ?: '<modelo>') . "\n";
        return 0;
    }
    echo $problems . " problema(s) encontrado(s).\n";
    return 1;
}

/**
 * Confere faixas dos parâmetros conhecidos; devolve a lista de problemas.
 * Parâmetros não reconhecidos NÃO são reclamados: são repassados como
 * 'extra' aos provedores openai, o que permite campos próprios de
 * provedores exóticos (Modal, vLLM). Só validamos os padronizados.
 */
function check_params(array $params, string $where): array
{
    $ranges   = ['temperature' => [0, 2], 'top_p' => [0, 1]];
    $problems = [];

    foreach ($params as $name => $value) {
        if ($value === null) {
            continue;
        }
        if (isset($ranges[$name]) && (!is_numeric($value) || $value < $ranges[$name][0] || $value > $ranges[$name][1])) {
            $problems[] = "{$where}: {$name} deve ficar entre {$ranges[$name][0]} e {$ranges[$name][1]}.";
        }
        if (in_array($name, ['top_k', 'max_tokens'], true) && (!is_numeric($value) || (int) $value < 1)) {
            $problems[] = "{$where}: {$name} deve ser inteiro >= 1.";
        }
    }
    return $problems;
}

// =====================================================================
// test — chamada real, provedor por provedor
// =====================================================================

/**
 * Faz uma chamada de verdade e mostra o que acontece em cada provedor da
 * fila: quem respondeu, em quanto tempo e por que os anteriores falharam.
 * Sem isto o único jeito de testar era montar o curl na mão.
 */
function cli_test(string $model): int
{
    if ($model === '') {
        echo "Uso: php index.php test <modelo>\n";
        echo 'Modelos de chat: ' . implode(', ', array_keys(models_of_type('chat'))) . "\n";
        $embeddings = models_of_type('embedding');
        if ($embeddings !== []) {
            echo 'Embeddings: ' . implode(', ', array_keys($embeddings)) . "\n";
        }
        return 1;
    }

    if (!isset(MODELS[$model])) {
        echo "[!] Modelo '{$model}' não existe em MODELS.\n";
        return 1;
    }
    $embedding = model_type(MODELS[$model]) === 'embedding';

    deadline_start();
    $candidates = $embedding
        ? collect_candidates($model, models_of_type('embedding'), [])
        : collect_candidates($model, models_of_type('chat'), MODEL_FALLBACKS);

    if ($candidates === []) {
        echo "[!] Nenhum provedor disponível para '{$model}'. Rode: php index.php check\n";
        return 1;
    }

    echo "Testando '{$model}' — " . count($candidates) . " provedor(es) na fila.\n\n";

    foreach ($candidates as $index => $candidate) {
        $provider = $candidate['provider'];
        printf('  %d. %-24s %-32s ', $index + 1, $provider['label'], $provider['model']);

        if ($embedding) {
            $provider['endpoint'] = 'embeddings';
            $payload = build_embedding_payload(['input' => 'ping'], $provider['model']);
        } else {
            $request = normalize_openai_request([
                'model'      => $model,
                'messages'   => [['role' => 'user', 'content' => 'Responda apenas: ok']],
                'max_tokens' => 16,
            ]);
            $request = apply_params($request, $candidate['params']);
            $request = apply_system_prompt($request, $candidate['system_prompt']);
            $payload = build_payload($request, $provider);
        }

        $started = microtime(true);
        $result  = call_provider($provider, $payload, null);
        $ms      = (int) round((microtime(true) - $started) * 1000);

        if ($result['status'] === 200) {
            $decoded = json_decode($result['body'], true);
            $ok      = $embedding
                ? embedding_response_is_usable($decoded)
                : is_array($decoded) && canonical_is_usable(
                    $provider['type'] === 'openai'
                        ? canonical_from_openai_response($decoded)
                        : canonical_from_anthropic_response($decoded)
                );
            if ($ok) {
                printf("OK   %5dms\n", $ms);
                echo "\n  Atendido por '{$provider['label']}' em {$ms}ms.\n";
                return 0;
            }
            printf("FALHA %4dms  200 sem resposta utilizável\n", $ms);
            continue;
        }
        printf("FALHA %4dms  %s\n", $ms, failure_reason($result));
    }

    echo "\n[!] Todos os provedores falharam para '{$model}'.\n";
    return 1;
}

// =====================================================================
// sync — os ids de modelo envelhecem
// =====================================================================

/**
 * Pergunta a cada provedor quais modelos ele oferece hoje e compara com os
 * ids escritos em MODELS. Id de modelo que muda é o problema operacional
 * número um deste tipo de gateway: o provedor responde 404 e o router
 * rotaciona em silêncio para o próximo, mais caro.
 */
function cli_sync(): int
{
    // Um provedor pode aparecer em vários modelos; consulta uma vez só.
    $wanted = [];   // provedor => [id de modelo => onde foi declarado]
    foreach (MODELS as $name => $config) {
        foreach (model_entries($config) as $entry) {
            $referred = (string) ($entry['provider'] ?? '');
            if ($referred !== '' && isset(PROVIDERS[$referred]) && ($entry['model'] ?? '') !== '') {
                $wanted[$referred][(string) $entry['model']] = 'MODELS ' . $name;
            }
        }
    }
    if ($wanted === []) {
        echo "Nenhum modelo aponta para um provedor conhecido.\n";
        return 0;
    }

    $problems = 0;
    foreach ($wanted as $name => $models) {
        $provider = resolve_provider(['provider' => $name, 'model' => array_key_first($models)])[0] ?? null;
        if ($provider === null) {
            continue;
        }
        echo "\n" . $name . ':';
        if ($provider['key'] === '' && !provider_is_local($provider['url'])) {
            echo " sem chave, pulando.\n";
            continue;
        }

        $result = get_provider($provider, 'models');
        if ($result['status'] !== 200) {
            // Nem todo provedor expõe /models — não é erro de configuração.
            echo ' não foi possível listar (' . ($result['error'] !== '' ? failure_reason($result) : 'HTTP ' . $result['status']) . ").\n";
            continue;
        }
        $available = [];
        foreach ((array) (json_decode($result['body'], true)['data'] ?? []) as $item) {
            if (isset($item['id'])) {
                $available[(string) $item['id']] = true;
            }
        }
        if ($available === []) {
            echo " catálogo vazio ou em formato desconhecido.\n";
            continue;
        }

        $missing = array_diff_key($models, $available);
        if ($missing === []) {
            echo ' ' . count($models) . " id(s) conferem.\n";
            continue;
        }
        echo ' ' . count($missing) . ' id(s) NÃO existem mais:' . "\n";
        foreach ($missing as $id => $declaredIn) {
            echo "    [!] '{$id}'  (em {$declaredIn})\n";
            $problems++;
        }
    }

    echo "\n" . ($problems === 0
        ? "Todos os ids conferem.\n"
        : $problems . " id(s) para corrigir no config.php.\n");
    return $problems === 0 ? 0 : 1;
}
