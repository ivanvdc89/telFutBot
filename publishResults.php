<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/matchDayTeamPoint.php");

use TelegramBot\Api\BotApi;

$telegram = new BotApi('8363817321:AAGIQ7mQ_hTZgXduSiuYKdAEAQyeMS-bAHY');

$groupRepo                = new Group();
$playersRepo              = new Player();
$matchDayPlayerPointsRepo = new MatchDayPlayerPoint();
$matchDayTeamPointsRepo   = new MatchDayTeamPoint();

$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];
$message     = "Resultats:\n";

$classification = $matchDayPlayerPointsRepo->getAllMatchDayPlayerPoints(1);
$telegram->sendMessage($groupChatId, $message);

