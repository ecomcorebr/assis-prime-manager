<?php
if ( PHP_SAPI !== 'cli' ) {
    if ( ! defined( 'ABSPATH' ) ) exit;
    http_response_code( 404 );
    exit;
}
/**
 * Build determinístico do bundle administrativo.
 * Uso: php tools/build-admin-js.php
 */
$root = dirname(__DIR__);
$src = $root . '/assets/js/src';
$out = $root . '/assets/js/admin.js';
$files = glob($src . '/*.js');
sort($files, SORT_STRING);
$bundle = '';
foreach ($files as $file) {
    $bundle .= file_get_contents($file);
    if (!str_ends_with($bundle, "\n")) $bundle .= "\n";
}
// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- CLI build tool only; never executed within WordPress.
file_put_contents($out, $bundle);
echo "admin.js gerado com " . count($files) . " módulos.\n";
