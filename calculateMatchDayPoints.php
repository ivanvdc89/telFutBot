<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/matchDayTeamPoint.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/teamResult.php");
require_once("models/action.php");

$playersRepo             = new Player();
$teamsRepo               = new Team();
$matchDayTeamPointRepo   = new MatchDayTeamPoint();
$matchDayPlayerPointRepo = new MatchDayPlayerPoint();
$teamResultRepo          = new TeamResult();
$actionsRepo             = new Action();
$matchDay                = 3;

$players = $playersRepo->getAllPlayers();

foreach ($players as $player) {
    $playerId     = $player['id'];
    $lastMatchDay = $matchDayPlayerPointRepo->getLastMatchDayByPlayer($player['id']);
    $playerTeams  = $teamsRepo->getTeamsByPlayerId($player['id']);
    $chlPoints    = 0;
    $eulPoints    = 0;
    $colPoints    = 0;
    foreach ($playerTeams as $team) {
        $pot        = $team['pot'];
        $teamResult = $teamResultRepo->getResultByTeamIdAndMatchday($team['id'], $matchDay);
        if (count($teamResult) == 0) {
            continue;
        }
        if($teamResult[0]['competition'] === "CHL"){
            $chlPoints += $teamResult[0]['points'];
        }
        if($teamResult[0]['competition'] === "EUL"){
            $eulPoints += $teamResult[0]['points'];
        }
        if($teamResult[0]['competition'] === "COL"){
            $colPoints += $teamResult[0]['points'];
        }
        $matchDayTeamPointRepo->addMatchDayTeamPoint(
            $playerId,
            $matchDay,
            $pot,
            $team['id'],
            $teamResult[0]['points'],
            '',
            $teamResult[0]['points']
        );
    }
    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'badDay');
    $chlPointsAfterAction = $chlPoints;
    $eulPointsAfterAction = $eulPoints;
    $colPointsAfterAction = $colPoints;
    $chlAction = '';
    $eulAction = '';
    $colAction = '';
    if (isset($actions[0])) {
        $actionData = json_decode($actions[0]['data'], true);
        foreach ($actionData as $competition) {
            if ($competition === "CHL") {
                if ($chlPoints > 5) {
                    $chlAction = '{"type":"malDia","result":"KO"}';
                    $chlPointsAfterAction = 2;
                } else {
                    $chlAction = '{"type":"malDia","result":"OK"}';
                    $chlPointsAfterAction = 9;
                }
            }
            if ($competition === "EUL") {
                if ($eulPoints > 5) {
                    $eulAction = '{"type":"malDia","result":"KO"}';
                    $eulPointsAfterAction = 2;
                } else {
                    $eulAction = '{"type":"malDia","result":"OK"}';
                    $eulPointsAfterAction = 9;
                }
            }
            if ($competition === "COL") {
                if ($colPoints > 6) {
                    $colAction = '{"type":"malDia","result":"KO"}';
                    $colPointsAfterAction = 2;
                } else {
                    $colAction = '{"type":"malDia","result":"OK"}';
                    $colPointsAfterAction = 12;
                }
            }
        }
    }

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'iAmTheBest');
    foreach ($actions as $action) {
        $actionData = json_decode($action['data'], true);
        foreach ($actionData as $competition) {
            if ($competition === "CHL") {
                if ($chlPoints >= 9) {
                    $chlAction            = '{"type":"socElMillor","result":"OK"}';
                    $chlPointsAfterAction += 3;
                } else {
                    $chlAction            = '{"type":"socElMillor","result":"KO"}';
                    $chlPointsAfterAction -= 3;
                }
            }
            if ($competition === "EUL") {
                if ($eulPoints >= 9) {
                    $eulAction = '{"type":"socElMillor","result":"OK"}';
                    $eulPointsAfterAction += 3;
                } else {
                    $eulAction = '{"type":"socElMillor","result":"KO"}';
                    $eulPointsAfterAction -= 3;
                }
            }
            if ($competition === "COL") {
                if ($colPoints >= 12) {
                    $colAction = '{"type":"socElMillor","result":"OK"}';
                    $colPointsAfterAction += 4;
                } else {
                    $colAction = '{"type":"socElMillor","result":"KO"}';
                    $colPointsAfterAction -= 4;
                }
            }
        }
    }

    $matchDayPlayerPointRepo->addMatchDayPlayerPoint(
        $playerId,
        $matchDay,
        $chlPoints,
        $chlAction,
        $chlPointsAfterAction,
        $chlPointsAfterAction + $lastMatchDay['chl_total'],
        $eulPoints,
        $eulAction,
        $eulPointsAfterAction,
        $eulPointsAfterAction + $lastMatchDay['eul_total'],
        $colPoints,
        $colAction,
        $colPointsAfterAction,
        $colPointsAfterAction + $lastMatchDay['col_total'],
        '',
        $chlPointsAfterAction + $eulPointsAfterAction + $colPointsAfterAction,
        $lastMatchDay['total'] + $chlPointsAfterAction + $eulPointsAfterAction + $colPointsAfterAction
    );
}
