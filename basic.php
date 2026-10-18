<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/secrets.php");
require_once("config/matchDay.php");
require_once("config/actions.php");
require_once("config/settings.php");
require_once("config/pots.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");
require_once("models/action.php");
require_once("actions/SubstitutionAction.php");
require_once("actions/SettingsAction.php");
require_once("actions/DataAction.php");
require_once("actions/RulesAction.php");
require_once("actions/DoubleOrNothingAction.php");
require_once("actions/KosAndShieldsAction.php");
require_once("actions/SureToBeQualifiedAction.php");
require_once("actions/BestFinalAction.php");
require_once("actions/BadDayAction.php");
require_once("actions/IAmTheBestAction.php");
require_once("actions/WinOrDieAction.php");

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\ReplyKeyboardRemove;

// Els pots de la temporada són a config/pots.php, que també fan servir les
// accions (DataAction).

$telegram = new BotApi(secret('telegram_token'));

$update = json_decode(file_get_contents('php://input'));

$playersRepo       = new Player();
$substitutionsRepo = new Substitution();
$teamsRepo         = new Team();
$actionsRepo       = new Action();

$matchDay = currentMatchDay();

if(isset($update->message->text) && $update->message->chat->type === "private") {
    $chatId  = $update->message->chat->id;
    $text    = $update->message->text;
    $args    = explode(" ", $text);
    $command = $args[0];

    // Cada acció viu a actions/. El router només comprova si el comandament és
    // seu i, si ho és, tanca la petició aquí perquè no caigui al missatge final
    // de més avall.
    if (SubstitutionAction::handlesCommand($command)) {
        $substitutionAction = new SubstitutionAction(
            $telegram,
            $playersRepo,
            $teamsRepo,
            $substitutionsRepo,
            (int) $chatId,
            $matchDay,
            LAST_MATCH_DAY_WITH_CHANGES,
            SUBSTITUTIONS_OPEN,
            $args
        );
        $substitutionAction->run();
        exit;
    }

    if (DoubleOrNothingAction::handlesCommand($command)) {
        $doubleOrNothingAction = new DoubleOrNothingAction(
            $telegram,
            $playersRepo,
            $teamsRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $doubleOrNothingAction->run();
        exit;
    }

    if (KosAndShieldsAction::handlesCommand($command)) {
        $kosAndShieldsAction = new KosAndShieldsAction(
            $telegram,
            $playersRepo,
            $teamsRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $kosAndShieldsAction->run();
        exit;
    }

    if (SureToBeQualifiedAction::handlesCommand($command)) {
        $sureToBeQualifiedAction = new SureToBeQualifiedAction(
            $telegram,
            $playersRepo,
            $teamsRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $sureToBeQualifiedAction->run();
        exit;
    }

    if (BestFinalAction::handlesCommand($command)) {
        $bestFinalAction = new BestFinalAction(
            $telegram,
            $playersRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $bestFinalAction->run();
        exit;
    }

    if (BadDayAction::handlesCommand($command)) {
        $badDayAction = new BadDayAction(
            $telegram,
            $playersRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $badDayAction->run();
        exit;
    }

    if (IAmTheBestAction::handlesCommand($command)) {
        $iAmTheBestAction = new IAmTheBestAction(
            $telegram,
            $playersRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $iAmTheBestAction->run();
        exit;
    }

    if (WinOrDieAction::handlesCommand($command)) {
        $winOrDieAction = new WinOrDieAction(
            $telegram,
            $playersRepo,
            $actionsRepo,
            (int) $chatId,
            $matchDay,
            $args,
            ACTIONS_OPEN
        );
        $winOrDieAction->run();
        exit;
    }

    if (SettingsAction::handlesCommand($command)) {
        $settingsAction = new SettingsAction(
            $telegram,
            $playersRepo,
            (int) $chatId,
            $matchDay,
            $args
        );
        $settingsAction->run();
        exit;
    }

    if (DataAction::handlesCommand($command)) {
        $dataAction = new DataAction(
            $telegram,
            $playersRepo,
            $teamsRepo,
            (int) $chatId,
            $args
        );
        $dataAction->run();
        exit;
    }

    if (RulesAction::handlesCommand($command)) {
        $rulesAction = new RulesAction(
            $telegram,
            (int) $chatId,
            $args
        );
        $rulesAction->run();
        exit;
    }

    if ($command === '/inici' || $command === '/start') {
        $keyboard = new ReplyKeyboardMarkup(
            [['/accions', '/data', '/equips'], ['/normes', '/configuració']],
            true,
            true
        );
        $telegram->sendMessage(
            $chatId,
            "Que vols fer?",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }
    
    elseif ($command === '/equips' || $command === '/teams') {
        $player = $playersRepo->getPlayerByChatId($chatId);
        if (is_array($player) && count($player) > 0){
            $playerId              = $player[0]['id'];
            $alreadyAddedTeams     = $teamsRepo->getTeamsByPlayerId($playerId);
            $alreadyAddedPots      = array_map(function($team) { return $team['pot']; }, $alreadyAddedTeams);
            $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $alreadyAddedTeams);

            $remainingPots = array_diff(POTS, $alreadyAddedPots);
            if (count($remainingPots) == 0) {
                $message = '';
                foreach ($alreadyAddedTeams as $team) {
                    $pot = POT_NUMBERS[$team['pot']];
                    $message .= $team['name'] . " (" . $team['competition'] . " Pot " . $pot . ")\n";
                }
                $telegram->sendMessage($chatId, $message);
                exit;
            }
            $nextPot = min($remainingPots);
        } else {
            $playerId = $playersRepo->createPlayer($chatId);
            $nextPot = 1;
            $alreadyAddedCountries = [];
        }

        $nextPotTeams = $teamsRepo->getTeamsByPot($nextPot);
        $rows         = [];
        $row          = [];
        foreach ($nextPotTeams as $team) {
            if (!in_array($team['country'], $alreadyAddedCountries)) {
                $row[] = '/add ' . $team['name'];
            }
            if(count($row) == 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if (count($row) != 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $telegram->sendMessage(
            $chatId,
            POT_COMPETITIONS[$nextPot] . " Pot " . POT_NUMBERS[$nextPot] . " equips:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/add') {
        $player = $playersRepo->getPlayerByChatId($chatId);

        if (is_array($player) && count($player) == 0){
            $playerId = $playersRepo->createPlayer($chatId);
        } else {
            $playerId = $player[0]['id'];
        }

        if (isset($args[1])) {
            $newTeam = $teamsRepo->getTeamByName($args[1]);
            if (is_array($newTeam) && count($newTeam) == 1){
                $newTeamId             = $newTeam[0]['id'];
                $newTeamPot            = $newTeam[0]['pot'];
                $newTeamCountry        = $newTeam[0]['country'];
                $alreadyAddedTeams     = $teamsRepo->getTeamsByPlayerId($playerId);
                $alreadyAddedPots      = array_map(function($team) { return $team['pot']; }, $alreadyAddedTeams);
                $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $alreadyAddedTeams);

                if (in_array($newTeamPot, $alreadyAddedPots)) {
                    $telegram->sendMessage($chatId, "ERROR, ja tens un equip del pot " . potLabel($newTeamPot));
                    exit;
                }
                if (in_array($newTeamCountry, $alreadyAddedCountries)) {
                    $telegram->sendMessage($chatId, "ERROR, $newTeamCountry repeated");
                    exit;
                }
                $teamsRepo->addPlayerTeam($playerId, $newTeamId);
                $telegram->sendMessage($chatId, "$args[1] team added");

                $alreadyAddedPots[]      = $newTeamPot;
                $alreadyAddedCountries[] = $newTeamCountry;
                $remainingPots = array_diff(POTS, $alreadyAddedPots);
                if (count($remainingPots) == 0) {
                    $telegram->sendMessage($chatId, "Ja has elegit tots els teus equips!");
                    exit;
                }
                $nextPot = min($remainingPots);
                $nextPotTeams = $teamsRepo->getTeamsByPot($nextPot);
                $rows = [];
                $row  = [];

                foreach ($nextPotTeams as $team) {
                    if (!in_array($team['country'], $alreadyAddedCountries)) {
                        $row[] = '/add ' . $team['name'];
                    }
                    if(count($row) == 3) {
                        $rows[] = $row;
                        $row = [];
                    }
                }
                if (count($row) != 0) {
                    $rows[] = $row;
                }

                $keyboard = new ReplyKeyboardMarkup($rows, true, true);

                $telegram->sendMessage(
                    $chatId,
                    POT_COMPETITIONS[$nextPot] . " Pot " . POT_NUMBERS[$nextPot] . " equips:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } else {
                $telegram->sendMessage($chatId, "ERROR, team not found");
                exit;
            }
        } else {
            $telegram->sendMessage($chatId, "ERROR, please send Add teamName");
            exit;
        }
    }

    elseif ($command === '/accions' || $command === '/actions') {
        // El menú surt dels interruptors, no d'una llista escrita a mà: tancar
        // una fase la treu de la llista tota sola.
        $rows = actionsMenuRows(SUBSTITUTIONS_OPEN, ACTIONS_OPEN);

        if (count($rows) === 0) {
            // Passa a la estona d'aplicar i publicar els canvis. Cal treure el
            // teclat vell, que si no es queda a la pantalla oferint coses que ja
            // no es poden fer.
            $telegram->sendMessage(
                $chatId,
                "Ara mateix no hi ha cap acció disponible.",
                false,
                null,
                null,
                new ReplyKeyboardRemove()
            );
            exit;
        }

        $telegram->sendMessage(
            $chatId,
            "Accions disponibles:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup($rows, true, true)
        );
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /inici");
}
?>