<?php

include './vendor/autoload.php';

use TelegramBot\Api\BotApi;

$telegram = new BotApi('8363817321:AAGIQ7mQ_hTZgXduSiuYKdAEAQyeMS-bAHY');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message->text)) {
    $chatId = $update->message->chat->id;
    $text = $update->message->text;

    $telegram->sendMessage($chatId, "Per ara millor utilitzar els botons -> /start");
}

?>

