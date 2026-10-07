<?php

require_once("config/secrets.php");

// Només per CLI: si es pogués demanar per URL, qualsevol podria forçar que es
// torni a registrar el webhook.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$token = secret('telegram_token');

$url = 'https://ko.ivanvdc.com/basic.php';

$query = "https://api.telegram.org/bot$token/setWebhook?url=$url";
$response = file_get_contents($query);

echo $response;

?>
