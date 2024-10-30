<?php
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/DatabaseHandler.php';

header('Content-Type: application/json');

try {
    $db = DatabaseHandler::getInstance();
    
    // ทดสอบ query
    $tables = $db->query("SHOW TABLES")->fetchAll();
    
    echo json_encode([
        'success' => true,
        'message' => 'Database connected successfully',
        'tables' => $tables
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}