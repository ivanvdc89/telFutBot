<?php

include './vendor/autoload.php';

use TelegramBot\Api\BotApi;

$telegram = new BotApi('8363817321:AAGIQ7mQ_hTZgXduSiuYKdAEAQyeMS-bAHY');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message)) {
    $chatId = $update->message->chat->id;
    $text = $update->message->text;

    // Echo the received message back to the user
    $telegram->sendMessage($chatId, "You said: " . $text);
}

?>

