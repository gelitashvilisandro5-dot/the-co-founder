<?php
/**
 * describe_library.php - Generate descriptions for a list of books
 */

require 'vendor/autoload.php';

use Gemini\Client;

if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

$api_key = getenv('GEMINI_API_KEY');
if (!$api_key) {
    die("❌ Error: GEMINI_API_KEY not found.\n");
}

$client = Gemini::client($api_key);
$model = $client->generativeModel('models/gemini-3-flash-preview');

// The massive list from the user
$bookList = <<<'EOT'
1. 01-11-2020-212827The 7 Habits of Highly Effective People.pdf
2. 021.pdf
3. 14.The Four Steps to the Epiphany (Steve Blank) 2013.pdf
4. 16-05-2021-051456The-Obstacle-Is-the-Way.pdf
5. 1735103610_startup playbook.pdf
6. 1Get Backed TOC intro ch1.pdf
7. 1d51eT8uRSmgFRRyaOMT_The 1-Page Marketing Plan_ Get New Customers, Make More Money, And Stand out From The Crowd ( PDFDrive ).pdf
8. 2477the-hard-thing-about-hard-things.pdf
9. 9780753556528.pdf
10. ABUIABA9GAAgzcquuQYowMibkgM.pdf
11. AETCS-2019-Book-for-Web.pdf
12. Al_Ries_Jack_Trout-The_22_Immutable_Laws_of_Marketing-EN.pdf
13. All Marketers Are Liars.pdf
14. Analytics-Lessons-Learned.pdf
15. Andy-Grove_-High-Output-Manage-Andy-Grove_3707.pdf
16. Apress-Founders-At-Work-Jessica-Livingston.pdf
17. Atomic habits ( PDFDrive ).pdf
18. BAE 682-Assembly Automation & Product Design.pdf
19. Blitzscaling. The Lightning Fast Path to Building Massively Valuable Companies.pdf
20. Blue Ocean Strategy, Expanded Edition_ How to Create Uncontested Market Space and Make the Competition Irrelevant ( PDFDrive.com ).pdf
21. Building-a-StoryBrand-Clarify-Your-Message-So-Customers-Will-Listen-by-Donald-Miller-z-lib.org_.pdf
22. Business-model-generation-_-a-handbook-for-visionaries-game-changers-and-challengers-PDFDrive-1.pdf
23. Change by Design_ How Design Thinking Transforms Organizations and Inspires Innovation .pdf
24. Clean.Code.A.Handbook.of.Agile.Software.Craftsmanship.pdf
25. Collins-2001-Good-to-Great-Why-Some-Companies-Make-the-Leap...and-Others-Dont_.pdf
26. Confessions-of-an-Advertising-Man-by-Ogilvy-David-Parker-Alan-z-lib.org_.pdf
27. Content-Burger-Pulizzi-Joe-Epic-content-marketing.pdf
28. Crossing-The-Chasm WHOLEBOOK.pdf
29. Crush_It_by_Gary_Vaynerchuk.pdf
30. Daniel Kahneman-Thinking, Fast and Slow .pdf
31. Design-for-Manufacture-and-Assembly-1692982263.pdf
32. Designing Data-Intensive Applications The Big Ideas Behind Reliable, Scalable, and Maintainable Systems by Martin Kleppmann (z-lib.org).pdf
33. Digital Marketing For Dummies ( PDFDrive ).pdf
34. E-book - The Ultimate Guide to New Product Development.pdf
35. Financial Intelligence.pdf
36. GapAndGain.pdf
37. Grinding It Out_ The Making of McDonald’s - PDF Room.pdf
38. HOW-WILL-YOU-MEASURE-YOUR-LIFE.pdf
39. Hardware+as+business.pdf
40. Hooked-How-to-Build-Habit-Forming-Products-_Nir-Eyal_.pdf
41. How To Win Friends And Influence People - Carnegie, Dale.pdf
42. I_Steve__Steve_Jobs_in_His_Own_Words___.pdf
43. Jab-Jab-Jab-Right-Hook_-How-Vaynerchuk-Gary1.pdf
44. L-G-0000588237-0002385028.pdf
45. Marketing 4.0_ Moving from Traditional to Digital ( PDFDrive ).pdf
46. Marketing A Love Story - PDF Room.pdf
47. Marketing Book.pdf
48. Measure-What-Matters-John-Doerr.pdf
49. Michael-Bloomberg-Bloomberg-by-Bloomberg.pdf
50. NegotiationGeniusMalhotra.pdf
51. O2NoTH5ITGumSoNx5Es5_UPSTREAM_MARKETING_CHAPTER_1.pdf
52. Onward_How_Starbucks_Fought_for_Its_Lif_-_Howard_Schultz.pdf
53. Patent It Yourself ~@nthr@x~.pdf
54. Paul_Allen_The_idea_man.pdf
55. Peter F. Drucker - Innovation and Entrepreneurship-1985.pdf
56. Play Bigger_ How Pirates, Dreamers, and Innovators Create and Dominate Markets - PDF Room.pdf
57. Refactoring-Improving-the-Design-of-Existing-Code-Addison-Wesley-Professional-1999.pdf
58. SEv3.pdf
59. Saint Startup. The Founder's Guidebook V2.0a.pdf
60. ScientificAdvertising.pdf
61. Seth-Godin-Purple-Cow-Transform-Your-Business-by-Being-Remarkable.pdf
62. Shoe_Dog_A_Memoir_by_the_Creator_of_NIKE_by_Phil_Knight.pdf
63. Startup-Founder-Survival-Guide.pdf
64. The Circuit Designers Companion.pdf
65. The Design of Everyday Things - Don Norman.pdf
66. The Diary of a CEO_ The 33 Laws of Business and Life - PDF Room.pdf
67. The E-Myth Revisited - Michael E. Gerber.pdf
68. The Innovator’s Dilemma (Clayton M. Christensen)2000.pdf
69. The Psychology of Persuasion.pdf
70. The+48+Laws+Of+Power.pdf
71. The-100-Startup-Chris-Guillebeau.pdf
72. The-Art-of-the-Start-2.0-8freebooks.net_+2 (1).pdf
73. The-Entrepreneurs-Guide-to-Building-a-Successful-Business-2017.pdf
74. The-Lean-Startup-.pdf
75. The-Mom-Test-en.pdf
76. The-Phoenix-Project-A-Novel-about-IT-DevOps-and-Helping-Your-Business-Win-by-Gene-Kim-George-Spafford-Kevin-Behr-z-lib.org_.pdf
77. The-war-of-art-by-Robert-Pressfield.pdf
78. The_Startup_Owner s_Manual-A step by step guide for building a great company.pdf
79. The_new_rules_Marketing_PR.pdf
80. This Is Marketing_ You Can’t Be Seen Until You Learn to See ( PDFDrive )_compressed.pdf
81. Timothy-Ferriss-The-4-Hour-Workweek-.pdf
82. Venture-deals.pdf
83. YouTube video marketing _ secrets revealed _ the beginners guide to online video marketing - PDF Room.pdf
84. ai_insight.txt
85. anthony-robbins-unlimited-power.pdf
86. ashlee-vance-elon-musk-tesla-spacex-and-the-quest-for-a-fantastic-future.pdf
87. bk_hayh_001430.pdf
88. bk_ntgl_000033.pdf
89. definitive-guide-to-engaging-email-marketing.pdf
90. delivering-happiness_-a-path-to-profits-hsieh_-tony.pdf
91. digital-marketing-strategy-an-integrated-approach-to-online-5ggz79hub6.pdf
92. dokumen.pub_buy-then-build-how-acquisition-entrepreneurs-outsmart-the-startup-game-9781544501147.docx
93. dokumen.pub_buy-then-build-how-acquisition-entrepreneurs-outsmart-the-startup-game-9781544501147.pdf
94. dokumen.pub_buyology-truth-and-lies-about-why-we-buy-9780385523899-2008006057-9780385528290.pdf
95. dokumen.pub_contagious-why-things-catch-on-9781451686593-1451686587.pdf
96. dokumen.pub_dotcom-secrets-the-underground-playbook-for-growing-your-company-online-9781630474782-2014919068 (1).pdf
97. dokumen.pub_dotcom-secrets-the-underground-playbook-for-growing-your-company-online-9781630474782-2014919068.pdf
98. dokumen.pub_growth-hacker-marketing-a-primer-on-the-future-of-pr-marketing-and-advertising-apenguin-special-from-portfolio-9780698138247.pdf
99. dokumen.pub_hacking-growth-how-todays-fastest-growing-companies-drive-breakout-success-9780451497215-045149721x-9780451497222-9781524760007.pdf
100. dokumen.pub_how-to-launch-a-brand-2nd-edition-your-step-by-step-guide-to-crafting-a-brand-from-positioning-to-naming-and-brand-identity-2nbsped-9780989646130.pdf
101. dokumen.pub_how-to-win-at-the-sport-of-business-9780983988533.pdf
102. dokumen.pub_obviously-awesome-how-to-nail-product-positioning-so-customers-get-it-buy-it-love-it.pdf
103. dokumen.pub_pitch-anything-an-innovative-method-for-presenting-persuading-and-winning-the-deal-9780071759762-007175976x-9780071752855-0071752854-f-1240938.epub
104. dokumen.pub_start-your-own-virtual-assistant-business-9781642011142-9781613084380-1642011142.pdf
105. dokumen.pub_the-pragmatic-programmer-from-journeyman-to-master-1nbsped-020161622x-9780201616224.pdf
106. dokumen.pub_traction-how-any-startup-can-achieve-rapid-customer-growth-9780241242551-024124255x-978-0-698-41187-6-0698411870.pdf
107. eMarketing_ The Essential Guide to Digital Marketing - PDF Room (1).pdf
108. gary-vaynerchuk-crushing-it-how-great-entrepreneurs-build-their-business-and-influence-and-how-you-can-too-harper-business-2018.pdf
109. how inovations work.pdf
110. isaacson2011stevejobs-1.pdf-1.pdf
111. iwoz.pdf
112. mythicalmanmonth00fred.pdf
113. napoleon-hill-think-and-grow-rich.pdf
114. newer split.pdf
115. output.pdf
116. pdfcoffee.com_2019-secrets-of-sand-hill-road-by-scott-kupor-venture-capital-and-how-to-get-it-penguin-audio-pdf-free.pdf
117. pdfcoffee.com_hook-point-how-to-stand-out-in-a-3-second-world-by-brendan-kane-4-pdf-free.pdf
118. pdfcoffee.com_ogilvy-on-advertising-4-pdf-free.pdf
119. pdfcoffee.com_ogilvy-on-advertising-pdf-free.pdf
120. pdfcoffee.com_profit-first-a-simple-system-to-transform-any-business-from-a-cash-eating-monster-to-a-money-making-machine-pdfdrive-pdf-free.pdf
121. pdfcoffee.com_the-psychology-of-selling-2-pdf-free.pdf
122. pdfcoffee.com_the-smartest-guys-in-the-room-by-bethany-mclean-pdf-free.pdf
123. presentation_zen_design__simple_design_principles_and_techniques_to_enhance_your_presentations.pdf
124. preview-9781118276945_A23918103.pdf
125. preview-9781420089288_A37928772.pdf
126. preview-9781422187586_A23972819.pdf
127. preview-9781449335724_A24027771.pdf
128. rework-jason-fried.pdf
129. rich-dad-poor-dad.pdf
130. sam_walton-made_in_america.pdf
131. simon-sinek-start-with-why.pdf
132. success_built_to_last.pdf
133. the-everything-store-jeff-bezos-and-the-age-of-amazon.pdf
134. the-millionaire-fastlane.pdf
135. the-personal-mba-kaufman-josh-679-pages_compress.pdf
136. the-tipping-point.pdf
137. toyota.pdf
138. vadim-zeland-reality-transurfing-pdf-free.pdf
139. სიმბრძნე_სიმდიდრისა_V1_1.pdf
EOT;

// Split the massive list into lines
$lines = explode("\n", trim($bookList));
$batchSize = 25;
$batches = array_chunk($lines, $batchSize);

echo "📚 Found " . count($lines) . " items. Processing in " . count($batches) . " batches...\n\n";

foreach ($batches as $i => $batch) {
    $batchNum = $i + 1;
    $batchText = implode("\n", $batch);
    
    echo "Processing Batch $batchNum / " . count($batches) . "...\n";
    
    $prompt = <<<EOT
I want you to describe these books from our digital library.
For each item:
1. Identify the book title and author (clean up the filename).
2. Provide a 2-3 sentence summary of what the book is about and its core value for a founder/entrepreneur.
3. If the file is unrecognizable (e.g. '021.pdf' or 'output.pdf'), mark it as "⚠️ Unknown/Generic content".

There is no limit on answer size. Be comprehensive.

LIST:
$batchText
EOT;

    try {
        $response = $model->generateContent($prompt);
        echo $response->text() . "\n\n";
    } catch (Exception $e) {
        echo "❌ Error in Batch $batchNum: " . $e->getMessage() . "\n";
    }
    
    // Cool down slightly between batches
    sleep(2);
}
echo "✅ Done.\n";
