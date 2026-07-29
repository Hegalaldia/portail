<?php
// Webhook GitHub → git pull automatique
// Token secret à configurer dans GitHub Webhooks

define('SECRET', 'a491e3dd8f691df3630e7cba1c1ec232d00347c6ac9cddb157e6f5476748841b');

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!hash_equals('sha256=' . hash_hmac('sha256', $payload, SECRET), $sig)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$data = json_decode($payload, true);
if (($data['ref'] ?? '') !== 'refs/heads/main') {
    http_response_code(200);
    echo 'Not main branch, ignored';
    exit;
}

$dir = escapeshellarg(__DIR__);
$https_url = 'https://github.com/PyroNiuM64/Heatmap-Hegalaldia.git';
$output = shell_exec("cd $dir && git remote set-url origin " . escapeshellarg($https_url) . " && git pull origin main 2>&1");
echo "OK\n" . $output;
