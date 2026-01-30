<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/matchDayTeamPoint.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/teamResult.php");
require_once("models/action.php");
require_once("models/substitution.php");

$playersRepo             = new Player();
$teamsRepo               = new Team();
$matchDayTeamPointRepo   = new MatchDayTeamPoint();
$matchDayPlayerPointRepo = new MatchDayPlayerPoint();
$matchDay                = 9;

$eliminated = [27, 35, 31, 20, 33, 22, 28, 21, 17, 25, 15, 36, 54, 60, 61, 68, 40, 55, 44, 39, 62, 69, 67, 104, 91, 79, 77, 75, 96, 83, 102, 105, 107, 94, 76];
$top8       = [10, 4, 5, 19, 9, 7, 23, 3, 49, 45, 56, 43, 38, 47, 57, 37, 86, 99, 93, 78, 82, 74, 85, 100];

$players = [
    'CHL' => [],
    'EUL' => [],
    'COL' => [],
];

$players = $playersRepo->getAllPlayers();
foreach ($players as $player) {
    $playerId     = $player['id'];
    $lastMatchDay = $matchDayPlayerPointRepo->getLastMatchDayByPlayer($player['id']);
    $playerTeams  = $teamsRepo->getTeamsByPlayerId($player['id']);
    $chlPoints    = 0;
    $eulPoints    = 0;
    $colPoints    = 0;

    foreach ($playerTeams as $team) {
        $pot    = $team['pot'];
        $action = [];
        if (in_array($team['id'], $eliminated)) {
            $points = 0;
            $action = ["type" => "posició", "result" => "eliminat"];
        } elseif (in_array($team['id'], $top8)) {
            $points = 8;
            $action = ["type" => "posició", "result" => "top8"];
        } else {
            $points = 0;
        }

        if ($team['competition'] == 'CHL') {
            $chlPoints += $points;
        } elseif ($team['competition'] == 'EUL') {
            $eulPoints += $points;
        } elseif ($team['competition'] == 'COL') {
            $colPoints += $points;
        }

        $matchDayTeamPointRepo->addMatchDayTeamPoint(
            $playerId,
            $matchDay,
            $pot,
            $team['id'],
            $points,
            empty($action) ? '' : json_encode($action),
            $points
        );
    }

    $matchDayPlayerPointRepo->addMatchDayPlayerPoint(
        $playerId,
        $matchDay,
        $chlPoints,
        json_encode([]),
        $chlPoints,
        $chlPoints + $lastMatchDay['chl_total'],
        $eulPoints,
        json_encode([]),
        $eulPoints,
        $eulPoints + $lastMatchDay['eul_total'],
        $colPoints,
        json_encode([]),
        $colPoints,
        $colPoints + $lastMatchDay['col_total'],
        '',
        $chlPoints + $eulPoints + $colPoints,
        $lastMatchDay['total'] + $chlPoints + $eulPoints + $colPoints
    );
}
