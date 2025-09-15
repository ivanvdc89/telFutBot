<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

$telegram = new BotApi('%TOKEN_ID');

$update = json_decode(file_get_contents('php://input'));
$pots   = [1,2,3,4,5,6,7,8,9,10,11,12];

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
                $telegram->sendMessage($chatId, $message);
                exit;
            } else {
                $playerId              = $player[0]['id'];
                $alreadyAddedTeams     = $teamsRepo->getTeamsByPlayerId($playerId);
                $alreadyAddedPots      = array_map(function($team) { return $team['pot']; }, $alreadyAddedTeams);
                $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $alreadyAddedTeams);

                $remainingPots = array_diff($pots, $alreadyAddedPots);
                if (count($remainingPots) == 0) {
                    $message = '';
                    foreach ($alreadyAddedTeams as $team) {
                        $pot = (intval($team['competition']) % 4) ?: 4;
                        $message .= $team['name'] . " (" . $team['competition'] . " Pot " . $pot . ")\n";
                    }
                    $telegram->sendMessage($chatId, $message);
                    exit;
                }
                $nextPot = min($remainingPots);
            }
        } else {
            $playerId = $playersRepo->createPlayer($chatId);
            $nextPot = 1;
        }

        $nextPotTeams = $teamsRepo->getTeamsByPot($nextPot);
        $rows         = [];
        $row          = [];
        foreach ($nextPotTeams as $team) {
            if (!in_array($team['country'], $alreadyAddedCountries)) {
                $row[] = '/add ' . $team['name'];
            }
            if(count($row) == 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if (count($rows) != 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $telegram->sendMessage(
            $chatId,
            "Pot" . $nextPot . " teams:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/add') {
        $playersRepo = new Player();
        $teamsRepo   = new Team();
        $player      = $playersRepo->getPlayerByChatId($chatId);

        if (is_array($player) and count($player) == 0){
            $playerId = $playersRepo->createPlayer($chatId);
        } else {
            $playerId = $player[0]['id'];
        }

        if (isset($args[1])) {
            $newTeam = $teamsRepo->getTeamByName($args[1]);
            if (is_array($newTeam) and count($newTeam) == 1){
                $newTeamId             = $newTeam[0]['id'];
                $newTeamPot            = $newTeam[0]['pot'];
                $newTeamCountry        = $newTeam[0]['country'];
                $alreadyAddedTeams     = $teamsRepo->getTeamsByPlayerId($playerId);
                $alreadyAddedPots      = array_map(function($team) { return $team['pot']; }, $alreadyAddedTeams);
                $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $alreadyAddedTeams);

                if (in_array($newTeamPot, $alreadyAddedPots)) {
                    $telegram->sendMessage($chatId, "ERROR, $newTeamPot pot repeated");
                    exit;
                }
                if (in_array($newTeamCountry, $alreadyAddedCountries)) {
                    $telegram->sendMessage($chatId, "ERROR, $newTeamCountry repeated");
                    exit;
                }
                $teamsRepo->addPlayerTeam($playerId, $newTeamId);
                $telegram->sendMessage($chatId, "$args[1] team added");

                $alreadyAddedPots[] = $newTeamPot;
                $remainingPots = array_diff($pots, $alreadyAddedPots);
                if (count($remainingPots) == 0) {
                    $telegram->sendMessage($chatId, "You have selected all your teams!");
                    exit;
                }
                $nextPot = min($remainingPots);
                $nextPotTeams = $teamsRepo->getTeamsByPot($nextPot);
                $rows = [];
                $row  = [];

                foreach ($nextPotTeams as $team) {
                    if (!in_array($team['country'], $alreadyAddedCountries)) {
                        $row[] = '/add ' . $team['name'];
                    }
                    if(count($row) == 3) {
                        $rows[] = $row;
                        $row = [];
                    }
                }
                if (count($rows) != 0) {
                    $rows[] = $row;
                }

                $keyboard = new ReplyKeyboardMarkup($rows, true, true);

                $telegram->sendMessage(
                    $chatId,
                    "Pot" . $nextPot . " teams:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } else {
                $telegram->sendMessage($chatId, "ERROR, team not found");
                exit;
            }
        } else {
            $telegram->sendMessage($chatId, "ERROR, please send Add teamName");
            exit;
        }
    }

    elseif ($command === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /start");
}

?>

