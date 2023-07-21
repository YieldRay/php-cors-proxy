<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
header_remove("X-Powered-By");
corsHeaders();
main();
// main("https://www.google.com"); 

function main($origin = '')
{

    // skip OPTIONS request
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        exit();
    }

    $path = urlPathPart();

    if ($origin !== '') {
        // proxy for single origin
        if (!isUrl($origin)) exit('$origin should be url origin like "https://example.net" !');
        reverseProxy((endsWith($origin, "/") ? $origin : $origin . "/") . $path, [], false);
        return;
    }

    if (strlen($path) === 0) {
        function getServerVar($key)
        { // avoid php warning
            return array_key_exists($key, $_SERVER) ? $_SERVER[$key] : "";
        }
        $tmp = array_filter([getServerVar("HTTP_X_FORWARDED_PROTO"), getServerVar("REQUEST_SCHEME"), "http"], function ($e) {
            return is_string($e) && strlen($e) > 0;
        });
        $protocol = reset($tmp);
        $host = getServerVar("HTTP_HOST");
        $url = "$protocol://$host/https://example.net/api";
        header("content-type: text/plain");
        echo <<<HEREB
        Usage:
        const response = await fetch("$url");
        const json = await response.json();
        HEREB;
        exit();
    }

    $referer = isUrl($path) ? $path : "http://$path";
    reverseProxy($path, ["Referer: $referer"], true);
}

/**
 * =================
 * COMMON FUNCTIONS
 * =================
 */

// string utils

function startsWith($haystack, $needle)
{
    return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
}
function endsWith($haystack, $needle)
{
    return substr_compare($haystack, $needle, -strlen($needle)) === 0;
}
function removePrefix($haystack, $needle)
{
    if (!startsWith($haystack, $needle)) {
        return $haystack;
    }
    return substr($haystack, strlen($needle));
}
function removeSuffix($haystack, $needle)
{
    if (!endsWith($haystack, $needle)) {
        return $haystack;
    }
    return substr($haystack, 0, -strlen($needle));
}
function isUrl($str)
{
    return startsWith($str, "http://") || startsWith($str, "https://");
}

/**
 * get fixed url path part (no prefix '/')
 */
function urlPathPart()
{
    $PATH = "";
    $REQUEST_URI = $_SERVER["REQUEST_URI"];
    $SCRIPT_NAME = $_SERVER["SCRIPT_NAME"];

    if (startsWith($REQUEST_URI, $SCRIPT_NAME)) {
        // no rewrite
        // $_SERVER['REQUEST_URI']	/test/index.php/https://example.net
        // $_SERVER['SCRIPT_NAME']	/test/index.php
        $PATH = removePrefix(removePrefix($REQUEST_URI, $SCRIPT_NAME), "/");
    } else {
        // has rewrite
        // $_SERVER['REQUEST_URI']	/test/https://example.net
        // $_SERVER['SCRIPT_NAME']	/test/index.php
        $PATH = removePrefix($REQUEST_URI, removeSuffix($SCRIPT_NAME, "index.php"));
    }
    /**
     * <IfModule mod_rewrite.c>
     * RewriteEngine On
     * RewriteBase /test/
     * RewriteCond %{REQUEST_FILENAME} !-f
     * RewriteCond %{REQUEST_FILENAME} !-d
     * RewriteRule ^(.*)$ index.php [L,E=PATH_INFO:$1]
     * </IfModule>
     */
    return $PATH;
}

/**
 * reverse proxy for an url
 */
function reverseProxy($targetUrl, $incomingHeaders = [], $followLocation = false)
{
    // Get incoming request headers
    foreach (getallheaders() as $key => $val) {
        // Exclude some header
        if (strtolower($key) !== "host" && strtolower($key) !== "accept-encoding") {
            $incomingHeaders[] = "$key: $val";
        }
    }

    // Initialize cURL session
    $ch = curl_init();

    // Set the target URL
    curl_setopt($ch, CURLOPT_URL, $targetUrl);

    // Enable automatically set the Referer: field
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);

    // Pass incoming request headers to the target server
    curl_setopt($ch, CURLOPT_HTTPHEADER, $incomingHeaders);

    // Forward the request method and body
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents("php://input"));

    // Do not follow location
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    // Do not fail on error
    curl_setopt($ch, CURLOPT_FAILONERROR, false);

    // Forward headers
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header_line) use ($targetUrl, $followLocation) {
        // split header to key and value
        $kv = explode(":", $header_line);
        $k = strtolower(trim($kv[0])); // header key
        $v = trim(implode(":", array_slice($kv, 1))); // header value
        if (startsWith($k, "http/") || $k === "transfer-encoding") {
            return strlen($header_line);
        } // skip http version header

        if ($followLocation || $k !== "location") {
            http_response_code(curl_getinfo($curl, CURLINFO_HTTP_CODE)); // forward status code
            header($header_line); // forward single header here
            return strlen($header_line); // curl need this return
        }

        $location = $v;
        $parsedUrl = parse_url(isUrl($targetUrl) ? $targetUrl : "http://" . $targetUrl);
        $origin = $parsedUrl["scheme"] . "://" . $parsedUrl["host"] . (array_key_exists("port", $parsedUrl) ? (":" . $parsedUrl["port"]) : "");
        $redirect = removeSuffix($_SERVER["REQUEST_URI"], $targetUrl);
        if (isUrl($location)) {
            $redirect = $redirect . $location; // absolute url
        } elseif (startsWith($location, "/")) {
            $redirect = $redirect . $origin . $location; // relative to url's root
        } else {
            $redirect = $redirect . $origin . (array_key_exists("path", $parsedUrl) ? $parsedUrl["path"] : "") . "/" . $location; // relative to current url
        }
        header("location: $redirect"); // forward location header
        http_response_code(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        return strlen($header_line); // curl need this return
    });

    // Skip ssl check
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, false);
    curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYPEER, false);

    // Execute the cURL request
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    $success = curl_exec($ch);

    // Error handler
    if (!$success) {
        http_response_code(599);
        header("content-type: application/json");
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        echo json_encode(["errno" => $errno, "error" => $error]);
    }
}

/**
 * send cors headers
 */
function corsHeaders()
{
    header("access-control-allow-origin: *");
    header("access-control-allow-method: *");
    header("access-control-allow-headers: *");
    header("access-control-allow-credentials: true");
    header("access-control-expose-headers: *");
    header("access-control-max-age: 7200");
    header("timing-allow-origin: *");
}
