<?php
// /home/bot.dailymu.com/private/src/OpenAIHandler.php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/CacheHandler.php';

class OpenAIHandler {
    private $apiKey;
    private $model;
    private $cache;

    public function __construct() {
        $this->apiKey = Config::OPENAI_API_KEY;
        $this->model = Config::OPENAI_MODEL;
        $this->cache = new CacheHandler();
    }

    public function getResponse($message, $sessionId = null) {
        try {
            // Try cache first
            $cacheKey = "openai_" . md5($message);
            $cachedResponse = $this->cache->get($cacheKey);
            
            if ($cachedResponse !== null) {
                return $cachedResponse;
            }

            // Prepare request
            $data = [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system', 
                        'content' => 'You are a helpful assistant. Please respond in Thai language.'
                    ],
                    [
                        'role' => 'user', 
                        'content' => $message
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.7,
                'top_p' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0
            ];

            // Make API request
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception('Curl error: ' . curl_error($ch));
            }
            
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception('OpenAI API Error: ' . $response);
            }

            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            $reply = $result['choices'][0]['message']['content'] ?? 'ขออภัย ไม่สามารถประมวลผลได้';

            // Cache the response
            $this->cache->set($cacheKey, $reply);

            return $reply;

        } catch (Exception $e) {
            error_log("OpenAI Error: " . $e->getMessage());
            return "ขออภัย มีข้อผิดพลาดในการเชื่อมต่อกับ AI: " . $e->getMessage();
        }
    }

    // Helper method to test connection
    public function testConnection() {
        try {
            $response = $this->getResponse("ทดสอบภาษาไทย");
            return [
                'success' => true,
                'response' => $response
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}