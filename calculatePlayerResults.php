<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");

use TelegramBot\Api\BotApi;

$telegram = new BotApi('%TOKEN_ID');

$groupRepo   = new Group();
$playersRepo = new Player();

$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];
$message     = "Resultats:\n";

$players = $playersRepo->getAllPlayers();
$telegram->sendMessage($groupChatId, "Resultats");

