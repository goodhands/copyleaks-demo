<?php

include_once "demo.php";

$class = new PlagiarismChecker();

$endpoint = parse_url($_SERVER['REQUEST_URI']);
$path = ltrim($endpoint['path'], "/");

$url = $_SERVER['REQUEST_URI'];
$data = json_decode(file_get_contents('php://input'), true);

if ($url === $class::COMPLETION_WEBHOOK_URL) {
    $this->retry(array($this, 'export_completed_webhook'), $data);
} elseif ($url === $class::WEBHOOK_URL) {
    $this->retry(array($this, 'scan_completed_webhook'), $data);
} elseif ($url === self::PDF_WEBHOOK_URL) {
    $this->retry(array($this, 'download_pdf_webhook'), $data);
} elseif (strpos($url, 'submit') !== false) {
    error_log('submit endpoint');
    $class->retry(array($class, 'submit'), true);
}

// if (strpos($url, 'download')  !== false) {
//     list($id, $filename) = explode("/", $path);
//     error_log('download endpoint');

//     $class->retry(array($class, 'download'));
// } elseif (strpos($url, 'submit') !== false) {
//     error_log('submit endpoint');

//     $class->retry(array($class, 'submit'), true);
// } elseif (strpos($url, 'export') !== false) {
//     error_log('submit endpoint');

//     $class->retry(array($class, 'download'));
// } elseif (strpos($url, 'webhook') !== false) {
//     error_log('webhook pinged!');

//     $data = json_decode(file_get_contents('php://input'), true);

//     list($webhook, $status, $id) = explode("/", $path);

//     if ($status === 'completed') {
//         $class->retry(array($class, 'webhook'), $data);
//         // $class->webhook($data);
//     }
// }
