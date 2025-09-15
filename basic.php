<?php

include './vendor/autoload.php';

use TelegramBot\Api\BotApi;

$telegram = new BotApi('8363817321:AAGIQ7mQ_hTZgXduSiuYKdAEAQyeMS-bAHY');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message->text)) {
    $chatId = $update->message->chat->id;
    $text = $update->message->text;

    if($text === '/rules') {
        $replyMarkup = $telegram->sendPhoto($chatId, "https://example.com/rules.jpg", "Regles del joc", null, true);
        exit;
    }

    if($text === '/teams') {
        $keyboard = [
            ['Option 1', 'Option 2'],
            ['Option 3', 'Option 4']
        ];
        $replyMarkup = $telegram->sendPhoto($keyboard, true, true);
        $telegram->sendMessage($chatId, "Benvingut! Tria una opció:", false, null, null, $replyMarkup);
        exit;
    }

    $telegram->sendMessage($chatId, "Per ara millor utilitzar els botons -> /start");
}

?>

