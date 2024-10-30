<?php
// public_html/test.php
header('Content-Type: application/json');
echo json_encode([
    'server' => $_SERVER,
    'request_uri' => $_SERVER['REQUEST_URI'],
    'script_filename' => $_SERVER['SCRIPT_FILENAME'],
    'document_root' => $_SERVER['DOCUMENT_ROOT'],
]);
