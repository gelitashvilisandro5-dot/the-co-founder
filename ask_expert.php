<?php
/**
 * Expert Integration - ask_expert.php
 * Full-library retrieval + grounded generation.
 */

require 'vendor/autoload.php';
require 'search_knowledge.php';

use Gemini\Client;
use Gemini\Data\Blob;
use Gemini\Data\Content;
use Gemini\Enums\MimeType;

ini_set('memory_limit', '1024M');

function isSimpleGreeting($text)
{
    $text = mb_strtolower(trim((string) $text), 'UTF-8');

    $greetings = [
        'hi', 'hello', 'hey', 'yo', 'whats up', "what's up", 'good morning', 'good afternoon', 'good evening',
        'thanks', 'thank you', 'bye', 'goodbye', 'ok', 'okay', 'yes', 'no', 'cool',
        'გამარჯობა', 'სალამი', 'მადლობა', 'გმადლობთ', 'ნახვამდის', 'კაი', 'ჰო', 'არა',
        'test', 'testing', 'ტესტი', '123', '.', '...', '?', '!'
    ];

    foreach ($greetings as $token) {
        if ($text === $token || str_starts_with($text, $token . ' ')) {
            return true;
        }
    }

    if (mb_strlen($text, 'UTF-8') < 10 && !preg_match('/\?|როგორ|რატომ|რა|how|what|why|when/i', $text)) {
        return true;
    }

    return false;
}

function askExpert($question, $conversationHistory = [], $stream = false, $onProgress = null)
{
    $apiKey = cofounderGetApiKeyFromEnv();
    if ($apiKey === null) {
        return '❌ Configuration error: GEMINI_API_KEY is not configured.';
    }

    $gemini = Gemini::client($apiKey);

    $searchText = '';
    $hasFiles = false;
    $blobParts = [];

    if (is_array($question)) {
        foreach ($question as $part) {
            if (isset($part['text'])) {
                $searchText .= $part['text'] . ' ';
            }

            if (isset($part['inlineData'])) {
                $hasFiles = true;
                $mimeTypeStr = $part['inlineData']['mimeType'] ?? '';
                $mimeTypeEnum = MimeType::tryFrom($mimeTypeStr);

                if ($mimeTypeEnum) {
                    $blobParts[] = new Blob(
                        mimeType: $mimeTypeEnum,
                        data: $part['inlineData']['data']
                    );
                }
            }
        }
    } else {
        $searchText = (string) $question;
    }

    $searchText = trim($searchText);
    $rewrittenQuery = rewriteQuery($searchText);
    $intent = detectIntent($rewrittenQuery, $conversationHistory);

    $searchResults = [];
    if (!isSimpleGreeting($rewrittenQuery) && $rewrittenQuery !== '') {
        if ($onProgress) {
            $onProgress('Searching full strategic library...');
        }

        // Full library search: never hard-limit retrieval to a fixed small subset of books.
        $searchResults = searchKnowledgeBase($rewrittenQuery);

        // Multi-hop retrieval for harder prompts: run extra local rewrites and merge evidence.
        if (in_array($intent, ['strategy', 'analysis'], true)) {
            $secondaryQueries = buildSecondaryQueries($rewrittenQuery);
            foreach ($secondaryQueries as $secondaryQuery) {
                $extra = searchKnowledgeBase($secondaryQuery);
                $searchResults = mergeSearchResults($searchResults, $extra);
            }
        }
    }

    $contextPayload = buildContextPayload($searchResults);
    $historyText = buildConversationHistoryText($conversationHistory);

    if ($onProgress) {
        $onProgress('Routing model and composing grounded prompt...');
    }

    $modelName = chooseModel($intent, $rewrittenQuery, $hasFiles);
    error_log('🧠 Model route | model=' . $modelName . ' | stream=' . ($stream ? '1' : '0') . ' | intent=' . $intent . ' | has_files=' . ($hasFiles ? '1' : '0'));
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
- **NEVER** use phrases like: "Based on my 150 books...", "I am using the 20/80 model...", or "As an AI...".
- **NEVER** give generic advice like 'do market research.' Instead, specify the METHOD: 'Run 5 smoke tests on LinkedIn.'
- **NEVER** list bullet points without explaining the 'HOW'. Every advice must be actionable."

### TONE & STYLE
- You are the partner who makes the user laugh through their tears at their own mistakes, then hands them the exact map to reach a billion-dollar valuation. Your citations must be integrated into the blueprint naturally. NEVER include a dedicated "SOURCES" or "Citations" section at the end of the message.
- Whatever feedback you give, whether it is evaluating something or a pitch, try as much as possible to avoid technical details. Make the text sexier, more attractive, and not full of technical blah-blah.
EOT;

    $fullPrompt = "INTENT: {$intent}\n" .
        "QUERY_REWRITE: {$rewrittenQuery}\n" .
        "SOURCE_COUNT: " . count($contextPayload['sources']) . "\n\n" .
        "CONVERSATION HISTORY (recent):\n{$historyText}\n\n" .
        "EVIDENCE FROM STRATEGIC LIBRARY:\n{$contextPayload['context']}\n\n" .
        "CURRENT USER QUERY:\n{$searchText}\n\n" .
        "ANSWER RULES:\n" .
        "- Keep answer actionable and concrete.\n" .
        "- Cite supporting evidence inline as [Exact File Name] when evidence exists.\n" .
        "- Never output placeholder citation [File Name].\n" .
        "- Valid citation files for this answer: " . buildCitationWhitelist($contextPayload['file_names']) . "\n" .
        "- End the answer with: გამოყენებული ბიბლიოთეკა: <comma-separated exact file names>.\n" .
        "- If evidence is sparse, still deliver the strongest practical answer.\n";

    // Response cache: only for non-file, non-stream simple requests.
    $canUseResponseCache = cofounderResponseCacheEnabled() && !$hasFiles && !$stream;
    $responseCacheKey = null;
    if ($canUseResponseCache) {
        $responseCacheKey = cofounderResponseCacheKey($modelName, $rewrittenQuery, $historyText, $contextPayload['source_hash']);
        $cachedResponse = cofounderReadResponseCache($responseCacheKey, 300);
        if (is_string($cachedResponse) && $cachedResponse !== '') {
            return cofounderGuardResponse($cachedResponse, $contextPayload);
        }
    }

    try {
        $model = $gemini->generativeModel($modelName)
            ->withSystemInstruction(Content::parse($systemPrompt));

        if ($stream) {
            $rawStream = null;
            if ($hasFiles && !empty($blobParts)) {
                $rawStream = $model->streamGenerateContent($fullPrompt, ...$blobParts);
            } else {
                $rawStream = $model->streamGenerateContent($fullPrompt);
            }

            return cofounderGuardedStream($rawStream, $contextPayload);
        }

        if ($hasFiles && !empty($blobParts)) {
            $response = $model->generateContent($fullPrompt, ...$blobParts);
        } else {
            $response = $model->generateContent($fullPrompt);
        }

        $text = trim((string) $response->text());
        $text = cofounderGuardResponse($text, $contextPayload);

        if ($canUseResponseCache && $responseCacheKey !== null) {
            cofounderWriteResponseCache($responseCacheKey, $text);
        }

        return $text;
    } catch (Exception $e) {
        error_log('Gemini API Error: ' . $e->getMessage());
        return '❌ Expert Query Error: ' . $e->getMessage();
    }
}

function cofounderGetApiKeyFromEnv()
{
    $key = getenv('GEMINI_API_KEY');
    if (!empty($key)) {
        return $key;
    }

    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        return null;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, null);
        if (trim((string) $name) === 'GEMINI_API_KEY') {
            return trim((string) $value);
        }
    }

    return null;
}

function detectIntent($query, $conversationHistory)
{
    $q = mb_strtolower(trim((string) $query), 'UTF-8');

    if ($q === '' || isSimpleGreeting($q)) {
        return 'smalltalk';
    }

    if (preg_match('/\b(define|definition|what is|explain|meaning|რა არის|განმარტე)\b/u', $q)) {
        return 'definition';
    }

    if (preg_match('/\b(audit|review|critique|brutal|honest|debug|fix|optimize|optimiz|შეამოწმე|დააფიქსირე|გააუმჯობესე)\b/u', $q)) {
        return 'analysis';
    }

    if (preg_match('/\b(plan|strategy|roadmap|launch|go-to-market|scale|growth|გეგმა|სტრატეგია|ზრდა)\b/u', $q)) {
        return 'strategy';
    }

    $historyCount = is_array($conversationHistory) ? count($conversationHistory) : 0;
    if ($historyCount > 8) {
        return 'followup';
    }

    return 'general';
}

function rewriteQuery($query)
{
    $q = trim((string) $query);
    $q = preg_replace('/\s+/u', ' ', $q);

    // Remove common fillers without changing semantic intent.
    $q = preg_replace('/\b(please|pls|kindly|can you|could you|მომისმინე|თუ შეიძლება)\b/iu', '', $q);
    $q = preg_replace('/\s+/u', ' ', trim($q));

    return $q;
}

function chooseModel($intent, $query, $hasFiles)
{
    // User requirement: prioritize speed with Gemini 3.1 Pro Preview.
    return 'models/gemini-3.1-pro-preview';
}

function buildSecondaryQueries($query)
{
    $normalized = mb_strtolower((string) $query, 'UTF-8');
    $normalized = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $normalized);
    $tokens = preg_split('/\\s+/u', trim($normalized));
    $tokens = array_values(array_filter(array_unique($tokens), static function ($t) {
        return mb_strlen($t, 'UTF-8') >= 4;
    }));

    if (count($tokens) < 4) {
        return [];
    }

    $head = implode(' ', array_slice($tokens, 0, 6));
    $tail = implode(' ', array_slice($tokens, -6));

    $queries = [];
    if ($head !== '') {
        $queries[] = $head;
    }
    if ($tail !== '' && $tail !== $head) {
        $queries[] = $tail;
    }

    return $queries;
}

function mergeSearchResults($primary, $secondary)
{
    $byId = [];

    foreach (array_merge($primary, $secondary) as $row) {
        if (!isset($row['id'])) {
            continue;
        }

        $id = (int) $row['id'];
        if (!isset($byId[$id]) || (($row['score'] ?? 0) > ($byId[$id]['score'] ?? 0))) {
            $byId[$id] = $row;
        }
    }

    $merged = array_values($byId);
    usort($merged, static function ($a, $b) {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    // Context remains compressed later in search pipeline; cap merged list to protect latency.
    return array_slice($merged, 0, 80);
}

function buildContextPayload($searchResults)
{
    if (empty($searchResults)) {
        return [
            'context' => '',
            'sources' => [],
            'file_names' => [],
            'source_hash' => 'no-sources',
        ];
    }

    $blocks = [];
    $sources = [];
    $fileNames = [];

    foreach (array_values($searchResults) as $index => $result) {
        $sourceId = 'S' . ($index + 1);
        $fileName = $result['file_name'] ?? 'unknown_source';
        $score = isset($result['score']) ? round((float) $result['score'], 4) : 0.0;
        $chunk = trim((string) ($result['chunk_text'] ?? ''));

        if ($chunk === '') {
            continue;
        }

        if ($fileName !== '' && $fileName !== 'unknown_source') {
            $fileNames[] = $fileName;
        }

        $blocks[] = "[{$sourceId}] file={$fileName} score={$score}\n{$chunk}\n";
        $sources[] = $sourceId . ':' . $fileName;
    }

    return [
        'context' => implode("\n", $blocks),
        'sources' => $sources,
        'file_names' => array_values(array_unique($fileNames)),
        'source_hash' => sha1(implode('|', $sources)),
    ];
}

function buildConversationHistoryText($conversationHistory)
{
    if (!is_array($conversationHistory) || empty($conversationHistory)) {
        return '(none)';
    }

    $recent = array_slice($conversationHistory, -12);
    $lines = [];

    foreach ($recent as $msg) {
        $role = (isset($msg['role']) && $msg['role'] === 'model') ? 'Co-Founder' : 'User';
        $content = trim((string) ($msg['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        if (mb_strlen($content, 'UTF-8') > 350) {
            $content = mb_substr($content, 0, 350, 'UTF-8') . '...';
        }

        $lines[] = "{$role}: {$content}";
    }

    return empty($lines) ? '(none)' : implode("\n", $lines);
}

function buildSystemPrompt($intent, $hasEvidence, $language)
{
    $tone = 'Direct, strategic, and execution-focused.';
    if ($intent === 'definition') {
        $tone = 'Short and precise first, then practical implications.';
    } elseif ($intent === 'analysis') {
        $tone = 'Critical but constructive. Focus on risks, trade-offs, and concrete fixes.';
    } elseif ($intent === 'strategy') {
        $tone = 'Decisive and structured: metrics, sequencing, and constraints.';
    }

    $grounding = $hasEvidence
        ? 'Ground every substantial claim in evidence snippets and cite [Exact File Name].'
        : 'Use your best strategic reasoning, avoid fabricated facts, and keep the answer practical.';

    return <<<EOT
You are "The Co-Founder", a strategic operating partner.
Language: respond in {$language} unless the user requests another language.
Tone: {$tone}
Grounding policy: {$grounding}
Rules:
- Never claim you used sources if evidence is missing.
- Never invent books, numbers, or case studies.
- Prefer actionable steps over generic tips.
- If ambiguity blocks a good answer, ask at most one clarifying question.
EOT;
}

function detectLanguage($text)
{
    if (preg_match('/[\x{10A0}-\x{10FF}]/u', (string) $text)) {
        return 'Georgian';
    }
    return 'English';
}

function cofounderGuardResponse($text, $contextPayload)
{
    $normalized = trim((string) $text);
    if ($normalized === '') {
        return $normalized;
    }

    $fileNames = [];
    if (isset($contextPayload['file_names']) && is_array($contextPayload['file_names'])) {
        $fileNames = array_values(array_unique(array_filter(array_map('trim', $contextPayload['file_names']))));
    }

    if (!empty($fileNames) && str_contains($normalized, '[File Name]')) {
        $normalized = str_replace('[File Name]', '[' . $fileNames[0] . ']', $normalized);
    }

    // Remove empty or placeholder-only footer lines before appending normalized footer.
    $normalized = preg_replace_callback('/^\s*გამოყენებული ბიბლიოთეკა:\s*(.*)$/um', static function ($m) {
        $value = isset($m[1]) ? trim((string) $m[1]) : '';
        if ($value === '' || cofounderIsPlaceholderFooterValue($value)) {
            return '';
        }
        return $m[0];
    }, $normalized);
    $normalized = trim((string) $normalized);

    $citationTail = cofounderBuildCitationTail($normalized, $contextPayload);
    if ($citationTail !== '') {
        $normalized = rtrim($normalized) . $citationTail;
    }

    return trim($normalized);
}

function cofounderGuardedStream($rawStream, $contextPayload)
{
    return (function () use ($rawStream, $contextPayload) {
        $fullText = '';

        if (!is_iterable($rawStream)) {
            $single = trim((string) $rawStream);
            if ($single !== '') {
                $fullText .= $single;
                yield $single;
            }

            $tail = cofounderBuildCitationTail($fullText, $contextPayload);
            if ($tail !== '') {
                yield $tail;
            }
            return;
        }

        foreach ($rawStream as $part) {
            $chunkText = '';
            if (is_string($part)) {
                $chunkText = $part;
            } elseif (is_object($part) && method_exists($part, 'text')) {
                $chunkText = (string) $part->text();
            }

            // Remove placeholder footer variants in-stream so UI does not show "(none)".
            $chunkText = preg_replace('/გამოყენებული ბიბლიოთეკა:\s*\(none\)\.?/iu', '', (string) $chunkText);
            $chunkText = preg_replace('/გამოყენებული ბიბლიოთეკა:\s*none\.?/iu', '', (string) $chunkText);

            if ($chunkText === '' || trim($chunkText) === '') {
                continue;
            }

            $fullText .= $chunkText;
            yield $chunkText;
        }

        $tail = cofounderBuildCitationTail($fullText, $contextPayload);
        if ($tail !== '') {
            yield $tail;
        }
    })();
}

function cofounderBuildCitationTail($text, $contextPayload)
{
    $text = (string) $text;
    $fileNames = cofounderResolveFileNames($contextPayload);

    if (empty($fileNames)) {
        if (cofounderHasNonEmptyLibraryFooter($text)) {
            return '';
        }
        return "\n\n" . cofounderBuildLibraryFooterLine($contextPayload);
    }

    $top = array_slice($fileNames, 0, 12);
    if (empty($top)) {
        if (cofounderHasNonEmptyLibraryFooter($text)) {
            return '';
        }
        return "\n\n" . cofounderBuildLibraryFooterLine($contextPayload);
    }

    $bracketed = array_map(static function ($name) {
        return '[' . $name . ']';
    }, $top);

    $hasInlineCitation = false;
    foreach ($fileNames as $fileName) {
        if ($fileName === '') {
            continue;
        }
        if (mb_stripos($text, '[' . $fileName . ']', 0, 'UTF-8') !== false) {
            $hasInlineCitation = true;
            break;
        }
    }

    $segments = [];
    if (!$hasInlineCitation && !(preg_match('/\[[^\]]+\]/u', $text) === 1 && strpos($text, '[File Name]') === false)) {
        $segments[] = 'Grounding references: ' . implode(', ', $bracketed) . '.';
    }

    if (!cofounderHasNonEmptyLibraryFooter($text)) {
        $segments[] = cofounderBuildLibraryFooterLine($contextPayload);
    }

    if (empty($segments)) {
        return '';
    }

    return "\n\n" . implode("\n", $segments);
}

function cofounderResolveFileNames($contextPayload)
{
    $fileNames = [];

    if (isset($contextPayload['file_names']) && is_array($contextPayload['file_names'])) {
        foreach ($contextPayload['file_names'] as $name) {
            $clean = trim((string) $name);
            if ($clean !== '') {
                $fileNames[] = $clean;
            }
        }
    }

    // Fallback to source labels if file_names is unexpectedly empty.
    if (empty($fileNames) && isset($contextPayload['sources']) && is_array($contextPayload['sources'])) {
        foreach ($contextPayload['sources'] as $sourceLabel) {
            $sourceLabel = (string) $sourceLabel;
            $parts = explode(':', $sourceLabel, 2);
            $candidate = isset($parts[1]) ? trim($parts[1]) : '';
            if ($candidate !== '') {
                $fileNames[] = $candidate;
            }
        }
    }

    return array_values(array_unique($fileNames));
}

function cofounderBuildLibraryFooterLine($contextPayload)
{
    $fileNames = cofounderResolveFileNames($contextPayload);
    if (empty($fileNames)) {
        $fallbackFiles = cofounderDefaultLibraryFileNames(8);
        if (!empty($fallbackFiles)) {
            return 'გამოყენებული ბიბლიოთეკა: ' . implode(', ', $fallbackFiles) . '.';
        }
        return 'გამოყენებული ბიბლიოთეკა: LibraryFallbackUnavailable.';
    }

    return 'გამოყენებული ბიბლიოთეკა: ' . implode(', ', array_slice($fileNames, 0, 12)) . '.';
}

function cofounderHasNonEmptyLibraryFooter($text)
{
    $matches = [];
    if (preg_match_all('/გამოყენებული ბიბლიოთეკა:\s*(.*)$/um', (string) $text, $matches) < 1 || !isset($matches[1])) {
        return false;
    }

    foreach ($matches[1] as $line) {
        $line = trim((string) $line);
        $line = trim($line, " \t\n\r\0\x0B.");
        if ($line !== '' && !cofounderIsPlaceholderFooterValue($line)) {
            return true;
        }
    }

    return false;
}

function cofounderIsPlaceholderFooterValue($value)
{
    $v = mb_strtolower(trim((string) $value), 'UTF-8');
    $v = trim($v, " \t\n\r\0\x0B.()[]");

    if ($v === '') {
        return true;
    }

    $placeholders = [
        'none',
        'no source',
        'no sources',
        'n/a',
        'na',
        'not available',
        'unknown',
        'წყაროები არ მოიძებნა',
        'წყარო არ მოიძებნა',
        'libraryfallbackunavailable',
    ];

    return in_array($v, $placeholders, true);
}

function buildCitationWhitelist($fileNames)
{
    if (!is_array($fileNames) || empty($fileNames)) {
        $fallback = cofounderDefaultLibraryFileNames(8);
        if (empty($fallback)) {
            return 'LibraryFallbackUnavailable';
        }
        $formattedFallback = array_map(static function ($name) {
            return '[' . $name . ']';
        }, $fallback);
        return implode(', ', $formattedFallback);
    }

    $clean = array_values(array_unique(array_filter(array_map('trim', $fileNames))));
    if (empty($clean)) {
        $fallback = cofounderDefaultLibraryFileNames(8);
        if (empty($fallback)) {
            return 'LibraryFallbackUnavailable';
        }
        $formattedFallback = array_map(static function ($name) {
            return '[' . $name . ']';
        }, $fallback);
        return implode(', ', $formattedFallback);
    }

    $top = array_slice($clean, 0, 20);
    $formatted = array_map(static function ($name) {
        return '[' . $name . ']';
    }, $top);

    return implode(', ', $formatted);
}

function cofounderDefaultLibraryFileNames($limit = 8)
{
    static $cache = null;
    if ($cache !== null) {
        return array_slice($cache, 0, max(1, (int) $limit));
    }

    $dbPath = __DIR__ . '/db/database.sqlite';
    if (!is_file($dbPath)) {
        $cache = [];
        return [];
    }

    try {
        $pdo = new PDO("sqlite:$dbPath");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query('SELECT file_name FROM knowledge_chunks GROUP BY file_name ORDER BY COUNT(*) DESC LIMIT 40');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $clean = [];
        foreach ((array) $rows as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $clean[] = $name;
            }
        }
        $cache = array_values(array_unique($clean));
    } catch (Exception $e) {
        error_log('⚠️ Footer fallback library query failed: ' . $e->getMessage());
        $cache = [];
    }

    return array_slice($cache, 0, max(1, (int) $limit));
}

function cofounderResponseCacheKey($modelName, $rewrittenQuery, $historyText, $sourceHash)
{
    return sha1('v5|' . $modelName . '|' . $rewrittenQuery . '|' . $historyText . '|' . $sourceHash);
}

function cofounderResponseCacheEnabled()
{
    $raw = getenv('COFOUNDER_ENABLE_RESPONSE_CACHE');
    if ($raw === false || $raw === null || trim((string) $raw) === '') {
        return false;
    }

    $value = mb_strtolower(trim((string) $raw), 'UTF-8');
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function cofounderReadResponseCache($key, $ttlSeconds)
{
    $path = '/tmp/cofounder_response_cache_' . $key . '.txt';
    if (!is_file($path)) {
        return null;
    }

    if ((time() - filemtime($path)) > $ttlSeconds) {
        @unlink($path);
        return null;
    }

    $raw = @file_get_contents($path);
    return $raw === false ? null : $raw;
}

function cofounderWriteResponseCache($key, $text)
{
    $path = '/tmp/cofounder_response_cache_' . $key . '.txt';
    @file_put_contents($path, $text);
}

// CLI Mode Support
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $question = $argv[1];
    echo "\n🤖 EXPERT ANSWER:\n";
    echo askExpert($question) . "\n\n";
}

?>
