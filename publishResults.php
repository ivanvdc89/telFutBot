<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/matchDayTeamPoint.php");

use TelegramBot\Api\BotApi;

$telegram = new BotApi('%TOKEN_ID');

$groupRepo                = new Group();
$playersRepo              = new Player();
$teamsRepo                = new Team();
$matchDayPlayerPointsRepo = new MatchDayPlayerPoint();
$matchDayTeamPointsRepo   = new MatchDayTeamPoint();

$group          = $groupRepo->getGroup(1);
$groupChatId    = $group[0]['chat_id'];
$message        = "";
$order          = 1;
$messageBestsCHL = "";
$messageBestsEUL = "";
$messageBestsCOL = "";
$bestCHLPoints  = -1;
$bestEULPoints  = -1;
$bestCOLPoints  = -1;
$messageRanking = "Classificació:\n";

$allMatchDayPlayerPoints = $matchDayPlayerPointsRepo->getAllMatchDayPlayerPoints(1);
foreach ($allMatchDayPlayerPoints as $allMatchDayPlayerPoint) {
    $player = $playersRepo->getPlayerById($allMatchDayPlayerPoint['player_id']);
    $message .= $order . "º =>" . $player[0]['name'] . ":\n";
    $messageRanking .= $order . "º " . $player[0]['name'] . ": " . $allMatchDayPlayerPoint['total'] . "\n";

    if($allMatchDayPlayerPoint['chl_total'] > $bestCHLPoints) {
        $bestCHLPoints   = $allMatchDayPlayerPoint['chl_total'];
        $messageBestsCHL = "Millor CHL (" . $bestCHLPoints . "pts):\n" . $player[0]['name'] . "\n";
    } elseif ($allMatchDayPlayerPoint['chl_total'] === $bestCHLPoints) {
        $messageBestsCHL .= $player[0]['name'] . "\n";
    }

    if($allMatchDayPlayerPoint['eul_total'] > $bestEULPoints) {
        $bestEULPoints   = $allMatchDayPlayerPoint['eul_total'];
        $messageBestsEUL = "Millor EUL (" . $bestEULPoints . "pts):\n" . $player[0]['name'] . "\n";
    } elseif ($allMatchDayPlayerPoint['eul_total'] === $bestEULPoints) {
        $messageBestsEUL .= $player[0]['name'] . "\n";
    }

    if($allMatchDayPlayerPoint['col_total'] > $bestCOLPoints) {
        $bestCOLPoints   = $allMatchDayPlayerPoint['col_total'];
        $messageBestsCOL = "Millor COL (" . $bestCOLPoints . "pts):\n" . $player[0]['name'] . "\n";
    } elseif ($allMatchDayPlayerPoint['col_total'] === $bestCOLPoints) {
        $messageBestsCOL .= $player[0]['name'] . "\n";
    }

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
        $message .= "- " . $team[0]['name'] . " " . $allMatchDayTeamPoint['points'] . " pts\n";
        if($allMatchDayTeamPoint['pot'] == 4) {
            $message .= "- JORNADA: " . $allMatchDayPlayerPoint['chl_sum'] . " pts\n";
            $message .= "- TOTAL: " . $allMatchDayPlayerPoint['chl_total'] . " pts\n";
        }
        if($allMatchDayTeamPoint['pot'] == 8) {
            $message .= "- JORNADA: " . $allMatchDayPlayerPoint['eul_sum'] . " pts\n";
            $message .= "- TOTAL: " . $allMatchDayPlayerPoint['eul_total'] . " pts\n";
        }
        if($allMatchDayTeamPoint['pot'] == 12) {
            $message .= "- JORNADA: " . $allMatchDayPlayerPoint['col_sum'] . " pts\n";
            $message .= "- TOTAL: " . $allMatchDayPlayerPoint['col_total'] . " pts\n";
        }
    }
    $message .= "\n- SUMA JORNADA: " . $allMatchDayPlayerPoint['match_day_total'] . " pts\n";
    $message .= "- TOTAL: " . $allMatchDayPlayerPoint['total'] . " pts\n";

    $telegram->sendMessage($groupChatId, $message);
    $message = "";
    $order++;
}

$telegram->sendMessage($groupChatId, $messageRanking);
$telegram->sendMessage($groupChatId, $messageBestsCHL . "\n" . $messageBestsEUL . "\n" . $messageBestsCOL);
