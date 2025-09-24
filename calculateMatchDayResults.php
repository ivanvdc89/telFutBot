<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/matchDayTeamPoint.php");
require_once("models/teamResult.php");

$playersRepo            = new Player();
$teamsRepo              = new Team();
$matchDayTeamPointRepo  = new MatchDayTeamPoint();
$teamResultRepo         = new TeamResult();

$players = $playersRepo->getAllPlayers();

foreach ($players as $player) {
    $playerId = $player['id'];
    $playerTeams = $teamsRepo->getTeamsByPlayerId($player['id']);
    foreach ($playerTeams as $team) {
        $pot        = $team['pot'];
        $teamResult = $teamResultRepo->getResultByTeamIdAndMatchday($team['id']);
        if (count($teamResult) == 0) {
            continue;
        }
        $matchDayTeamPointRepo->addMatchDayTeamRPoint(
            $playerId,
            1,
            $pot,
            $team['id'],
            $teamResult[0]['points'],
            '',
            $teamResult[0]['points']
        );
    }
}
