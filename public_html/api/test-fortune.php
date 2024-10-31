<?php
require_once __DIR__ . '/../../private/vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/DialogflowHandler.php';
require_once __DIR__ . '/../../private/src/OpenAIHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';

// สร้าง test user
$userHandler = new UserHandler();
$testUser = $userHandler->getOrCreateUser('test', 'test_user_1');

// อัพเดทข้อมูล user
$userData = [
    'nickname' => 'คนทดสอบ',
    'birth_date' => '1990-01-01',
    'zodiac' => 'ราศีมังกร'
];
$userHandler->updateUser($testUser['id'], $userData);

// ทดสอบดูดวงรายวัน
$dialogflow = new DialogflowHandler();
$result = $dialogflow->detectIntent('ดูดวงวันนี้', $testUser['id']);
echo "ผลการทำนายดวงรายวัน:\n";
print_r($result);

// ทดสอบดูดวงราศี
$result = $dialogflow->detectIntent('ดูดวงราศีมังกร', $testUser['id']);
echo "\nผลการทำนายดวงราศี:\n";
print_r($result);