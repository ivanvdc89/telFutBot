<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");
require_once("models/action.php");

use TelegramBot\Api\BotApi;

$telegram = new BotApi('%TOKEN_ID');

$groupRepo        = new Group();
$playersRepo      = new Player();
$teamsRepo        = new Team();
$actionsRepo      = new Action();

$matchDay    = 4;
$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];

$message      = "Accions activades:\n";
$allActions   = $actionsRepo->getActionsByMatchDay($matchDay);
$actionsTexts = [
    'badDay' => 'malDia',
    'iAmTheBest' => 'socElMillor',
];
foreach ($allActions as $action) {
    $player = $playersRepo->getPlayerById($action['player_id']);
    $competitions = json_decode($action['data'], true);
    foreach ($competitions as $competition) {
        $message .= "-" . $player[0]['name'] . ": " . $actionsTexts[$action['type']] . " " . $competition . "\n";
    }
}
$telegram->sendMessage($groupChatId, $message);