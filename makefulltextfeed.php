<?php
// makefulltextfeed.php — PHP 8.x safe single-file version
// This file merges fixes to prevent deprecated warnings from breaking headers,
// and guards for PHP 8 CurlHandle changes. It assumes existing libraries are present
// at /var/www/html/libraries/ and you want to keep the same app behavior.

// 0) Production-safe error handling: log everything, show nothing.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 1) Start output buffering immediately so no notices break headers.
if (!headers_sent()) {
    ob_start();
}

// 2) Strict headers only after buffering starts. We'll send once here.
// You may adjust content-type later if you emit JSON/XML conditionally.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 0');
header('Content-Type: application/xml; charset=UTF-8');

// 3) Remove deprecated libxml_disable_entity_loader() usage.
// In PHP >= 8.0, libxml external entity loader defaults are safe enough when you avoid LIBXML_NOENT.
// Do not call libxml_disable_entity_loader().

// 4) Autoload libraries (SimplePie, HTML5-PHP, HumbleHttpAgent).
// Keep includes quiet; if files are missing, we log and bail with a controlled error XML.
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
    // Flush buffer and exit; headers already set.
    ob_end_flush();
    exit;
}

// 5) Input: feed URL and options.
// We read from GET/POST safely, validating URL. Fall back gracefully when missing.
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

// Optional: item limit and timeouts
$itemLimit = (int) param('limit', 50);
if ($itemLimit <= 0) $itemLimit = 50;
$timeoutSec = (int) param('timeout', 15);
if ($timeoutSec < 3) $timeoutSec = 10;

// 6) Utilities

// Guarded strtolower (avoids deprecated “Passing null to parameter #1 ($string)”)
function safe_lower(?string $s): ?string {
    return $s === null ? null : strtolower($s);
}

// Create a cURL handle safely; return null on failure.
// Never stringify CurlHandle; only pass to curl_*.
function make_curl_handle(string $url, int $timeout): ?CurlHandle {
    $ch = curl_init();
    if ($ch === false) {
        error_log("curl_init() failed");
        return null;
    }
    // CurlHandle in PHP 8: set options explicitly
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

// Fetch a URL via CurlHandle with error handling
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

// 7) Parse feed with SimplePie, but avoid dynamic property deprecations.
// Modern SimplePie versions handle PHP 8; if yours is older, we’ll still try.
$sp = new SimplePie();
$sp->set_feed_url($feedUrl);
$sp->enable_order_by_date(true);
$sp->set_cache_duration(0); // Disable cache for simplicity; adjust as needed.
$sp->init();

if ($sp->error()) {
    error_log("SimplePie error: " . $sp->error());
}

// 8) Build output: expand each item to full text using HumbleHttpAgent/RollingCurl safely.
$items = $sp->get_items(0, $itemLimit);
if (!is_array($items)) {
    $items = [];
}

// Prepare batch fetch list
$targets = [];
foreach ($items as $it) {
    $link = $it->get_link();
    if (!$link || !filter_var($link, FILTER_VALIDATE_URL)) {
        continue;
    }
    $targets[] = $link;
}

// If HumbleHttpAgent supports multi-fetch, use it; otherwise fall back to curl loop.
$fullTexts = [];
if (!empty($targets)) {
    try {
        // HumbleHttpAgent flow
        $agent = new HumbleHttpAgent();
        // Be explicit with options to avoid PHP 8 surprises
        $agent->setConnectTimeout($timeoutSec);
        $agent->setTimeout($timeoutSec);

        // fetchAllOnce / fetchAll may internally rely on RollingCurl.
        // The library must not stringify CurlHandle; if it does, our environment
        // still avoids emitting notices. We add a protective try/catch:
        $responses = $agent->fetchAll($targets);

        foreach ($targets as $idx => $url) {
            $respBody = '';
            $status = 0;

            if (is_array($responses) && array_key_exists($idx, $responses)) {
                $r = $responses[$idx];
                // Handle common response shapes: ['body' => ..., 'status' => ...] or objects
                if (is_array($r)) {
                    $respBody = (string)($r['body'] ?? '');
                    $status = (int)($r['status'] ?? 200);
                } elseif (is_object($r)) {
                    if (property_exists($r, 'body')) $respBody = (string)$r->body;
                    if (property_exists($r, 'status')) $status = (int)$r->status;
                }
            }

            if ($respBody === '') {
                // Fallback single fetch to improve resilience
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
        error_log("HumbleHttpAgent batch error: " . $e->getMessage());
        // Fallback: single-threaded fetch
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

// 9) Minimal full-text extraction
// If you have Readability or HTML5-PHP utilities, use them. Here we do a simple extraction:
// Try to pick main content from <article>, or <main>, else fallback to body.
function extract_main_content(string $html): string {
    // Very lightweight extraction to avoid dependencies clashing.
    // If HTML5-PHP is present, we could parse DOM; to keep safe under PHP 8, use regex heuristics.
    // Note: For production quality, replace with a robust parser (Readability, DOMDocument).
    $clean = $html;

    // Prefer <article>
    if (preg_match('~<article\b[^>]*>(.*?)</article>~is', $clean, $m)) {
        return trim($m[1]);
    }
    // Then <main>
    if (preg_match('~<main\b[^>]*>(.*?)</main>~is', $clean, $m)) {
        return trim($m[1]);
    }
    // Else body content
    if (preg_match('~<body\b[^>]*>(.*?)</body>~is', $clean, $m)) {
        return trim($m[1]);
    }
    // Fallback: entire HTML (may be noisy)
    return trim($clean);
}

// 10) Emit RSS XML with expanded content.
// Keep headers consistent and never echo notices.
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
    $date = $it->get_date('U'); // Unix timestamp or false
    $guid = $it->get_id() ?: $link;

    // Safe lowercase helper example (avoid strtolower(null))
    $mime = safe_lower($it->get_content_type() ?: null); // may be null on some feeds

    $contentHtml = '';
    if ($link && isset($fullTexts[$link]) && $fullTexts[$link]['ok'] && is_string($fullTexts[$link]['html'])) {
        $contentHtml = extract_main_content($fullTexts[$link]['html']);
    }
    if ($contentHtml === '') {
        // fallback to feed description if extraction failed
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

// 11) Finalize buffered output safely.
if (ob_get_level() > 0) {
    ob_end_flush();
}
