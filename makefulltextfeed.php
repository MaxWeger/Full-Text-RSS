<?php
// makefulltextfeed.php — PHP 8.x safe single-file version
// Hardened for PHP 8 SimplePie/HumbleHttpAgent differences and CurlHandle behavior.

// 0) Production-safe error handling: log everything, show nothing.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 1) Start output buffering immediately so no notices break headers.
if (!headers_sent()) {
    ob_start();
}

// 2) Strict headers only after buffering starts.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 0');
header('Content-Type: application/xml; charset=UTF-8');

// 3) Autoload libraries (SimplePie, HTML5-PHP, HumbleHttpAgent).
$base = __DIR__;
function safe_require(string $path): bool {
    if (file_exists($path)) {
        require_once $path;
        return true;
    }
    error_log("Missing dependency: {$path}");
    return false;
}

$ok = true;
$ok = $ok && safe_require($base . '/libraries/simplepie/autoloader.php');
$ok = $ok && safe_require($base . '/libraries/html5php/autoloader.php');
// Prefer RollingCurl low-level; HumbleHttpAgent may not be PHP 8.2 safe in batch path
$ok = $ok && safe_require($base . '/libraries/humble-http-agent/RollingCurl.php');

if (!$ok) {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo "<error>Dependencies not found. See error log.</error>";
    if (ob_get_level() > 0) ob_end_flush();
    exit;
}

// 4) Input: feed URL and options.
function param(string $name, mixed $default = null): mixed {
    if (isset($_GET[$name])) return $_GET[$name];
    if (isset($_POST[$name])) return $_POST[$name];
    return $default;
}

function normalize_feed_url(string $raw): string {
    $url = trim($raw);

    // Strip nonstandard scheme like sec:// → https://
    if (stripos($url, 'sec://') === 0) {
        $url = 'https://' . substr($url, 6);
    }

    // Unwrap known FeedControl indirection patterns: ...nu#/feed/... ?text=ENCODED_URL
    // We only trust text= as canonical source if present.
    $parts = parse_url($url);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        if (!empty($q['text'])) {
            $candidate = $q['text'];
            // Nested encoding occurs; decode repeatedly but cap iterations
            for ($i = 0; $i < 3; $i++) {
                $decoded = urldecode($candidate);
                if ($decoded === $candidate) break;
                $candidate = $decoded;
            }
            if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                $url = $candidate;
            }
        }
    }

    // Force https if host is present and scheme missing
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    return $url;
}

$feedUrlRaw = (string) param('url', '');
$feedUrl = normalize_feed_url($feedUrlRaw);

if ($feedUrl === '' || !filter_var($feedUrl, FILTER_VALIDATE_URL)) {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo "<error>Invalid or missing 'url' parameter.</error>";
    if (ob_get_level() > 0) ob_end_flush();
    exit;
}

$itemLimit = (int) param('limit', (int) param('max', 50)); // support both limit & legacy max
if ($itemLimit <= 0) $itemLimit = 50;
$timeoutSec = (int) param('timeout', 15);
if ($timeoutSec < 3) $timeoutSec = 10;

// 5) cURL utilities (strict type-safe)
function make_curl_handle(string $url, int $timeout): CurlHandle|false {
    $ch = curl_init();
    if ($ch === false) {
        error_log("curl_init() failed");
        return false;
    }
    $ok = curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'FullTextRSS-PHP8/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml,application/rss+xml,application/atom+xml,application/xhtml+xml,text/html;q=0.9,*/*;q=0.8',
        ],
    ]);
    if ($ok === false) {
        error_log("curl_setopt_array() failed for URL: {$url}");
        curl_close($ch);
        return false;
    }
    return $ch;
}

function fetch_url(string $url, int $timeout): array {
    $ch = make_curl_handle($url, $timeout);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Curl init failed'];
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => $code, 'body' => '', 'error' => $err ?: 'curl_exec failed'];
    }
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 400 && is_string($body) && $body !== '', 'status' => $code, 'body' => (string)$body, 'error' => ''];
}

// 6) Parse feed with SimplePie safely.
$sp = new SimplePie();
$sp->set_feed_url($feedUrl);
$sp->enable_order_by_date(true);
$sp->set_cache_duration(0);

// Provide a cache location to avoid internal errors on some setups
$cacheDir = sys_get_temp_dir() . '/sp-cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $sp->set_cache_location($cacheDir);
}

// Disable SimplePie’s internal error handler path; rely on $sp->error()
$sp->set_stupidly_fast(true);
$sp->init();

if ($sp->error()) {
    error_log("SimplePie error: " . $sp->error());
}

$items = $sp->get_items(0, $itemLimit);
if (!is_array($items)) {
    $items = [];
}

// 7) Prepare batch targets
$targets = [];
foreach ($items as $it) {
    $link = $it->get_link();
    if (!$link || !filter_var($link, FILTER_VALIDATE_URL)) {
        continue;
    }
    // Normalize article URL scheme if it came weird
    $targets[] = normalize_feed_url($link);
}

// 8) Fetch full texts using RollingCurl when available; fallback to per-URL curl.
// This avoids HumbleHttpAgent’s CurlHandle string issues on PHP 8.2.
$fullTexts = [];

if (!empty($targets) && class_exists('RollingCurl')) {
    try {
        $rc = new RollingCurl(function ($response, $info, $request) use (&$fullTexts) {
            $url = (string) ($request->getUrl() ?? '');
            $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
            $body = is_string($response) ? $response : '';
            $fullTexts[$url] = [
                'ok' => $status >= 200 && $status < 400 && $body !== '',
                'status' => $status,
                'html' => $body,
            ];
        });
        $rc->setSimultaneousLimit(6);

        foreach ($targets as $url) {
            $req = new RollingCurlRequest($url);
            $req->addOptions([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => $timeoutSec,
                CURLOPT_TIMEOUT => $timeoutSec,
                CURLOPT_USERAGENT => 'FullTextRSS-PHP8/1.0',
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $rc->add($req);
        }
        $rc->execute();
    } catch (Throwable $e) {
        error_log("RollingCurl batch error: " . $e->getMessage());
    }
}

// Fallback for any missing results or failures
foreach ($targets as $url) {
    if (!isset($fullTexts[$url]) || !$fullTexts[$url]['ok']) {
        $single = fetch_url($url, $timeoutSec);
        $fullTexts[$url] = [
            'ok' => $single['ok'],
            'status' => $single['status'],
            'html' => $single['body'],
        ];
    }
}

// 9) DOM-based extraction (more reliable than regex).
function extract_main_content(string $html): string {
    // Basic sanitization
    if ($html === '') return '';

    // Use HTML5-PHP (Masterminds) to parse robustly
    try {
        $dom = \Masterminds\HTML5::loadHTML($html);
    } catch (Throwable $e) {
        // Fallback to regex only if parser fails
        if (preg_match('~<article\b[^>]*>(.*?)</article>~is', $html, $m)) return trim($m[1]);
        if (preg_match('~<main\b[^>]*>(.*?)</main>~is', $html, $m)) return trim($m[1]);
        if (preg_match('~<body\b[^>]*>(.*?)</body>~is', $html, $m)) return trim($m[1]);
        return trim($html);
    }

    if (!$dom) return '';

    // Heuristics: prefer <article>, then <main>, else largest content block
    $xpath = new DOMXPath($dom);
    foreach (['article', 'main'] as $tag) {
        $nodes = $xpath->query("//{$tag}");
        if ($nodes && $nodes->length > 0) {
            $node = $nodes->item(0);
            return trim(inner_html($node));
        }
    }

    // Largest text container heuristic: pick element with most text length among common containers
    $candidates = $xpath->query('//div|//section');
    $best = '';
    $bestLen = 0;
    if ($candidates) {
        foreach ($candidates as $node) {
            $htmlChunk = inner_html($node);
            $len = strlen(strip_tags($htmlChunk));
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $htmlChunk;
            }
        }
    }

    if ($best !== '') return trim($best);

    // Fallback to body
    $bodyNodes = $xpath->query('//body');
    if ($bodyNodes && $bodyNodes->length > 0) {
        return trim(inner_html($bodyNodes->item(0)));
    }

    return trim($html);
}

// Helper: innerHTML for DOMNode
function inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

// 10) Content type inference across SimplePie versions.
function infer_item_content_type(SimplePie_Item $item): string {
    if (method_exists($item, 'get_enclosure')) {
        $enc = $item->get_enclosure();
        if ($enc && method_exists($enc, 'get_type')) {
            $t = $enc->get_type();
            if (is_string($t) && $t !== '') return strtolower($t);
        }
    }
    if (method_exists($item, 'get_content_type')) {
        $t = $item->get_content_type();
        if (is_string($t) && $t !== '') return strtolower($t);
    }
    $hasHtml =
        (method_exists($item, 'get_content') && is_string($item->get_content()) && $item->get_content() !== '') ||
        (method_exists($item, 'get_description') && is_string($item->get_description()) && $item->get_description() !== '');
    return $hasHtml ? 'text/html' : 'application/xml';
}

// 11) Emit RSS XML with expanded content.
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo "<rss version=\"2.0\">\n";
echo "  <channel>\n";
echo "    <title>" . htmlspecialchars($sp->get_title() ?: 'Full Text Feed', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title>\n";
echo "    <link>" . htmlspecialchars($feedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</link>\n";
echo "    <description>Full-text version generated on PHP 8.x</description>\n";

foreach ($items as $it) {
    $title = $it->get_title() ?: '';
    $link = normalize_feed_url($it->get_link() ?: '');
    $desc = $it->get_description() ?: '';
    $date = $it->get_date('U'); // may be false for undated items
    $guid = $it->get_id() ?: $link;

    $mime = infer_item_content_type($it);

    $contentHtml = '';
    if ($link && isset($fullTexts[$link]) && $fullTexts[$link]['ok'] && is_string($fullTexts[$link]['html'])) {
        $contentHtml = extract_main_content($fullTexts[$link]['html']);
    }
    if ($contentHtml === '') {
        $contentHtml = $desc;
    }

    echo "    <item>\n";
    echo "      <title>" . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title>\n";
    echo "      <link>" . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</link>\n";
    echo "      <guid isPermaLink=\"false\">" . htmlspecialchars($guid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</guid>\n";
    if (is_int($date) && $date > 0) {
        echo "      <pubDate>" . gmdate('D, d M Y H:i:s', $date) . " GMT</pubDate>\n";
    }
    echo "      <description><![CDATA[" . $contentHtml . "]]></description>\n";
    echo "      <category>" . htmlspecialchars($mime ?: 'text/html', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</category>\n";
    echo "    </item>\n";
}

echo "  </channel>\n";
echo "</rss>\n";

// 12) Finalize buffered output safely.
if (ob_get_level() > 0) {
    ob_end_flush();
}
