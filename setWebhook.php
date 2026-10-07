<?php

require_once("config/secrets.php");

$token = secret('telegram_token');

$url = 'https://ko.ivanvdc.com/basic.php';

$query = "https://api.telegram.org/bot$token/setWebhook?url=$url";
$response = file_get_contents($query);

echo $response;

?>
