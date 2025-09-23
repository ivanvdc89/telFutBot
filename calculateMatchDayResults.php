<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/teamPoint.php");
require_once("models/teamResult.php");

$playersRepo    = new Player();
$teamsRepo      = new Team();
$teamResultRepo = new TeamResult();
$teamPointRepo  = new TeamPoint();

$players = $playersRepo->getAllPlayers();

foreach ($players as $player) {
    $playerId = $player['id'];
    $playerTeams = $teamsRepo->getTeamsByPlayerId($player['id']);
    foreach ($playerTeams as $team) {
        $pot        = $team['pot'];
        $teamPoints = $teamPointRepo->getPointByTeamIdAndMatchday($team['id'], 1);
        if (count($teamPoints) == 0) {
            continue;
        }
        $teamResultRepo->addTeamPoints(
            $playerId,
            1,
            $pot,
            $team['id'],
            $teamPoints[0]['points'],
            '',
            $teamPoints[0]['points']
        );
    }
}
