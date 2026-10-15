<?php

/**
 * Publica al grup la classificació de la jornada i els punts de cada jugador.
 *
 *     php publishResults.php
 *
 * Només llegeix: no escriu res a la base de dades, aixina que es pot tornar a
 * executar sempre que calga (reenvia els missatges). El text viu a
 * config/publishResults.php, que es pot provar sense base de dades ni Telegram.
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/secrets.php");
require_once("config/matchDay.php");
require_once("config/publishResults.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");
require_once("models/matchDayPlayerPoint.php");
require_once("models/matchDayTeamPoint.php");

use TelegramBot\Api\BotApi;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$telegram = new BotApi(secret('telegram_token'));

$groupRepo                = new Group();
$playersRepo              = new Player();
$teamsRepo                = new Team();
$matchDayPlayerPointsRepo = new MatchDayPlayerPoint();
$matchDayTeamPointsRepo   = new MatchDayTeamPoint();

$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];
$matchDay    = currentMatchDay();

$playerPoints = $matchDayPlayerPointsRepo->getAllMatchDayPlayerPoints($matchDay);

if (count($playerPoints) === 0) {
    echo "No hi ha punts calculats per a la jornada $matchDay: no s'envia res.\n";
    exit;
}

// Tots els noms, d'una consulta per taula: el joc és petit i aixina no es repeteix
// cap consulta per cada jugador i cada equip.
$playerNames = [];
foreach ($playersRepo->getAllPlayers() as $player) {
    $playerId               = (int) $player['id'];
    $playerNames[$playerId] = (string) ($player['name'] ?? ('#' . $playerId));
}

$teamNames = [];
foreach ($teamsRepo->getAllTeams() as $team) {
    $teamNames[(int) $team['id']] = (string) $team['name'];
}

$teamPointsByPlayer = [];
foreach ($playerPoints as $row) {
    $playerId = (int) $row['player_id'];

    $teamPointsByPlayer[$playerId] =
        $matchDayTeamPointsRepo->getAllMatchDayTeamPointsByPlayerIdAndMatchDay($playerId, $matchDay);
}

foreach (resultsMessages($playerPoints, $teamPointsByPlayer, $playerNames, $teamNames, $matchDay) as $message) {
    // El parse_mode ve de config/publishResults.php: les classificacions van en
    // un bloc de codi (HTML) perquè les columnes s'alineen.
    $telegram->sendMessage($groupChatId, $message['text'], $message['parse_mode']);
}
