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
    $playerId     = $player['id'];
    $lastMatchDay = $matchDayPlayerPointRepo->getLastMatchDayByPlayer($player['id']);
    $playerTeams  = $teamsRepo->getTeamsByPlayerId($player['id']);
    $chlPoints    = 0;
    $eulPoints    = 0;
    $colPoints    = 0;
    foreach ($playerTeams as $team) {
        $pot        = $team['pot'];
        $teamResult = $teamResultRepo->getResultByTeamIdAndMatchday($team['id'], 2);
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
            2,
            $pot,
            $team['id'],
            $teamResult[0]['points'],
            '',
            $teamResult[0]['points']
        );
    }
    $matchDayPlayerPointRepo->addMatchDayPlayerPoint(
        $playerId,
        2,
        $chlPoints,
        '',
        $chlPoints,
        $chlPoints + $lastMatchDay['chl_total'],
        $eulPoints,
        '',
        $eulPoints,
        $eulPoints + $lastMatchDay['eul_total'],
        $colPoints,
        '',
        $colPoints,
        $colPoints + $lastMatchDay['col_total'],
        '',
        $eulPoints + $chlPoints + $colPoints,
        $chlPoints + $lastMatchDay['chl_total'] + $eulPoints + $lastMatchDay['eul_total'] + $colPoints + $lastMatchDay['col_total']
    );
}
