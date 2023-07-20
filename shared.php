<?php

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
    if (!startsWith($haystack, $needle)) return $haystack;
    return substr($haystack, strlen($needle));
}
function removeSuffix($haystack, $needle)
{
    if (!endsWith($haystack, $needle)) return $haystack;
    return substr($haystack, 0, -strlen($needle));
}
function isUrl($str)
{
    return startsWith($str, "http://") || startsWith($str, "https://");
}

/**
 * get fixed url path part (no predix '/')
 */
function urlPathPart()
{
    $PATH = "";
    $REQUEST_URI = $_SERVER['REQUEST_URI'];
    $SCRIPT_NAME = $_SERVER['SCRIPT_NAME'];


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
function reverseProxy($targetUrl, $incomingHeaders = [])
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
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));

    // Do not follow location
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    // Forward headers
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header_line) use ($targetUrl) {
        // split header to key and value
        $kv = explode(":", $header_line);
        $k = strtolower(trim($kv[0])); // header key
        $v = trim(implode(":", array_slice($kv, 1))); // header value
        if (startsWith($k, "http/") || $k === "transfer-encoding") return strlen($header_line); // skip http version header

        if ($k !== "location") {
            header($header_line); // forward single header here
            return strlen($header_line); // curl need this return
        }

        $location =  $v;
        $url = isUrl($targetUrl) ? $targetUrl : ("http://" . $targetUrl); // auto add protocol
        $parsedUrl = parse_url($url);
        $origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        $redirect =  removeSuffix($_SERVER['REQUEST_URI'], $targetUrl);
        if (startsWith($location, $origin)) {
            $redirect = $redirect . $location; // absolute url
        } else if (startsWith($location, "/")) {
            $redirect = $redirect . $origin . $location; // relative to root url
        } else if (isUrl($location)) {
            $parsedLocation = parse_url($location);
            $parsedLocationHost = array_key_exists('host', $parsedLocation) ? $parsedLocation['host'] : '';
            if ($parsedLocationHost === $parsedUrl['host']) { // same host but not same protocol
                $redirect = $redirect . $location; // absolute url
            } else {
                header($header_line);  // do not modify if redirect to another origin
            }
        } else {
            $redirect = $redirect . $targetUrl . $location; // relative to current url
        }
        header("location: $redirect"); // forward location header
        return strlen($header_line); // curl need this return
    });

    // Skip ssl check
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, false);
    curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYPEER, false);

    // Forward status code
    http_response_code(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    // Execute the cURL request
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_exec($ch);
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
