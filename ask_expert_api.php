<?php
/**
 * ask_expert_api.php
 * Streaming SSE API wrapper for the Expert engine.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require 'ask_expert.php';

set_time_limit(0);
ignore_user_abort(false);

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$parts = [];
$conversationHistory = [];

if (isset($payload['conversationHistory']) && is_array($payload['conversationHistory'])) {
    $conversationHistory = $payload['conversationHistory'];
}

if (isset($payload['contents'][0]['parts']) && is_array($payload['contents'][0]['parts'])) {
    foreach ($payload['contents'][0]['parts'] as $part) {
        $parts[] = $part;
    }
}

if (empty($parts)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Empty message content']);
    exit;
}

/**
 * Compatibility mode:
 * - stream=true => SSE streaming response
 * - stream missing/false => JSON response (legacy clients)
 */
$streamRequested = false;
if (isset($payload['stream'])) {
    $streamRequested = filter_var($payload['stream'], FILTER_VALIDATE_BOOLEAN);
} else {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'text/event-stream') !== false) {
        $streamRequested = true;
    }
}

if (!$streamRequested) {
    header('Content-Type: application/json');
    try {
        $text = askExpert($parts, $conversationHistory, false, null);
        echo json_encode([
            'text' => $text,
            // Keep Gemini-like structure for legacy frontend compatibility.
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text]
                        ]
                    ]
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function sseSendComment($message)
{
    echo ': ' . $message . "\n\n";
    @flush();
}

function sseSendData($data)
{
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

$startTime = microtime(true);
$firstTokenAt = null;
$charCount = 0;

sseSendComment('start');

try {
    $stream = askExpert($parts, $conversationHistory, true, function ($msg) {
        sseSendComment($msg);
    });

    if (is_iterable($stream)) {
        foreach ($stream as $part) {
            if (connection_aborted()) {
                error_log('🛑 Client disconnected - streaming stopped');
                break;
            }

            $textChunk = '';
            if (is_string($part)) {
                $textChunk = $part;
            } elseif (is_object($part) && method_exists($part, 'text')) {
                $textChunk = (string) $part->text();
            }

            if ($textChunk === '' || trim($textChunk) === '') {
                continue;
            }

            if ($firstTokenAt === null) {
                $firstTokenAt = microtime(true);
            }

            $charCount += mb_strlen($textChunk, 'UTF-8');
            sseSendData(['text' => $textChunk]);
        }
    } elseif (is_string($stream)) {
        $charCount = mb_strlen($stream, 'UTF-8');
        $firstTokenAt = microtime(true);
        sseSendData(['text' => $stream]);
    } else {
        sseSendData(['error' => 'Unexpected response type from AI']);
    }

    if (!connection_aborted()) {
        echo "data: [DONE]\n\n";
        @flush();
    }

    $endTime = microtime(true);
    $ttft = $firstTokenAt !== null ? round(($firstTokenAt - $startTime) * 1000) : null;
    $totalMs = round(($endTime - $startTime) * 1000);

    error_log('📊 SSE KPI | TTFT(ms): ' . ($ttft ?? 'n/a') . ' | Total(ms): ' . $totalMs . ' | Chars: ' . $charCount);
} catch (Exception $e) {
    error_log('❌ STREAMING ERROR: ' . $e->getMessage());
    sseSendData(['error' => $e->getMessage()]);
}
