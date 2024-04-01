<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
main();
// main("https://www.google.com"); 


/**
 * @param $origin  -  provide none string value or blank string to show a proxy api
 * or an url that proxy to (place index.php in root dir and rewrite engine must be configured correctly)
 */
function main($origin = '')
{
    // skip OPTIONS request
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        corsHeaders();
        exit();
    }

    $path = urlPathPart();

    // proxy for one site
    if (is_string($origin) && $origin !== '') {
        if (!isUrl($origin))
            exit('$origin should be url origin like "https://example.net" !');
        reverseProxy((endsWith($origin, "/") ? $origin : $origin . "/") . $path, [], false);
        exit();
    }

    // proxy api
    if ($path !== "") {
        $referer = isUrl($path) ? $path : "http://$path";
        reverseProxy($path, ["Referer: $referer"], true);
        exit();
    } else {
        // show api doc
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
 * 
 * @param targetUrl  -  the url that proxy to
 * @param requestHeaders  -  string array, header list that will be used when request
 * @param rewriteLocation  -  rewrite `location` header to keep url proxied, rather than redirect to another url
 */
function reverseProxy($targetUrl, $requestHeaders = [], $rewriteLocation = false)
{
    $isMultipartFormData = isset($_SERVER['CONTENT_TYPE']) && startsWith($_SERVER['CONTENT_TYPE'], 'multipart/form-data');

    // Get incoming request headers
    foreach (getallheaders() as $key => $val) {
        // Exclude some header
        $name = strtolower($key);
        if ($name !== "host" && $name !== "accept-encoding") {
            if ($name === "content-type" && $isMultipartFormData) {
                $requestHeaders[] = "content-type: multipart/form-data";
            } else {
                $requestHeaders[] = "$name: $val";
            }
        }
    }


    // Initialize cURL session
    $ch = curl_init();

    // Set the target URL
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    // Enable automatically set the Referer field
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);

    // Forward request headers
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

    // Forward request method and body
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
    if ($isMultipartFormData) {
        $fields = array();
        foreach ($_POST as $key => $value) {
            $fields[$key] = $value;
        }
        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $fields[$key] = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
            }
        }
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            $fields
        );
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents("php://input"));
        // curl_setopt($ch, CURLOPT_UPLOAD, true);
        // curl_setopt($ch, CURLOPT_INFILE, fopen('php://input', 'r'));
    }

    // Forward response headers
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header_line) use ($targetUrl, $rewriteLocation) {
        // split header to key and value
        $kv = explode(":", $header_line);
        $k = strtolower(trim($kv[0])); // header key
        $v = trim(implode(":", array_slice($kv, 1))); // header value
        if (startsWith($k, "http/") || $k === "transfer-encoding")
            return strlen($header_line); // skip http version header

        //?[option] $rewriteLocation 
        if ($rewriteLocation && $k === "location") {
            // handle "Location: xxx"
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
            header("location: $redirect"); // forward location header here
        } else {
            // handle "$k: $v"
            if (
                // remove csp header
                $k === "content-security-policy" ||
                $k === "content-security-policy-report-only" ||
                $k === "cross-origin-resource-policy" ||
                $k === "cross-origin-embedder-policy" ||
                $k === "permissions-policy" ||
                // remove cors header
                $k === "access-control-allow-origin" ||
                $k === "access-control-allow-method" ||
                $k === "access-control-allow-headers" ||
                $k === "access-control-allow-credentials" ||
                $k === "access-control-max-age" ||
                $k === "timing-allow-origin"
            ) {
                // do nothing
            } else {
                header($header_line); // forward single header here
            }
        }

        http_response_code(curl_getinfo($curl, CURLINFO_HTTP_CODE)); // forward status code
        return strlen($header_line); // curl need this return
    });

    // Do not follow location
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    // Do not fail on error
    curl_setopt($ch, CURLOPT_FAILONERROR, false);

    // Skip ssl check
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, false);
    curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYPEER, false);

    // Execute the cURL request & send response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    corsHeaders();
    $success = curl_exec($ch);

    // Error handler
    if (!$success) {
        http_response_code(599);
        header("content-type: application/json");
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $json = json_encode(["errno" => $errno, "error" => $error]);
        if ($_SERVER["REQUEST_METHOD"] === "HEAD")
            header("x-proxy-error: $json"); // experimental
        else
            echo $json;
    }
    exit();
}

/**
 * send cors headers
 */
function corsHeaders()
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
    header_remove("X-Powered-By");
    header("access-control-allow-origin: $origin");
    header("access-control-allow-method: *");
    header("access-control-allow-headers: *");
    header("access-control-allow-credentials: true");
    header("access-control-expose-headers: *");
    header("access-control-max-age: 7200");
    header("timing-allow-origin: *");
}
