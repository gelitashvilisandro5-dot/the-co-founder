<?php
/**
 * ask_expert_api.php
 * Web API wrapper for the Expert Reasoning engine.
 * Mimics the Gemini API response structure for frontend compatibility.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require 'ask_expert.php';

// Get input
$inputJSON = file_get_contents('php://input');
// LOG RAW INPUT TO SEE EXACTLY WHAT IS SENT
error_log("🔍 RAW API INPUT: " . substr($inputJSON, 0, 1000)); // Log first 1000 chars

$input = json_decode($inputJSON, true);

// Extract the user message parts (Text + Files)
// The frontend sends { contents: [{ parts: [...] }], conversationHistory: [...] }
$parts = [];
$userTextForSearch = '';
$conversationHistory = [];

// Extract conversation history if provided
if (isset($input['conversationHistory']) && is_array($input['conversationHistory'])) {
    $conversationHistory = $input['conversationHistory'];
    error_log("🧠 API RECEIVED HISTORY: " . count($conversationHistory) . " messages");
} else {
    error_log("⚠️ API: No conversation history received");
}

if (isset($input['contents'][0]['parts'])) {
    foreach ($input['contents'][0]['parts'] as $part) {
        // Collect all parts (text and inlineData) to pass to Gemini
        $parts[] = $part;

        // Extract just text for RAG search
        if (isset($part['text'])) {
            $userTextForSearch .= $part['text'] . " ";
        }
    }
}

$userTextForSearch = trim($userTextForSearch);

// If no text, we can't search RAG, but might still have an image.
// If both empty, error.
if (empty($parts)) {
    echo json_encode(['error' => 'Empty message content']);
    exit;
}

// Call the expert logic with parts AND conversation history
ob_start();
$expertAnswer = askExpert($parts, $conversationHistory);
ob_end_clean();

// Format response to look like Gemini API for frontend compatibility
$response = [
    'candidates' => [
        [
            'content' => [
                'parts' => [
                    ['text' => $expertAnswer]
                ],
                'role' => 'model'
            ],
            'finishReason' => 'STOP',
            'index' => 0
        ]
    ],
    'usageMetadata' => [
        'promptTokenCount' => 0,
        'candidatesTokenCount' => 0,
        'totalTokenCount' => 0
    ]
];

echo json_encode($response);
