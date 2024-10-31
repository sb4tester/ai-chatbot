<?php
require_once __DIR__ . '/../../private/vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/OpenAIHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';
require_once __DIR__ . '/../../private/src/TagHandler.php';

// Get test user
$userHandler = new UserHandler();
$testUser = $userHandler->getOrCreateUser('test', 'test_user_1');

// Test daily fortune
$openai = new OpenAIHandler();
$dailyFortune = $openai->getFortunePrediction('daily', $testUser['id']);
echo "Daily Fortune:\n";
print_r($dailyFortune);

// Test zodiac fortune
$zodiacFortune = $openai->getFortunePrediction('zodiac', $testUser['id'], ['zodiac' => 'ราศีมังกร']);
echo "\nZodiac Fortune:\n";
print_r($zodiacFortune);