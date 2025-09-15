<?php

include './vendor/autoload.php';

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

$telegram = new BotApi('%TOKEN_ID');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message->text)) {
    $chatId = $update->message->chat->id;
    $text = $update->message->text;

    if ($text === '/start') {
        $keyboard = new ReplyKeyboardMarkup(
            [['/rules', '/teams'], ['/settings']],
            true, // resize
            true  // one-time keyboard
        );
        $telegram->sendMessage(
            $chatId,
            "Accions disponibles actualment:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }
    elseif ($text === '/rules') {
        $telegram->sendPhoto($chatId, new CURLFile("files/rules.jpg"), "Regles del joc");
        $telegram->sendPhoto($chatId, new CURLFile("files/ch.png"), "Equips de la Champions");
        $telegram->sendPhoto($chatId, new CURLFile("files/el.png"), "Equips de la Europa League");
        $telegram->sendPhoto($chatId, new CURLFile("files/cl.png"), "Equips de la Conference League");
        exit;
    }

    elseif ($text === '/teams') {
        $telegram->sendMessage($chatId, "Equips");
        exit;
    }

    elseif ($text === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /start");
}

?>

