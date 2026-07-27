<?php
declare(strict_types=1);

/**
 * LocalRouter
 * Projeto open-source sob licenca MIT.
 *
 * Gateway de IA com API no formato OpenAI e rotacao automatica de
 * provedores (dialetos openai e anthropic) para nunca parar de responder.
 *
 * Endpoints:
 *   POST /chat/completions  -> conclui usando o primeiro provedor saudavel
 *   GET  /models            -> lista os modelos configurados
 *   GET  /health            -> monitoramento; sem autenticacao
 *
 * Terminal:
 *   php index.php genkey       -> gera uma chave para GATEWAY_KEYS
 *   php index.php check        -> valida a configuracao
 *
 * A configuracao inteira fica em config.php. Este arquivo so carrega as
 * partes na ordem certa e despacha para a web ou para a linha de comando.
 */

define('LOCALROUTER', '1.0.0');

require __DIR__ . '/config.php';
require __DIR__ . '/src/providers.php';
require __DIR__ . '/src/formats.php';
require __DIR__ . '/src/streaming.php';
require __DIR__ . '/src/gateway.php';

// Terminal: utilitarios de configuracao, sem tocar na parte web.
// cli.php so e carregado em runtime CLI — o bootstrap web nunca o inclui.
if (PHP_SAPI === 'cli') {
    require __DIR__ . '/src/cli.php';
    cli_entry($GLOBALS['argv'] ?? []);
    exit;
}

// ---------------------------------------------------------------------
// Bootstrap web
// ---------------------------------------------------------------------

ini_set('display_errors', '0');          // erro nunca vaza caminho de arquivo para o cliente
ini_set('zlib.output_compression', '0'); // compressao quebraria o streaming
error_reporting(E_ALL);
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Mantem o script rodando apos o cliente fechar a conexao para o curl ser
// abortado de forma limpa (connection_aborted() so e confiavel com flush
// ativo; sem isto, em alguns SAPIs a chamada ao provedor fica pendurada
// ate o timeout). O deteccao de desconexao encerra a chamada ao provedor.
ignore_user_abort(true);

// Falha inesperada nunca vira stack trace na resposta.
set_exception_handler(static function (Throwable $exception): void {
    error_log('localrouter: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['message' => 'Erro interno do gateway.', 'type' => 'internal_error', 'code' => 500]]);
    }
});

main();
