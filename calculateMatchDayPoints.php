<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/matchDayTeamPoint.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/teamResult.php");

$playersRepo             = new Player();
$teamsRepo               = new Team();
$matchDayTeamPointRepo   = new MatchDayTeamPoint();
$matchDayPlayerPointRepo = new MatchDayPlayerPoint();
$teamResultRepo          = new TeamResult();

$players = $playersRepo->getAllPlayers();

foreach ($players as $player) {
    $playerId    = $player['id'];
    $playerTeams = $teamsRepo->getTeamsByPlayerId($player['id']);
    $chlPoints   = 0;
    $eulPoints   = 0;
    $colPoints   = 0;
    foreach ($playerTeams as $team) {
        $pot        = $team['pot'];
        $teamResult = $teamResultRepo->getResultByTeamIdAndMatchday($team['id']);
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
            1,
            $pot,
            $team['id'],
            $teamResult[0]['points'],
            '',
            $teamResult[0]['points']
        );
    }
    $matchDayPlayerPointRepo->addMatchDayPlayerPoint(
        $playerId,
        1,
        $chlPoints,
        '',
        $chlPoints,
        $eulPoints,
        '',
        $eulPoints,
        $colPoints,
        '',
        $colPoints,
        '',
        $eulPoints + $chlPoints + $colPoints
    );
}
