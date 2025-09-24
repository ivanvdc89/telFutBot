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
$teamsRepo                = new Team();
$matchDayPlayerPointsRepo = new MatchDayPlayerPoint();
$matchDayTeamPointsRepo   = new MatchDayTeamPoint();

$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];
$message     = "Resultats:\n";

$allMatchDayPlayerPoints = $matchDayPlayerPointsRepo->getAllMatchDayPlayerPoints(1);
foreach ($allMatchDayPlayerPoints as $allMatchDayPlayerPoint) {
    $player = $playersRepo->getPlayerById($allMatchDayPlayerPoint['player_id']);
    $message .= $player[0]['name'] . ":\n";
    $allMatchDayTeamPoints = $matchDayTeamPointsRepo->getAllMatchDayTeamPointsByPlayerIdAndMatchDay($allMatchDayPlayerPoint['player_id'], 1);
    foreach ($allMatchDayTeamPoints as $allMatchDayTeamPoint) {
        if($allMatchDayTeamPoint['pot'] == 1) {
            $message .=  "CHL:\n";
        }
        if($allMatchDayTeamPoint['pot'] == 5) {
            $message .=  "\nEUL:\n";
        }
        if($allMatchDayTeamPoint['pot'] == 9) {
            $message .=  "\nCOL:\n";
        }
        $team = $teamsRepo->getTeamById($allMatchDayTeamPoint['team_id']);
        $message .= "- " . $team[0]['name'] . "\t\t " . $allMatchDayTeamPoint['points'] . " pts\n";
    }
}
$telegram->sendMessage($groupChatId, $message);

