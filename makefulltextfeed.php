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
$ok = $ok && safe_require($base . '/libraries/humble-http-agent/HumbleHttpAgent.php');
$ok = $ok && safe_require($base . '/libraries/humble-http-agent/RollingCurl.php');

if (!$ok) {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo "<error>Dependencies not found. See error log.</error>";
    ob_end_flush();
    exit;
}

// 4) Input: feed URL and options.
function param(string $name, mixed $default = null): mixed {
    if (isset($_GET[$name])) return $_GET[$name];
    if (isset($_POST[$name])) return $_POST[$name];
    return $default;
}

$feedUrl = trim((string) param('url', ''));
if ($feedUrl === '' || !filter_var($feedUrl, FILTER_VALIDATE_URL)) {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo "<error>Invalid or missing 'url' parameter.</error>";
    ob_end_flush();
    exit;
}

$itemLimit = (int) param('limit', 50);
if ($itemLimit <= 0) $itemLimit = 50;
$timeoutSec = (int) param('timeout', 15);
if ($timeoutSec < 3) $timeoutSec = 10;

// 5) Utilities
function make_curl_handle(string $url, int $timeout): ?CurlHandle {
    $ch = curl_init();
    if ($ch === false) {
        error_log("curl_init() failed");
        return null;
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
        return null;
    }
    return $ch;
}

function fetch_url(string $url, int $timeout): array {
    $ch = make_curl_handle($url, $timeout);
    if ($ch === null) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Curl init failed'];
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['ok' => false, 'status' => (int)$code, 'body' => '', 'error' => $err ?: 'curl_exec failed'];
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 400, 'status' => (int)$code, 'body' => (string)$body, 'error' => ''];
}

// 6) Parse feed with SimplePie (avoid crashing on its internal error handler).
$sp = new SimplePie();
$sp->set_feed_url($feedUrl);
$sp->enable_order_by_date(true);
$sp->set_cache_duration(0);
$sp->init();

if ($sp->error()) {
    // Log and continue with empty items instead of invoking SimplePie_Misc::error path
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
    $targets[] = $link;
}

// 8) Fetch full texts with HumbleHttpAgent when possible; guard method APIs and CurlHandle issues.
$fullTexts = [];
if (!empty($targets)) {
    try {
        $agent = new HumbleHttpAgent();

        // Timeout API varies; guard methods.
        if (method_exists($agent, 'setConnectTimeout')) {
            $agent->setConnectTimeout($timeoutSec);
        } elseif (method_exists($agent, 'setOptions')) {
            $agent->setOptions(['connect_timeout' => $timeoutSec]);
        }
        if (method_exists($agent, 'setTimeout')) {
            $agent->setTimeout($timeoutSec);
        } elseif (method_exists($agent, 'setOptions')) {
            $agent->setOptions(['timeout' => $timeoutSec]);
        }

        // Attempt batch fetch.
        $responses = $agent->fetchAll($targets);

        foreach ($targets as $idx => $url) {
            $respBody = '';
            $status = 0;

            if (is_array($responses) && array_key_exists($idx, $responses)) {
                $r = $responses[$idx];
                if (is_array($r)) {
                    $respBody = (string)($r['body'] ?? '');
                    $status = (int)($r['status'] ?? 200);
                } elseif (is_object($r)) {
                    if (property_exists($r, 'body')) $respBody = (string)$r->body;
                    if (property_exists($r, 'status')) $status = (int)$r->status;
                }
            }

            if ($respBody === '') {
                // Fallback: single fetch via curl.
                $single = fetch_url($url, $timeoutSec);
                $respBody = $single['body'];
                $status = $single['status'];
            }

            $fullTexts[$url] = [
                'ok' => $status >= 200 && $status < 400 && $respBody !== '',
                'status' => $status,
                'html' => $respBody,
            ];
        }
    } catch (Throwable $e) {
        // Common PHP 8 issue from HumbleHttpAgent: "Object of class CurlHandle could not be converted to string"
        // Fall back to safe per-URL fetch with curl for resilience.
        error_log("HumbleHttpAgent batch error: " . $e->getMessage());
        foreach ($targets as $url) {
            $single = fetch_url($url, $timeoutSec);
            $fullTexts[$url] = [
                'ok' => $single['ok'],
                'status' => $single['status'],
                'html' => $single['body'],
            ];
        }
    }
}

// 9) Minimal full-text extraction (regex heuristics).
function extract_main_content(string $html): string {
    $clean = $html;
    if (preg_match('~<article\b[^>]*>(.*?)</article>~is', $clean, $m)) {
        return trim($m[1]);
    }
    if (preg_match('~<main\b[^>]*>(.*?)</main>~is', $clean, $m)) {
        return trim($m[1]);
    }
    if (preg_match('~<body\b[^>]*>(.*?)</body>~is', $clean, $m)) {
        return trim($m[1]);
    }
    return trim($clean);
}

// 10) Content type inference across SimplePie versions.
function infer_item_content_type(SimplePie_Item $item): string {
    // Prefer enclosure MIME type if present
    if (method_exists($item, 'get_enclosure')) {
        $enc = $item->get_enclosure();
        if ($enc && method_exists($enc, 'get_type')) {
            $t = $enc->get_type();
            if (is_string($t) && $t !== '') {
                return strtolower($t);
            }
        }
    }
    // If the library/fork provides get_content_type(), use it
    if (method_exists($item, 'get_content_type')) {
        $t = $item->get_content_type();
        if (is_string($t) && $t !== '') {
            return strtolower($t);
        }
    }
    // Heuristic: HTML vs XML based on available fields
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
    $link = $it->get_link() ?: '';
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
