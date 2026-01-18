<?php

$jsonFile = 'cofounder_library_map.json';
$mdFile = 'library_descriptions.md';

if (!file_exists($jsonFile) || !file_exists($mdFile)) {
    die("Files not found.\n");
}

$json = json_decode(file_get_contents($jsonFile), true);
if (!$json) {
    die("Invalid JSON in $jsonFile\n");
}

$mdContent = file_get_contents($mdFile);
$lines = explode("\n", $mdContent);

$descriptions = [];
$currentBlock = [];

foreach ($lines as $line) {
    $line = trim($line);
    
    // Check for new block start (e.g., "**26. filename.pdf**" or "1. **Title:**")
    if (preg_match('/^\*\*?(\d+)\.\s+(.*?)(\*\*|$)/', $line, $matches)) {
        if (!empty($currentBlock)) {
            $descriptions[] = $currentBlock;
        }
        $currentBlock = [
            'id' => $matches[1],
            'header_content' => trim($matches[2], "* "),
            'filename' => null,
            'title' => null,
            'summary' => null
        ];
        
        // Check if header content looks like a filename (ends in .pdf, .txt, etc or has hyphens/underscores and no spaces)
        if (preg_match('/\.(pdf|txt|epub|docx?)$/i', $currentBlock['header_content'])) {
            $currentBlock['filename'] = $currentBlock['header_content'];
        }
    } elseif (preg_match('/^\d+\.\s+\*\*Title:\*\*/', $line, $matches)) {
         // Handle blocks that start directly with Title lines like "1. **Title:**"
         if (!empty($currentBlock)) {
            $descriptions[] = $currentBlock;
        }
         $currentBlock = [
            'id' => null, // extract if needed
            'title' => null,
            'summary' => null,
            'filename' => null
        ];
    }

    // Extract Title
    if (preg_match('/\*\*Title:\*\*\s*(.*)/', $line, $matches)) {
        $currentBlock['title'] = trim($matches[1], "* ");
    }

    // Extract Summary
    if (preg_match('/\*\*Summary:\*\*\s*(.*)/', $line, $matches) || preg_match('/^\*?\s+\*\*Summary:\*\*\s*(.*)/', $line, $matches)) {
        // Summary might be multi-line. For simplicty take the first line + lookahead?
        // Actually, let's just take the rest of the paragraph if possible.
        // For now, take the matched line.
        $currentBlock['summary'] = trim($matches[1]);
    }
    // Append to summary if line is text and we are in summary mode? 
    // (Simplification: assuming summary is 1 paragraph on one line or followed by empty line)
    
}
if (!empty($currentBlock)) {
    $descriptions[] = $currentBlock;
}

echo "Found " . count($descriptions) . " descriptions.\n";

$updatedCount = 0;

foreach ($descriptions as $desc) {
    $matchKey = null;

    // Strategy 1: Exact Filename Match
    if (!empty($desc['filename']) && isset($json[$desc['filename']])) {
        $matchKey = $desc['filename'];
    }

    // Strategy 2: Fuzzy Filename Match in JSON keys
    if (!$matchKey && !empty($desc['filename'])) {
        foreach (array_keys($json) as $key) {
            if (stripos($key, $desc['filename']) !== false) {
                $matchKey = $key;
                break;
            }
        }
    }

    // Strategy 3: Match by Title
    if (!$matchKey && !empty($desc['title'])) {
        // Normalize title: remove "The", special chars
        $searchTitle = preg_replace('/[^\w\s]/', '', $desc['title']);
        $searchTitle = str_ireplace('The ', '', $searchTitle);
        $searchTerms = explode(' ', $searchTitle);
        $searchTerms = array_filter($searchTerms, function($w) { return strlen($w) > 3; });

        $bestMatch = null;
        $maxScore = 0;

        foreach (array_keys($json) as $key) {
            $score = 0;
            foreach ($searchTerms as $term) {
                if (stripos($key, $term) !== false) {
                    $score++;
                }
            }
            if ($score > $maxScore && $score >= count($searchTerms) * 0.5) { // At least 50% match
                 $maxScore = $score;
                 $bestMatch = $key;
            }
        }
        if ($bestMatch) {
            $matchKey = $bestMatch;
        }
    }

    if ($matchKey && !empty($desc['summary'])) {
        $json[$matchKey] = $desc['summary'];
        $updatedCount++;
        // echo "Updated $matchKey\n";
    }
}

file_put_contents($jsonFile, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Updated $updatedCount items in $jsonFile.\n";

?>
