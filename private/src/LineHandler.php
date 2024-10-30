<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/DialogflowHandler.php';

use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;

class LineHandler {
    private $bot;
    private $dialogflow;

    public function __construct() {
        $httpClient = new CurlHTTPClient(Config::LINE_CHANNEL_TOKEN);
        $this->bot = new LINEBot($httpClient, ['channelSecret' => Config::LINE_CHANNEL_SECRET]);
        $this->dialogflow = new DialogflowHandler();
    }

    public function handleWebhook() {
        // Get request body and signature
        $body = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

        // Validate signature
        if (!$this->validateSignature($body, $signature)) {
            http_response_code(400);
            error_log('Invalid signature');
            return;
        }

        // Parse events
        $events = json_decode($body, true)['events'] ?? [];
        
        foreach ($events as $event) {
            $this->handleEvent($event);
        }
    }

    private function handleEvent($event) {
        if ($event['type'] !== 'message' || $event['message']['type'] !== 'text') {
            return;
        }

        try {
            // Get response from Dialogflow
            $response = $this->dialogflow->detectIntent(
                $event['message']['text'],
                $event['source']['userId']
            );

            // Reply to user
            $this->replyMessage(
                $event['replyToken'],
                $response['text']
            );
        } catch (Exception $e) {
            error_log('Error: ' . $e->getMessage());
        }
    }

    private function replyMessage($replyToken, $message) {
        $messageBuilder = new TextMessageBuilder($message);
        $response = $this->bot->replyMessage($replyToken, $messageBuilder);
        
        if (!$response->isSucceeded()) {
            error_log('Failed to reply message: ' . $response->getHTTPStatus());
        }
    }

    private function validateSignature($body, $signature) {
        if (empty($signature)) {
            return false;
        }
        
        $hash = hash_hmac(
            'sha256',
            $body,
            Config::LINE_CHANNEL_SECRET,
            true
        );
        
        return base64_encode($hash) === $signature;
    }
}
