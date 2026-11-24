<?php
if ( PHP_SAPI !== 'cli' ) {
    if ( ! defined( 'ABSPATH' ) ) exit;
    http_response_code( 404 );
    exit;
}
/** Build determinístico do CSS administrativo. */
$root = dirname(__DIR__);
$src = $root . '/assets/css/src';
$out = $root . '/assets/css/admin.css';
$files = glob($src . '/*.css');
sort($files, SORT_STRING);
$bundle = '';
foreach ($files as $file) {
    $bundle .= file_get_contents($file);
    if (!str_ends_with($bundle, "\n")) $bundle .= "\n";
}
// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- CLI build tool only; never executed within WordPress.
file_put_contents($out, $bundle);
echo "admin.css gerado com " . count($files) . " módulos.\n";
