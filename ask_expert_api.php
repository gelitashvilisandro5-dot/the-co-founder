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
$input = json_decode($inputJSON, true);

// Extract the user message
// The frontend sends { contents: [{ parts: [{ text: "..." }] }] }
$userText = '';
if (isset($input['contents'][0]['parts'])) {
    foreach ($input['contents'][0]['parts'] as $part) {
        if (isset($part['text'])) {
            $userText .= $part['text'] . " ";
        }
    }
}

$userText = trim($userText);

if (empty($userText)) {
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// Call the expert logic
// Note: We use ob_start to catch the "Searching..." echo and discard it or log it
ob_start();
$expertAnswer = askExpert($userText);
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
