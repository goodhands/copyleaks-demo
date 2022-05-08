<?php

include_once "demo.php";

$class = new PlagiarismChecker();

$endpoint = parse_url($_SERVER['REQUEST_URI']);
$path = ltrim($endpoint['path'], "/");

$url = $_SERVER['REQUEST_URI'];

if (strpos($url, 'download') !== false) {
    list($id, $filename) = explode("/", $path);
    error_log('download endpoint');
    $class->download();
} elseif (strpos($url, 'submit') !== false) {
    error_log('submit endpoint');
    $class->submit(true, true);
} elseif (strpos($url, 'export') !== false) {
    error_log('submit endpoint');
    $class->download(true, true);
} elseif (strpos($url, 'webhook') !== false) {
    error_log('webhook pinged!');

    $data = json_decode(file_get_contents('php://input'), true);

    list($webhook, $status, $id) = explode("/", $path);

    if ($status === 'completed') {
        $class->webhook($class->authToken, $data);
    }
}
