<?php

/**
 * Publica al grup el resum de les accions de la jornada en curs.
 *
 *     php publishActions.php
 *
 * Només llegeix: no escriu res a la base de dades, i per tant es pot tornar a
 * executar sempre que calgui (reenvia el missatge sencer). Si no hi ha cap acció
 * a la jornada no envia res.
 *
 * El missatge va per seccions, una per acció, amb el mateix ordre que
 * /normes accions, i els resums de les accions de votació (#dobleORes,
 * #kosAmbEscuts, #millorFinal) surten dels vots de la jornada. Els textos i els
 * recomptes són a config/publishActions.php, que es pot provar sense base de
 * dades.
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/secrets.php");
require_once("config/matchDay.php");
require_once("config/publishActions.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/teamStanding.php");
require_once("models/group.php");
require_once("models/action.php");

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

use TelegramBot\Api\BotApi;

$telegram = new BotApi(secret('telegram_token'));

$groupRepo     = new Group();
$playersRepo   = new Player();
$teamsRepo     = new Team();
$standingsRepo = new TeamStanding();
$actionsRepo   = new Action();

$matchDay    = currentMatchDay();
$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];

$allActions = $actionsRepo->getActionsByMatchDay($matchDay);

// Només es publica la votació que es juga esta jornada (VOTACIO_ACTIVA): les
// files d'un altre tipus no ixen, igual que no es compten a
// calculateMatchDayPoints.
$votacioActiva = votacioActiva();

$allActions = array_values(array_filter(
    $allActions,
    static function (array $action) use ($votacioActiva): bool {
        $votacions = ['kosAndShields', 'kosPais', 'kosPots', 'doubleOrNothing'];

        if (in_array($action['type'], $votacions, true)) {
            return $action['type'] === $votacioActiva;
        }

        return true;
    }
));

if (count($allActions) === 0) {
    echo "No hi ha cap acció activada a la jornada $matchDay: no s'envia res.\n";
    exit;
}

// Tots els noms, d'una consulta per taula. El joc és petit i aixina no es repeteix
// cap consulta per cada acció.
$playerNames = [];
foreach ($playersRepo->getAllPlayers() as $player) {
    $name = (string) ($player['name'] ?? '');

    $playerNames[(int) $player['id']] = $name !== '' ? $name : '#' . $player['id'];
}

$teamNames        = [];
$teamCompetitions = [];
$teamCountries    = [];
$teamPots         = [];
foreach ($teamsRepo->getAllTeams() as $team) {
    $teamNames[(int) $team['id']]        = (string) $team['name'];
    $teamCompetitions[(int) $team['id']] = (string) $team['competition'];
    $teamCountries[(int) $team['id']]    = (string) $team['country'];
    $teamPots[(int) $team['id']]         = (int) $team['pot'];
}

// Regla de posició: els vots que li toquen a cada jugador segons la
// classificació general. Les files noves ja porten els vots desats al JSON;
// esta foto és per a les antigues, que només porten l'equip o la competició.
$allowedVotes = $playersRepo->getAllowedVotesByPlayer();

// Posició de cada equip a la seua competició: desempata els vots empatats de
// #dobleORes i de #kosAmbEscuts. Si la taula encara no té dades, l'empat es
// queda amb l'ordre en què s'ha votat.
$teamRankings = $standingsRepo->getRankingsByTeam();

// Punts de cada equip a la seua competició: si a #kosAmbEscuts no hi ha cap
// vot però algú va obrir l'acció, el boicot són els millors classificats.
$teamPoints = $standingsRepo->getPointsByTeam();

$announcement = actionsAnnouncement(
    $allActions,
    $allowedVotes,
    $playerNames,
    $teamNames,
    $teamCompetitions,
    Player::MIN_VOTES,
    $teamRankings,
    $teamPoints,
    $teamCountries,
    $teamPots
);

$message = actionsPublishText($matchDay, $announcement);

if ($message === '') {
    echo "No hi ha res a publicar a la jornada $matchDay: no s'envia res.\n";
    exit;
}

$telegram->sendMessage($groupChatId, $message);

// El text també surt per consola: si Telegram no el mostra com s'esperava, aquí
// es veu què s'ha enviat.
echo "Publicades les accions de la jornada $matchDay (" . count($allActions) . " files):\n\n";
echo $message . "\n";
