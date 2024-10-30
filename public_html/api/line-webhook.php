<?php
// /home/bot.dailymu.com/public_html/api/line-webhook.php

require_once __DIR__ . '/../../private/vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/FortuneHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';

use GuzzleHttp\Client;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\Configuration;
use LINE\Clients\MessagingApi\Model\TextMessage;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;
use LINE\Constants\MessageType;
use LINE\Parser\SignatureValidator;

class LineWebhook {
    private MessagingApiApi $messagingApi;
    private FortuneHandler $fortune;
    private UserHandler $user;

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
    }

    public function handleRequest() {
        try {
            // Debug: Log all headers
            $headers = getallheaders();
            error_log("All headers: " . json_encode($headers));

            // Get signature from different possible sources
            $signature = $headers['X-Line-Signature'] 
                     ?? $headers['x-line-signature']
                     ?? $_SERVER['HTTP_X_LINE_SIGNATURE']
                     ?? '';

            error_log("Signature found: " . $signature);

            // Get request body
            $body = file_get_contents("php://input");
            error_log("Request body: " . $body);

            // For GET requests (webhook verification)
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                return $this->handleVerification();
            }

            // Skip signature validation for empty requests
            if (empty($body)) {
                return $this->handleVerification();
            }

            // Validate signature if we have both body and signature
            if (!empty($signature)) {
                if (!SignatureValidator::validateSignature($body, Config::LINE_CHANNEL_SECRET, $signature)) {
                    error_log("Invalid signature validation");
                    http_response_code(400);
                    return ['error' => 'Invalid signature validation'];
                }
            } else {
                error_log("No signature found for non-empty request");
                return $this->handleVerification();
            }

            // Parse webhook body
            $events = json_decode($body, true)['events'] ?? [];
            $this->processEvents($events);

            return ['status' => 'ok'];

        } catch (Exception $e) {
            error_log("Error in webhook: " . $e->getMessage());
            http_response_code(500);
            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    private function handleVerification() {
        return [
            'status' => 'ready',
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Webhook URL verified'
        ];
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
            error_log("Handling message event: " . json_encode($event));

            $userId = $event['source']['userId'];
            $message = $event['message']['text'];
            $replyToken = $event['replyToken'];

            // Get or create user
            $user = $this->user->getOrCreateUser('line', $userId);

            // Process message
            $reply = $this->processFortuneCommand($message, $user['id']);

            // Create and send reply
            $textMessage = (new TextMessage())
                ->setType(MessageType::TEXT)
                ->setText($reply);

            $request = (new ReplyMessageRequest())
                ->setReplyToken($replyToken)
                ->setMessages([$textMessage]);

            $this->messagingApi->replyMessage($request);
            error_log("Message sent successfully");

        } catch (Exception $e) {
            error_log("Error in handleMessage: " . $e->getMessage());
        }
    }

    private function processFortuneCommand($message, $userId) {
        try {
            // ดูดวงรายวัน
            if (strpos($message, 'ดูดวงวันนี้') !== false || 
                strpos($message, 'ดวงวันนี้') !== false) {
                $fortune = $this->fortune->getDailyFortune($userId);
                return $this->formatDailyFortune($fortune);
            }

            // ดูดวงราศี
            if (strpos($message, 'ดูดวงราศี') !== false) {
                $zodiac = $this->extractZodiac($message);
                if ($zodiac) {
                    $fortune = $this->fortune->getZodiacFortune($userId, $zodiac);
                    return $this->formatZodiacFortune($fortune);
                }
                return "กรุณาระบุราศีที่ต้องการดูดวยค่ะ เช่น 'ดูดวงราศีกันย์' 🌟";
            }

            // ดูดวงไพ่
            if (strpos($message, 'ดูไพ่') !== false || 
                strpos($message, 'เปิดไพ่') !== false) {
                $fortune = $this->fortune->getTarotFortune($userId, $message);
                return $this->formatTarotFortune($fortune);
            }

            // คำสั่งไม่ตรง
            return "สวัสดีค่ะ สามารถเลือกดูดวงได้ดังนี้:\n" .
                   "1. พิมพ์ 'ดูดวงวันนี้' เพื่อดูดวงประจำวัน\n" .
                   "2. พิมพ์ 'ดูดวงราศี...' เพื่อดูดวงตามราศี\n" .
                   "3. พิมพ์ 'ดูไพ่' เพื่อดูดวงด้วยไพ่ทาโรต์\n" .
                   "✨ มิระยินดีให้คำทำนายค่ะ ✨";

        } catch (Exception $e) {
            error_log("Error in processFortuneCommand: " . $e->getMessage());
            return "ขออภัยค่ะ ระบบขัดข้อง กรุณาลองใหม่อีกครั้งนะคะ 🙏";
        }
    }

    private function extractZodiac($message) {
        $zodiacMap = [
            'ราศีเมษ', 'ราศีพฤษภ', 'ราศีเมถุน', 'ราศีกรกฎ',
            'ราศีสิงห์', 'ราศีกันย์', 'ราศีตุลย์', 'ราศีพิจิก',
            'ราศีธนู', 'ราศีมังกร', 'ราศีกุมภ์', 'ราศีมีน'
        ];

        foreach ($zodiacMap as $zodiac) {
            if (strpos($message, $zodiac) !== false) {
                return $zodiac;
            }
        }
        return null;
    }

    private function formatDailyFortune($fortune) {
        return "🔮 ดวงประจำวันของคุณ\n\n" .
               "📝 ดวงโดยรวม: {$fortune['overall']}\n\n" .
               "❤️ ความรัก: {$fortune['aspects']['love']}\n" .
               "💼 การงาน: {$fortune['aspects']['work']}\n" .
               "💰 การเงิน: {$fortune['aspects']['finance']}\n" .
               "🏥 สุขภาพ: {$fortune['aspects']['health']}\n\n" .
               "🎲 เลขนำโชค: {$fortune['lucky']['numbers']}\n" .
               "🎨 สีมงคล: " . implode(", ", array_keys($fortune['lucky']['colors'])) . "\n\n" .
               "💫 คำแนะนำ: {$fortune['advice']}";
    }

    private function formatZodiacFortune($fortune) {
        return "🌟 ดวงชะตาราศี {$fortune['zodiac']}\n\n" .
               "ธาตุ: {$fortune['element']}\n" .
               "{$fortune['description']}\n\n" .
               "การดูดวง:\n" .
               "🌞 ดวงโดยรวม: {$fortune['readings']['overall']}\n" .
               "❤️ ความรัก: {$fortune['readings']['love']}\n" .
               "💼 การงาน: {$fortune['readings']['work']}\n" .
               "💰 การเงิน: {$fortune['readings']['finance']}\n" .
               "🏥 สุขภาพ: {$fortune['readings']['health']}\n\n" .
               "🎲 เลขมงคล: " . implode(", ", $fortune['lucky']['numbers']) . "\n" .
               "🎨 สีมงคล: " . implode(", ", $fortune['lucky']['colors']) . "\n\n" .
               "⭐ อิทธิพลดวงดาว: {$fortune['planetary']}";
    }

    private function formatTarotFortune($fortune) {
        $message = "🎴 ไพ่ทาโรต์ของคุณ\n\n";
        foreach ($fortune['cards'] as $card) {
            $reversed = $card['card']['is_reversed'] ? "(คว่ำ)" : "(หงาย)";
            $message .= "🃏 {$card['position']}\n";
            $message .= "ไพ่: {$card['card']['name']} {$reversed}\n";
            $message .= "ความหมาย: {$card['card']['meaning']}\n\n";
        }
        $message .= "✨ สรุปการพยากรณ์:\n{$fortune['overall_meaning']}";
        return $message;
    }
}

// Handle webhook
$webhook = new LineWebhook();
$result = $webhook->handleRequest();
echo json_encode($result);