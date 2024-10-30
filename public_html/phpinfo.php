<?php
header('Content-Type: application/json');

$user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : ['name' => 'N/A'];

echo json_encode([
    'server' => [
        'php_user' => $user['name'],
        'document_root' => $_SERVER['DOCUMENT_ROOT'],
        'script_path' => __FILE__,
        'os' => PHP_OS
    ],
    'file_access' => [
        'api_dir' => is_readable(__DIR__),
        'api_dir_contents' => scandir(__DIR__),
        'private_dir_exists' => is_dir(dirname(dirname(__DIR__)) . '/private'),
    ]
], JSON_PRETTY_PRINT);