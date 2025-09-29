<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");
require_once("models/action.php");

use TelegramBot\Api\BotApi;

$telegram = new BotApi('%TOKEN_ID');

$groupRepo   = new Group();
$playersRepo = new Player();
$teamsRepo   = new Team();
$actionsRepo = new Action();

$group          = $groupRepo->getGroup(1);
$groupChatId    = $group[0]['chat_id'];
$message        = "Ningú ha fet canvis esta setmana.\n";

$telegram->sendMessage($groupChatId, $message);
