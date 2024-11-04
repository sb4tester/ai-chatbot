<?php
// /home/bot.dailymu.com/public_html/api/line-webhook.php

require_once __DIR__ . '/../../private/vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/FortuneHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';
require_once __DIR__ . '/../../private/src/DialogflowHandler.php';

use GuzzleHttp\Client;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Configuration;
use LINE\Constants\MessageType;
use LINE\Parser\SignatureValidator;
// เพิ่ม imports ที่จำเป็น
use LINE\Clients\MessagingApi\Model\TextMessage;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;
use LINE\Clients\MessagingApi\Model\QuickReply;
use LINE\Clients\MessagingApi\Model\QuickReplyItem;
use LINE\Clients\MessagingApi\Model\MessageAction;


class LineWebhook {
    private MessagingApiApi $messagingApi;
    private FortuneHandler $fortune;
    private UserHandler $user;
    private DialogflowHandler $dialogflow; // เพิ่ม

    public function __construct() {
        // Initialize LINE API client
        $client = new Client();
        $config = new Configuration();
        $config->setAccessToken(Config::LINE_CHANNEL_ACCESS_TOKEN);
        
        $this->messagingApi = new MessagingApiApi(
            client: $client,
            config: $config
        );

        // Initialize handlers
        $this->fortune = new FortuneHandler();
        $this->user = new UserHandler();
        $this->dialogflow = new DialogflowHandler('line'); // เพิ่ม
    }

    public function handleRequest() {
        try {
            // Debug: Log all headers
            $headers = getallheaders();
            error_log("All headers: " . json_encode($headers));

            // Get signature
            $signature = $headers['X-Line-Signature'] 
                     ?? $headers['x-line-signature']
                     ?? $_SERVER['HTTP_X_LINE_SIGNATURE']
                     ?? '';

            error_log("Signature found: " . $signature);

            // Get request body
            $body = file_get_contents("php://input");
            error_log("Request body: " . $body);

            // Skip signature validation for empty requests
            if (empty($body)) {
                return $this->handleVerification();
            }

            // Validate signature
            if (!empty($signature)) {
                if (!SignatureValidator::validateSignature($body, Config::LINE_CHANNEL_SECRET, $signature)) {
                    error_log("Invalid signature validation");
                    http_response_code(400);
                    return ['error' => 'Invalid signature validation'];
                }
            }

            // Parse webhook body
            $events = json_decode($body, true)['events'] ?? [];
            $this->processEvents($events);

            return ['status' => 'ok'];

        } catch (Exception $e) {
            error_log("Error in webhook: " . $e->getMessage());
            http_response_code(500);
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    private function processEvents(array $events) {
        error_log("Processing events: " . json_encode($events));
        
        foreach ($events as $event) {
            if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
                $this->handleMessage($event);
            }
        }
    }

    private function handleMessage(array $event) {
    try {
        if ($event['deliveryContext']['isRedelivery']) {
            error_log("Skip redelivery message");
            return;
        }

        $userId = $event['source']['userId'];
        $message = $event['message']['text'];
        $replyToken = $event['replyToken'];

        // Get LINE Profile
        $profile = $this->messagingApi->getProfile($userId);
        $displayName = $profile->getDisplayName();

        // Get or create user with displayName
        $user = $this->user->getOrCreateUser('line', $userId, ['nickname' => $displayName]);

        // Process message through Dialogflow
        $result = $this->dialogflow->detectIntent($message, $userId);
        error_log("Dialogflow result: " . json_encode($result));

        if (isset($result['text'])) {
            $this->replyMessage($replyToken, $result['text']);
        } else {
            error_log("No text response from Dialogflow");
        }

    } catch (Exception $e) {
        error_log("Error in handleMessage: " . $e->getMessage());
    }
}
/*

    private function handleMessage(array $event) {
    try {
        if ($event['deliveryContext']['isRedelivery']) {
            error_log("Skip redelivery message");
            return;
        }

        $userId = $event['source']['userId'];
        $message = $event['message']['text'];
        $replyToken = $event['replyToken'];

        error_log("Processing message: " . $message . " from user: " . $userId);

        $user = $this->user->getOrCreateUser('line', $userId);
        $result = $this->dialogflow->detectIntent($message, $userId);
        error_log("Dialogflow result: " . json_encode($result));

        if (isset($result['text'])) {
            $messages = [];
            
            // ข้อความหลัก
            $messages[] = new TextMessage([
                'type' => MessageType::TEXT,
                'text' => $result['text']
            ]);

            // ถ้ามีข้อความ follow up (คำทำนาย)
            if (isset($result['followed_by'])) {
                $messages[] = new TextMessage([
                    'type' => MessageType::TEXT,
                    'text' => $result['followed_by']
                ]);
            }

            // ส่งทั้งข้อความหลักและคำทำนาย
            $request = new ReplyMessageRequest([
                'replyToken' => $replyToken,
                'messages' => $messages
            ]);

            $this->messagingApi->replyMessage($request);
        } else {
            error_log("No text response from Dialogflow");
        }

    } catch (Exception $e) {
        error_log("Error in handleMessage: " . $e->getMessage());
    }
}
*/


    private function replyMessage($replyToken, $text) {
        try {
            $message = new TextMessage([
                'type' => MessageType::TEXT,
                'text' => $text
            ]);

            $request = new ReplyMessageRequest([
                'replyToken' => $replyToken,
                'messages' => [$message]
            ]);

            $this->messagingApi->replyMessage($request);
        } catch (Exception $e) {
            error_log("Error in replyMessage: " . $e->getMessage());
        }
    }

    private function handleVerification() {
        return [
            'status' => 'ready',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

// Create instance and handle request
$webhook = new LineWebhook();
$result = $webhook->handleRequest();
echo json_encode($result);