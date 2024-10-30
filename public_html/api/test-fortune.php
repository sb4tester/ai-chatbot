<?php
// /home/bot.dailymu.com/public_html/api/test-fortune-system.php

require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/DatabaseHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';
require_once __DIR__ . '/../../private/src/TagHandler.php';
require_once __DIR__ . '/../../private/src/FortuneHandler.php';

header('Content-Type: application/json');

try {
    // 1. ทดสอบสร้าง User
    $userHandler = new UserHandler();
    $testUser = $userHandler->getOrCreateUser('test', 'test_user_1');
    
    // 2. เพิ่มข้อมูลพื้นฐาน
    $userHandler->updateUser($testUser['id'], [
        'nickname' => 'คนทดสอบ',
        'birth_date' => '1990-01-01',
        'zodiac' => 'ราศีมังกร'
    ]);

    // 3. เพิ่ม tags
    $tagHandler = new TagHandler();
    $tagHandler->addTag($testUser['id'], 'relationship_status', 'โสด', [
        'category' => 'relationship',
        'confidence' => 1.0
    ]);
    
    // 4. ทดสอบดูดวง
    $fortuneHandler = new FortuneHandler();
    
    $results = [
        'user' => $testUser,
        'fortune_tests' => [
            'daily' => $fortuneHandler->getDailyFortune($testUser['id']),
            'zodiac' => $fortuneHandler->getZodiacFortune($testUser['id']),
            'tarot' => $fortuneHandler->getTarotFortune($testUser['id'], 'ดวงความรักเดือนนี้จะเป็นอย่างไร')
        ],
        'history' => $fortuneHandler->getFortuneHistory($testUser['id'])
    ];

    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}