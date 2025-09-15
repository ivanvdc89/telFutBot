<?php

include './vendor/autoload.php';

use TelegramBot\Api\BotApi;

$telegram = new BotApi('8363817321:AAGIQ7mQ_hTZgXduSiuYKdAEAQyeMS-bAHY');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message->text)) {
    $chatId = $update->message->chat->id;
    $text = $update->message->text;

    if($text === '/rules') {
        $replyMarkup = $telegram->sendPhoto($chatId, "https://ko.ivanvdc.com/files/rules.jpg", "Regles del joc", null, true);
        $replyMarkup = $telegram->sendPhoto($chatId, "https://ko.ivanvdc.com/files/cl.png", "Equips de la Champions", null, true);
        $replyMarkup = $telegram->sendPhoto($chatId, "https://ko.ivanvdc.com/files/el.png", "Equips de la Europa League", null, true);
        $replyMarkup = $telegram->sendPhoto($chatId, "https://ko.ivanvdc.com/files/cl.png", "Equips de la Conference League", null, true);
        exit;
    }

    if($text === '/teams') {
        exit;
    }

    $telegram->sendMessage($chatId, "Per ara millor utilitzar els botons -> /start");
}

?>

