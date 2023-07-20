<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('./shared.php');
header_remove('X-Powered-By');
corsHeaders();
if (getServerVar('REQUEST_METHOD') === 'OPTIONS') exit(); // skip OPTIONS request

$path = urlPathPart();

if (strlen($path) <= 2) {
    $protocol = pickString(getServerVar('HTTP_X_FORWARDED_PROTO'), getServerVar('REQUEST_SCHEME'), 'http');
    $host = getServerVar('HTTP_HOST');
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
reverseProxy($path, ["Referer: $referer"]);

// pick a not empty string from arguments
function pickString(...$items)
{
    $string = "";
    foreach ($items as $e) {
        if (is_string($e) && $e !== "") {
            $string = $e;
            break;
        }
    }
    return $string;
}

// avoid php warning
function getServerVar($key)
{
    return array_key_exists($key, $_SERVER) ? $_SERVER[$key] : "";
}
