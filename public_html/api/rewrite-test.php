<?php
// /home/bot.dailymu.com/public_html/api/rewrite-test.php
header('Content-Type: application/json');

echo json_encode([
    'server' => [
        'mod_rewrite' => in_array('mod_rewrite', apache_get_modules()),
        'request_uri' => $_SERVER['REQUEST_URI'],
        'script_name' => $_SERVER['SCRIPT_NAME'],
        'script_filename' => $_SERVER['SCRIPT_FILENAME'],
        'document_root' => $_SERVER['DOCUMENT_ROOT']
    ]
]);