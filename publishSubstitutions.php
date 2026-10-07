<?php
/**
 * Anuncia al grup les substitucions ja aplicades de la jornada en curs.
 *
 * No toca player_teams: això ho fa applySubstitutions.php. Aquest script només
 * llegeix el que ja està aplicat i ho publica, i per tant es pot tornar a
 * executar sempre que calgui. Si l'enviament a Telegram falla, tornar-lo a
 * executar reenvia el missatge.
 *
 *     php publishSubstitutions.php
 *
 * Abans això era impossible: el canvi s'aplicava i s'anunciava a la mateixa
 * execució, i com que el canvi ja quedava desat, tornar a executar el script no
 * trobava cap fila pendent i enviava una capçalera buida. Si Telegram fallava,
 * el grup no s'assabentava mai del que s'havia canviat.
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/secrets.php");
require_once("config/matchDay.php");
require_once("models/group.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");

use TelegramBot\Api\BotApi;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$telegram = new BotApi(secret('telegram_token'));

$groupRepo        = new Group();
$playersRepo      = new Player();
$teamsRepo        = new Team();
$substitutionRepo = new Substitution();

$matchDay    = currentMatchDay();
$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];

$substitutions = $substitutionRepo->getExecutedSubstitutionsByMatchDay($matchDay);

// Sense canvis aplicats no s'envia res. Abans s'enviava la capçalera tota sola.
if (count($substitutions) === 0) {
    echo "No hi ha cap substitució aplicada a la jornada $matchDay: no s'envia res.\n";
    exit;
}

$message = "Canvis realitzats:\n";

foreach ($substitutions as $substitution) {
    $player     = $playersRepo->getPlayerById($substitution['player_id']);
    $playerName = $player[0]['name'] ?? ('#' . $substitution['player_id']);

    $message .= "- " . $playerName . ": "
        . $teamsRepo->getTeamName($substitution['old_team_id'])
        . " -> " . $teamsRepo->getTeamName($substitution['new_team_id']) . "\n";
    $message .= "Cost " . $substitution['points_cost'] . "\n\n";
}

$telegram->sendMessage($groupChatId, $message);

echo "Anunciades " . count($substitutions) . " substitucions de la jornada $matchDay.\n";
