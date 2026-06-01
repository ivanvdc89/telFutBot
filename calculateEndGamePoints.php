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

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$playersRepo             = new Player();
$teamsRepo               = new Team();
$matchDayTeamPointRepo   = new MatchDayTeamPoint();
$matchDayPlayerPointRepo = new MatchDayPlayerPoint();
$matchDay                = 19;

$players = $playersRepo->getAllPlayers();
foreach ($players as $player) {
    $playerId     = $player['id'];
    $lastMatchDay = $matchDayPlayerPointRepo->getLastMatchDayByPlayer($player['id']);
    $chlPoints    = $playerId == 10 ? 20 : 0;
    $eulPoints    = $playerId == 17 ? 20 : 0;
    $colPoints    = $playerId == 9 ? 20 : 0;

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
