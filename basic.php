<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

$telegram = new BotApi('%TOKEN_ID');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message->text)) {
    $chatId  = $update->message->chat->id;
    $text    = $update->message->text;
    $args    = explode(" ", $text);
    $command = $args[0];

    if ($command === '/start') {
        $keyboard = new ReplyKeyboardMarkup(
            [['/rules', '/teams'], ['/settings']],
            true,
            true
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
    elseif ($command === '/rules') {
        $telegram->sendPhoto($chatId, new CURLFile("files/rules.jpg"), "Regles del joc");
        $telegram->sendPhoto($chatId, new CURLFile("files/ch.png"), "Equips de la Champions");
        $telegram->sendPhoto($chatId, new CURLFile("files/el.png"), "Equips de la Europa League");
        $telegram->sendPhoto($chatId, new CURLFile("files/cl.png"), "Equips de la Conference League");
        exit;
    }

    elseif ($command === '/teams') {
        $playersRepo = new Player();
        $teamsRepo   = new Team();
        $player      = $playersRepo->getPlayerByChatId($chatId);
        if (is_array($player) and count($player) > 0){
            if (count($player)!=1) {
                $message = "ERROR, avisa al admin";
            } else {
                $message = "Player $player[0]['name']";
            }
        } else {
            $playerID  = $playersRepo->createPlayer($chatId);
            $pot1Teams = $teamsRepo->getTeamsByPot(1);
            $rows = [];
            $chunked = array_chunk($pot1Teams, 3);

            foreach ($chunked as $chunk) {
                $row = [];
                foreach ($chunk as $team) {
                    $row[] = '/add ' . $team['name'];
                }
                $rows[] = $row;
            }

            $keyboard = new ReplyKeyboardMarkup($rows, true, true);

            $telegram->sendMessage(
                $chatId,
                "Pot1 teams:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        }

        $telegram->sendMessage($chatId, $message);
        exit;
    }

    elseif ($command === '/add') {
        if (isset($args[1])) {
            $telegram->sendMessage($chatId, "Add " . $args[1]);
        } else {
            $telegram->sendMessage($chatId, "ERROR, please send Add teamName");
        }
        exit;
    }

    elseif ($command === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /start");
}

?>

