<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/group.php");
require_once("models/substitution.php");

use TelegramBot\Api\BotApi;

$telegram = new BotApi('%TOKEN_ID');

$groupRepo        = new Group();
$playersRepo      = new Player();
$teamsRepo        = new Team();
$substitutionRepo = new Substitution();

$matchDay    = 3;
$group       = $groupRepo->getGroup(1);
$groupChatId = $group[0]['chat_id'];
$message     = "Canvis realitzats:\n";

$allSubstitutions = $substitutionRepo->getPendingSubstitutionsByMatchDay($matchDay);

foreach ($allSubstitutions as $substitution) {
    $player  = $playersRepo->getPlayerById($substitution['player_id']);
    $oldTeam = $teamsRepo->getTeamById($substitution['old_team_id']);
    $newTeam = $teamsRepo->getTeamById($substitution['new_team_id']);

    $message .= "-" . $player[0]['name'] . ": " . $oldTeam[0]['name'] . " -> " . $newTeam[0]['name'] . "\n";
    $message .= "Cost " . $substitution['points_cost'] . "\n\n";
    $substitutionRepo->markSubstitutionAsExecuted($substitution['id']);
    $teamsRepo->changePlayerTeam($substitution['player_id'], $substitution['old_team_id'], $substitution['new_team_id']);
}

$telegram->sendMessage($groupChatId, $message);
