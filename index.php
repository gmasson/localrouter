<?php
declare(strict_types=1);

/**
 * LocalRouter — gateway de IA com API no formato OpenAI e rotação
 * automática de provedores. Projeto open-source sob licença MIT.
 *
 * Este arquivo só carrega as partes na ordem certa e despacha para a web
 * ou para o terminal. Toda a configuração fica em config.php.
 */

define('LOCALROUTER', '0.3');

// LR_CONFIG aponta para outro config.php (permite apontar o gateway para
// uma configuração alternativa sem editar este arquivo).
// Sem a variável, vale o config.php ao lado deste arquivo.
require (getenv('LR_CONFIG') ?: __DIR__ . '/config.php');
require __DIR__ . '/src/providers.php';
require __DIR__ . '/src/formats.php';
require __DIR__ . '/src/streaming.php';
require __DIR__ . '/src/metrics.php';
require __DIR__ . '/src/gateway.php';

// Terminal: utilitários de configuração, sem tocar na parte web.
// cli.php só é carregado em runtime CLI — o bootstrap web nunca o inclui.
if (PHP_SAPI === 'cli') {
    require __DIR__ . '/src/cli.php';
    cli_entry($GLOBALS['argv'] ?? []);
    exit;
}

// ---------------------------------------------------------------------
// Bootstrap web
// ---------------------------------------------------------------------

ini_set('display_errors', '0');          // erro nunca vaza caminho de arquivo para o cliente
ini_set('zlib.output_compression', '0'); // compressão quebraria o streaming
error_reporting(E_ALL);
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Mantém o script rodando após o cliente fechar a conexão para o curl ser
// abortado de forma limpa (connection_aborted() só é confiável com flush
// ativo; sem isto, em alguns SAPIs a chamada ao provedor fica pendurada
// até o timeout). A detecção de desconexão encerra a chamada ao provedor.
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
