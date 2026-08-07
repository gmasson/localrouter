<?php
/**
 * LocalRouter — catálogo de provedores (carregado pelo config.php)
 *
 * Este arquivo é incluído por config.php via:
 *   define('PROVIDERS', require __DIR__ . '/providers.php');
 *
 * A função env() (definida em config.php) já está disponível no momento
 * do include, pois o require acontece depois da declaração dela.
 *
 * Campos de cada entrada:
 *   url         -> URL da API (com ou sem a rota final — o router ajusta)
 *   type        -> 'openai' (padrão, pode omitir) ou 'anthropic'
 *   key         -> string ou array de strings (várias contas do mesmo serviço)
 *   rpd         -> opcional: teto de requisições por dia daquela conta
 *   retries     -> opcional (padrão 0): reconexões no MESMO host quando a
 *                  falha parece cold start de serverless (conexão recusada,
 *                  5xx de proxy ou 404 "route not found" — caso da Modal)
 *   retry_delay -> opcional (padrão 10): segundos entre as reconexões
 *
 * O nome é livre: use sufixos para contas diferentes,
 * porque o log, o cooldown e o header X-Router-Provider passam
 * a distinguir cada uma. A chave fica aqui e não em MODELS porque pertence
 * ao provedor; MODELS pode sobrescrever quando um modelo precisar de outra.
 *
 * O resto do catálogo (não carregado por padrão) está em
 * providers-extra.php.example: copie a linha e cole aqui.
 */

return [

    'openrouter' => [
        'url' => 'https://openrouter.ai/api/v1', 
        'key' => [
            env('OPENROUTER_KEY')
        ]
    ],

    'nvidia' => [
        'url' => 'https://integrate.api.nvidia.com/v1',
        'key' => [
            env('NVIDIA_API_KEY')
        ]
    ],

    'opencode' => [
        'url' => 'https://opencode.ai/zen/v1',
        'key' => [
            env('OPENCODE_KEY')
        ]
    ],

    'opencode_go' => [
        'url' => 'https://opencode.ai/zen/go/v1',
        'key' => [
            env('OPENCODE_KEY')
        ]
    ],

    // Mesmos endpoints, dialeto Anthropic (traduzido por dentro).
    'opencode_anthropic' => [
        'url' => 'https://opencode.ai/zen/v1',
        'type' => 'anthropic',
        'key' => [
            env('OPENCODE_KEY')
        ]
    ],

    // API nativa da Anthropic.
    'anthropic' => [
        'url' => 'https://api.anthropic.com/v1',
        'type' => 'anthropic',
        'key' => [
            env('ANTHROPIC_API_KEY')
        ]
    ],

    // Ollama rodando na sua própria máquina: sem chave, sem limite.
    'local' => [
        'url' => 'http://127.0.0.1:11434/v1'
    ],

];
