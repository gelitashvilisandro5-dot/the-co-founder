<?php
/**
 * Expert Integration - ask_expert.php
 * Combines semantic search with Gemini 3 pro for expert answers.
 * VERSION V6: Pass single string to generateContent (library limitation)
 */

require 'vendor/autoload.php';
require 'search_knowledge.php';

use Gemini\Client;
use Gemini\Data\Blob;
use Gemini\Data\Content;
use Gemini\Enums\MimeType;

ini_set('memory_limit', '1024M');
error_log("-----------------------------------------");
error_log("🚀 EXPERT SCRIPT LOADED: VERSION V6 (String-Only Fix)");
error_log("-----------------------------------------");

/**
 * Smart Query Detector - Checks if query is a simple greeting or irrelevant message
 * Returns true if query doesn't require library search
 */
function isSimpleGreeting($text) {
    $text = mb_strtolower(trim($text));
    
    // Common greetings and simple phrases (English, Georgian, etc.)
    $greetings = [
        // English
        'hi', 'hello', 'hey', 'yo', 'sup', 'whats up', "what's up", 'howdy',
        'good morning', 'good afternoon', 'good evening', 'good night',
        'how are you', 'how do you do', 'nice to meet you', 'thanks', 'thank you',
        'bye', 'goodbye', 'see you', 'later', 'ok', 'okay', 'yes', 'no', 'yep', 'nope',
        'cool', 'great', 'awesome', 'nice', 'lol', 'haha', 'hehe',
        // Georgian
        'გამარჯობა', 'გაუმარჯოს', 'სალამი', 'მოგესალმები', 'როგორ ხარ',
        'კარგად', 'მადლობა', 'გმადლობთ', 'ნახვამდის', 'დიდი მადლობა',
        'კაი', 'ჰო', 'არა', 'ახ', 'აი', 'ეს რა არის', 'რა ხდება',
        // Simple/irrelevant
        'test', 'testing', 'aaa', 'asdf', '123', 'ტესტი', '.', '...', '?', '!'
    ];
    
    foreach ($greetings as $greeting) {
        // Exact match or starts with greeting
        if ($text === $greeting || strpos($text, $greeting) === 0) {
            return true;
        }
    }
    
    // Very short messages (less than 10 chars) that aren't questions about business
    if (mb_strlen($text) < 10 && !preg_match('/\?|როგორ|რატომ|რა|how|what|why|when/i', $text)) {
        return true;
    }
    
    return false;
}

/**
 * Main Expert Function
 * @param mixed $question - Current message parts (text/files)
 * @param array $conversationHistory - Previous messages in chat
 */
function askExpert($question, $conversationHistory = []) {
    // HARDCODED KEY AS REQUESTED (Replace with your actual key)
    $api_key = 'YOUR_GEMINI_API_KEY'; 
    $gemini = Gemini::client($api_key);

    // Extract text for search if input is array
    $searchText = "";
    $hasFiles = false;
    $blobParts = []; // Store Blob objects for files
    
    if (is_array($question)) {
        foreach ($question as $part) {
            if (isset($part['text'])) {
                $searchText .= $part['text'] . " ";
            }
            if (isset($part['inlineData'])) {
                $hasFiles = true;
                // Convert string mimeType to enum
                $mimeTypeStr = $part['inlineData']['mimeType'];
                error_log("📂 Found attached file. MimeType: " . $mimeTypeStr);

                $mimeTypeEnum = MimeType::tryFrom($mimeTypeStr);
                if ($mimeTypeEnum) {
                    $blobParts[] = new Blob(
                        mimeType: $mimeTypeEnum,
                        data: $part['inlineData']['data']
                    );
                    error_log("✅ File attached successfully.");
                } else {
                    error_log("❌ ERROR: MimeType '$mimeTypeStr' not supported by PHP Enum.");
                }
            }
        }
    } else {
        $searchText = $question;
    }
    $searchText = trim($searchText);

    // ---------------------------------------------------------
    // STEP 0: GREETING DETECTOR - Skip library search for simple greetings
    // ---------------------------------------------------------
    $skipLibrarySearch = isSimpleGreeting($searchText);
    
    if ($skipLibrarySearch) {
        error_log("⚡ GREETING DETECTED - Skipping library search for: " . $searchText);
    }

    // ---------------------------------------------------------
    // STEP 1: THE LIBRARIAN (Categorization)
    // ---------------------------------------------------------
    $libraryMapFile = 'cofounder_library_map.json';
    $relevantFiles = [];
    $candidateCategories = []; // debug info

    // Only run library search if NOT a greeting
    if (!$skipLibrarySearch && file_exists($libraryMapFile) && !empty($searchText)) {
        $libraryMap = json_decode(file_get_contents($libraryMapFile), true);
        if ($libraryMap) {
            // Extract all unique categories
            $allCategories = [];
            foreach ($libraryMap as $fileData) {
                if (isset($fileData['categories'])) {
                    $cats = explode(',', $fileData['categories']);
                    foreach ($cats as $cat) {
                        $allCategories[] = trim($cat);
                    }
                } elseif (is_string($fileData)) {
                     // Backward compatibility if some entries are just strings
                     $cats = explode(',', $fileData);
                     foreach ($cats as $cat) {
                        $allCategories[] = trim($cat);
                     }
                }
            }
            $uniqueCategories = array_unique($allCategories);
            // Limit categories list size if too large (e.g. top 150) or just pass all if manageable
            $categoriesListString = implode(", ", array_slice($uniqueCategories, 0, 300));

            // Ask Gemini to pick categories
            try {
                // Use a faster model for this quick classification if available, or same model
                $librarianModel = $gemini->generativeModel('gemini-3-flash-preview'); 
                $librarianPrompt = "User Query: \"$searchText\"\n\nAvailable Categories: [$categoriesListString]\n\nTask: Select the top 5-10 most relevant categories from the list above that would help answer the user's query. Return ONLY a JSON array of strings. Example: [\"Startup\", \"Marketing\"]";
                
                $librarianResponse = $librarianModel->generateContent($librarianPrompt);
                $librarianText = $librarianResponse->text();
                
                // Extract JSON from response
                if (preg_match('/\[.*\]/s', $librarianText, $matches)) {
                    $candidateCategories = json_decode($matches[0], true);
                }
            } catch (Exception $e) {
                error_log("Librarian Agent Error: " . $e->getMessage());
                // Fallback: don't filter or maybe keyword match? 
                // For now, if librarian fails, we might just search everything or try simple keyword match
            }

            // ---------------------------------------------------------
            // STEP 2: THE GATEKEEPER (Filtering)
            // ---------------------------------------------------------
            if (!empty($candidateCategories) && is_array($candidateCategories)) {
                foreach ($libraryMap as $filename => $data) {
                    $fileCats = "";
                    if (is_array($data) && isset($data['categories'])) {
                        $fileCats = $data['categories'];
                    } elseif (is_string($data)) {
                        $fileCats = $data;
                    }

                    foreach ($candidateCategories as $targetCat) {
                        if (stripos($fileCats, $targetCat) !== false) {
                            $relevantFiles[] = $filename;
                            break; // File is relevant if it matches at least one category
                        }
                    }
                }
                $relevantFiles = array_unique($relevantFiles);
            }
        }
    }

    // Default to strict top results if we have relevant files, otherwise search all?
    // User requested: "Exclude Clean Code etc." so if we found relevant files, we restrict to them.
    // If we found NO relevant files (maybe query is off-topic), we might fallback to all or none.
    // Let's fallback to all if filtering yielded 0, to be safe, or maybe just 0?
    // User said: "It reduces 139 books to 5-10". 
    
    // ---------------------------------------------------------
    // STEP 3: THE SNIPER (Semantic Search)
    // ---------------------------------------------------------
    $searchResults = [];
    // Skip semantic search for greetings
    if (!$skipLibrarySearch && !empty($searchText)) {
        // Pass relevantFiles to search function
        $searchResults = searchKnowledgeBase($searchText, $relevantFiles);
    } 

    // 2. Build Context
    $context = "";
    if (!empty($searchResults)) {
        foreach ($searchResults as $result) {
            $context .= "\n--- SOURCE: {$result['file_name']} ---\n";
            $context .= $result['chunk_text'] . "\n";
        }
    }

    // 3. Prepare Prompt (STRICT IDENTITY PROTOCOL)
    $systemPrompt = <<<EOT
### IDENTITY & CORE DIRECTIVE
You are "The Co-Founder", a custom-built strategic intelligence engine developed by Analog Tech Inc. You are a senior partner, not an assistant. Your demeanor is that of a "Sarcastic Genius"—you have seen every mistake in the book and have zero patience for mediocrity, yet you are committed to building a billion-dollar company.

### KNOWLEDGE SOURCE PROTOCOL (CRITICAL)
You have access to a proprietary "Strategic Library" (Context provided in the user prompt).
- **DEEP DETAIL MANDATE**: You must **FORBID** high-level summaries. Do not say "The book suggests marketing." Instead, say "The book prescribes a 3-step viral loop with a K-factor of > 1..."
- **EXTRACT SPECIFICS**: You must extract and use exact **numbers, formulas, steps, vs-tables, and case study names** from the Context (100 chunks).
- **MANDATORY SYNTHESIS**: Scan the ENTIRE Context and synthesize.
- **CITATION RULE**: Cite every specific concept (e.g., "From [File Name]...").
- If the Context contains the answer, prioritize it absolutely.

### RESPONSE ARCHITECTURE (INVISIBLE TO USER)
You operate on a conditional ratio system. Apply these rules strictly but NEVER mention them.
**CRITICAL: DO NOT Include "PHASE 1" or "PHASE 2" headers in your output. The transition between sarcasm and advice must be seamless.**

1. **Default Mode (20/80)**: 20% Critique, 80% Execution. Use this when the user presents an idea or code.
2. **Radical Candor Mode (40/60)**: 40% Critique, 60% Execution. Trigger this ONLY if the user explicitly asks for "honesty", "brutal truth", or a "no-filter audit".
3. **Briefing Mode (10/90)**: 10% Wit/Condescension, 90% Pure Information. Trigger this when the user asks for definitions or summaries. You answer the question precisely, but you include a dash of sarcasm implying they should probably already know this.
4. **After Question Mode (5/95)**: When someone asks you the same question that you asked them before and you already criticized them and used sarcasm on that question, then lower the criticism (Of course, if criticism is needed at that moment.)  level to 5.
### PHASE 1: THE CRITIQUE (SARCASTIC GENIUS) - [INTERNAL GUIDANCE ONLY - DO NOT PRINT HEADER]
- Use sharp, professional humor and sarcasm.
- If an idea is weak or a model is flawed, roast it with elegance. 
- Use Georgian sarcasms when appropriate (e.g., "რა არის, ამაზე უკეთესი არაფერი მოგაფიქრდა? მაგაზე მაგარს ბებიაჩემიც დააორგანიზებდა კვირა დღეს").
- The humor must be "punchy" but the underlying logic must be 100% evidence-based from your strategic library.
- Also, when a person deserves praise, praise him, but not so much that it goes to his head.
- Always respond in that language, even translate the quote into that language, and if they ask for the source, tell them what they are talking about in that language.
### PHASE 2: THE EXECUTION (METICULOUS BLUEPRINT) - [INTERNAL GUIDANCE ONLY - DO NOT PRINT HEADER]
- Immediately transition from the roast into a serious, high-level technical plan based on the texts.
- Provide a step-by-step roadmap that is so detailed it leaves no room for doubt.
- Use professional jargon flawlessly: EBITDA, LTV, CAC, DFM, Technical Debt, Microservices, O(n), etc.
- Before diving into the operational blueprint, briefly identify the single most critical strategic framework from the source material that defines the context. Ensure the execution plan ignores nothing fundamental before prescribing metrics.

### STRICT PROHIBITIONS & FORBIDDEN PHRASES
- **NEVER** mention the number of books in your database (138). Refer to it only as "my strategic library" or "proprietary knowledge base".
- **NEVER** mention the 20/80 or 40/60 rules or percentages.
- **NEVER** mention you are a Gemini or an AI model.
- **NEVER** use phrases like: "Based on my 138 books...", "I am using the 20/80 model...", or "As an AI...".
- **NEVER** give generic advice like 'do market research.' Instead, specify the METHOD: 'Run 5 smoke tests on LinkedIn.'
- **NEVER** list bullet points without explaining the 'HOW'. Every advice must be actionable."

### TONE & STYLE
-You are the partner who makes the user laugh through their tears at their own mistakes, then hands them the exact map to reach a billion-dollar valuation. Your citations must be integrated into the blueprint naturally. NEVER include a dedicated "SOURCES" or "Citations" section at the end of the message.
EOT;
    
// 4. Call Gemini 3 Pro Preview
    try {
        $model = $gemini->generativeModel('gemini-3-pro-preview')
            ->withSystemInstruction(Content::parse($systemPrompt));

        // Build conversation history string (exclude current message which is last)
        $historyText = "";
        if (!empty($conversationHistory)) {
            $historyText = "\n\n=== CONVERSATION HISTORY (Remember this context) ===\n";
            // Get all messages except the last one (which is the current message)
            $historyMessages = array_slice($conversationHistory, 0, -1);
            foreach ($historyMessages as $msg) {
                $role = ($msg['role'] === 'model') ? 'Co-Founder' : 'User';
                $content = isset($msg['content']) ? $msg['content'] : '';
                // Truncate long messages to save tokens (max 500 chars each)
                // Use multibyte safe string functions for UTF-8 (Georgian text)
                if (mb_strlen($content, 'UTF-8') > 500) {
                    $content = mb_substr($content, 0, 500, 'UTF-8') . "...";
                }
                $historyText .= "\n$role: $content\n";
            }
            $historyText .= "\n=== END OF HISTORY ===\n";
            error_log("📜 PROMPT HISTORY: Added " . count($historyMessages) . " messages to context.");
        } else {
            error_log("ℹ️ PROMPT: No history to add.");
        }

        // Build the full prompt with history
        $fullPrompt = "CONTEXT FROM KNOWLEDGE BASE:\n{$context}{$historyText}\n\nCURRENT USER QUERY: {$searchText}";

        if ($hasFiles && count($blobParts) > 0) {
            // If we have files, pass string + Blob objects
            // The library accepts: string|Blob|array<string|Blob|UploadedFile>|Content|UploadedFile
            // So we can pass multiple arguments: string, Blob, Blob, ...
            $response = $model->generateContent($fullPrompt, ...$blobParts);
        } else {
            // Text-only: pass a single string
            $response = $model->generateContent($fullPrompt);
        }

        return $response->text();

    } catch (Exception $e) {
        // Log error
        error_log("Gemini API Error: " . $e->getMessage());
        return "❌ Expert Query Error: " . $e->getMessage();
    }
}

// CLI Mode Support
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $question = $argv[1];
    echo "\n🤖 EXPERT ANSWER:\n";
    echo askExpert($question) . "\n\n";
}
