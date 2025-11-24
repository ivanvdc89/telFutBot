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

$matchDay    = 5;
$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];

$message      = "Accions activades:\n";
$allActions   = $actionsRepo->getActionsByMatchDay($matchDay);
$actionsTexts = [
    'badDay' => 'malDia',
    'iAmTheBest' => 'socElMillor',
    'winOrDie' => 'guanyarOMorir',
    'doubleOrNothing' => 'dobleORes',
];

$sumVotes = [
    4 => 2,
    10 => 1,
    56 => 2,
    57 => 1,
    98 => 2,
    88 => 1,
];

foreach ($allActions as $action) {
    $player = $playersRepo->getPlayerById($action['player_id']);
    if ($action['type'] == 'doubleOrNothing') {
        $doubleOrNothingData = json_decode($action['data'], true);
        foreach ($doubleOrNothingData['teams'] as $teamId) {
            if (isset($sumVotes[$teamId])) {
                $sumVotes[$teamId] = $sumVotes[$teamId] + 10;
            } else {
                $sumVotes[$teamId] = 10;
            }
            $team = $teamsRepo->getTeamById($teamId);
            $message .= "-" . $player[0]['name'] . ": 1 vot de " . $actionsTexts[$action['type']] . " a " . $team[0]['name'] . "\n";
        }
        $message .= "\n";
    } else {
        $competitions = json_decode($action['data'], true);
        foreach ($competitions as $competition) {
            $message .= "-" . $player[0]['name'] . ": " . $actionsTexts[$action['type']] . " " . $competition . "\n";
        }
    }
}

$maxCHL = 0;
$teamMaxCHL = 0;
$max2CHL = 0;
$teamMax2CHL = 0;
$maxEUL = 0;
$teamMaxEUL = 0;
$max2EUL = 0;
$teamMax2EUL = 0;
$maxCOL = 0;
$teamMaxCOL = 0;
$max2COL = 0;
$teamMax2COL = 0;
arsort($sumVotes);
foreach ($sumVotes as $teamId => $votes) {
    $team = $teamsRepo->getTeamById($teamId);
    if ($team[0]['competition'] == 'CHL') {
        if ($votes > $maxCHL) {
            $maxCHL = $votes;
            $teamMaxCHL = $team[0];
        } elseif ($votes > $max2CHL) {
            $max2CHL = $votes;
            $teamMax2CHL = $team[0];
        }
    }
    if ($team[0]['competition'] == 'EUL') {
        if ($votes > $maxEUL) {
            $maxEUL = $votes;
            $teamMaxEUL = $team[0];
        } elseif ($votes > $max2EUL) {
            $max2EUL = $votes;
            $teamMax2EUL = $team[0];
        }
    }
    if ($team[0]['competition'] == 'COL') {
        if ($votes > $maxCOL) {
            $maxCOL = $votes;
            $teamMaxCOL = $team[0];
        } elseif ($votes > $max2COL) {
            $max2COL = $votes;
            $teamMax2COL = $team[0];
        }
    }
}
$message .= "\n";
$message .= "-Res CHL: " . $teamMaxCHL['name'] . "\n";
$message .= "-Doble CHL: " . $teamMax2CHL['name'] . "\n";
$message .= "-Res EUL: " . $teamMaxEUL['name'] . "\n";
$message .= "-Doble EUL: " . $teamMax2EUL['name'] . "\n";
$message .= "-Res COL: " . $teamMaxCOL['name'] . "\n";
$message .= "-Doble COL: " . $teamMax2COL['name'] . "\n";

$telegram->sendMessage($groupChatId, $message);