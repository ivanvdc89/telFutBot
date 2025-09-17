<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

$pots           = [1,2,3,4,5,6,7,8,9,10,11,12];
$potNumber      = [0,1,2,3,4,1,2,3,4,1,2,3,4];
$potCompetition = ['X', 'CH', 'CH', 'CH', 'CH', 'EL', 'EL', 'EL', 'EL', 'CL', 'CL', 'CL', 'CL'];

$telegram = new BotApi('%TOKEN_ID');

$update = json_decode(file_get_contents('php://input'));

if(isset($update->message->text)) {
    $chatId  = $update->message->chat->id;
    $text    = $update->message->text;
    $args    = explode(" ", $text);
    $command = $args[0];

    if ($command === '/start') {
        $keyboard = new ReplyKeyboardMarkup(
            [['/rules', '/teams', '/actions'], ['/data', '/settings']],
            true,
            true
        );
        $telegram->sendMessage(
            $chatId,
            "Accions disponibles actualment:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }
    elseif ($command === '/rules') {
        $telegram->sendPhoto($chatId, new CURLFile("files/rules.jpg"), "Regles del joc");
        $telegram->sendPhoto($chatId, new CURLFile("files/ch.png"), "Equips de la Champions");
        $telegram->sendPhoto($chatId, new CURLFile("files/el.png"), "Equips de la Europa League");
        $telegram->sendPhoto($chatId, new CURLFile("files/cl.png"), "Equips de la Conference League");
        exit;
    }

    elseif ($command === '/teams') {
        $playersRepo = new Player();
        $teamsRepo   = new Team();
        $player      = $playersRepo->getPlayerByChatId($chatId);
        if (is_array($player) && count($player) > 0){
            if (count($player)!=1) {
                $message = "ERROR, avisa al admin";
                $telegram->sendMessage($chatId, $message);
                exit;
            } else {
                $playerId              = $player[0]['id'];
                $alreadyAddedTeams     = $teamsRepo->getTeamsByPlayerId($playerId);
                $alreadyAddedPots      = array_map(function($team) { return $team['pot']; }, $alreadyAddedTeams);
                $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $alreadyAddedTeams);

                $remainingPots = array_diff($pots, $alreadyAddedPots);
                if (count($remainingPots) == 0) {
                    $message = '';
                    foreach ($alreadyAddedTeams as $team) {
                        $pot = $potNumber[$team['pot']];
                        $message .= $team['name'] . " (" . $team['competition'] . " Pot " . $pot . ")\n";
                    }
                    $telegram->sendMessage($chatId, $message);
                    exit;
                }
                $nextPot = min($remainingPots);
            }
        } else {
            $playerId = $playersRepo->createPlayer($chatId);
            $nextPot = 1;
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
        if (count($rows) != 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $telegram->sendMessage(
            $chatId,
            $potCompetition[$nextPot] . " Pot " . $potNumber[$nextPot] . " teams:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/add') {
        $playersRepo = new Player();
        $teamsRepo   = new Team();
        $player      = $playersRepo->getPlayerByChatId($chatId);

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
                    $telegram->sendMessage($chatId, "ERROR, $newTeamPot pot repeated");
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
                $remainingPots = array_diff($pots, $alreadyAddedPots);
                if (count($remainingPots) == 0) {
                    $telegram->sendMessage($chatId, "You have selected all your teams!");
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
                if (count($rows) != 0) {
                    $rows[] = $row;
                }

                $keyboard = new ReplyKeyboardMarkup($rows, true, true);

                $telegram->sendMessage(
                    $chatId,
                    $potCompetition[$nextPot] . " Pot " . $potNumber[$nextPot] . " teams:",
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

    elseif ($command === '/data') {
        if(!isset($args[1])) {
            $keyboard = new ReplyKeyboardMarkup(
                [['/data pots', '/data players']], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Data disponible:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } else {
            if ($args[1] === 'pots') {
                $keyboard = new ReplyKeyboardMarkup(
                    [
                        ['/data pot 1', '/data pot 2', '/data pot 3', '/data pot 4'],
                        ['/data pot 5', '/data pot 6', '/data pot 7', '/data pot 8'],
                        ['/data pot 9', '/data pot 10', '/data pot 11', '/data pot 12']
                    ], true, true
                );
                $telegram->sendMessage(
                    $chatId,
                    "Pots disponibles:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } elseif ($args[1] === 'pot') {
                if (isset($args[2]) && is_numeric($args[2]) && $args[2] >= 1 && $args[2] <= 12) {
                    $teamsRepo = new Team();
                    $teams     = $teamsRepo->getCountPlayerTeamsByPot($args[2]);
                    $message   = '';
                    foreach ($teams as $team) {
                        $message .= $team['total'] . " " . $team['name'] . "\n";
                    }
                    $telegram->sendMessage($chatId, $message);
                    exit;
                } else {
                    $telegram->sendMessage($chatId, "ERROR, pot number not found");
                    exit;
                }
            } elseif ($args[1] === 'players') {
                $playersRepo = new Player();
                $players     = $playersRepo->getAllPlayers();

                $rows  = [];
                $row   = [];
                $row[] = '/data player all';
                foreach ($players as $player) {
                    $row[] = '/data player ' . $player['name'];
                    if(count($row) == 3) {
                        $rows[] = $row;
                        $row = [];
                    }
                }
                if (count($rows) != 0) {
                    $rows[] = $row;
                }

                $keyboard = new ReplyKeyboardMarkup($rows, true, true);
                $telegram->sendMessage(
                    $chatId,
                    "Jugadors participants:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } elseif ($args[1] === 'player') {
                if (isset($args[2]) && is_string($args[2]) && strlen($args[2]) > 0) {
                    $playersRepo = new Player();
                    $teamsRepo   = new Team();
                    if ($args[2] == 'all') {
                        $players = $playersRepo->getAllPlayers();
                        $message = 'Tots els jugadors:';
                        foreach ($players as $player) {
                            $playerTeams = $teamsRepo->getTeamsByPlayerId($player['id']);
                            $message     .= "\n\n" . $player['name'] . ":\n";
                            foreach ($playerTeams as $team) {
                                $pot = $potNumber[$team['pot']];
                                $message .= $team['name'] . " (" . $team['competition'] . " Pot " . $pot . ")\n";
                            }
                        }
                        $telegram->sendMessage($chatId, $message);
                        exit;
                    }
                    $player = $playersRepo->getPlayerByName($args[2]);
                    if (!is_array($player) || count($player) == 0) {
                        $telegram->sendMessage($chatId, "ERROR, player not found");
                        exit;
                    }
                    $playerTeams = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
                    $message     = $player[0]['name'] . ":\n";
                    foreach ($playerTeams as $team) {
                        $pot = $potNumber[$team['pot']];
                        $message .= $team['name'] . " (" . $team['competition'] . " Pot " . $pot . ")\n";
                    }
                    $telegram->sendMessage($chatId, $message);
                    exit;
                } else {
                    $telegram->sendMessage($chatId, "ERROR, player not found");
                    exit;
                }
            } else {
                $telegram->sendMessage($chatId, "ERROR, data command not found");
            }
        }
        exit;
    }

    elseif ($command === '/actions') {
        $keyboard = new ReplyKeyboardMarkup(
            [['/substitution']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "Accions disponibles:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/substitution') {
        $playersRepo       = new Player();
        $substitutionsRepo = new Substitution();
        $teamsRepo         = new Team();
        $player            = $playersRepo->getPlayerByChatId($chatId);

        $pendingSubstitutions = $substitutionsRepo->getPendingSubstitutionsByPlayerId($player[0]['id']);
        if (is_array($pendingSubstitutions) && count($pendingSubstitutions) > 0) {
            $message = "Ja tens una substitució pendent:\n";
            $message .= $pendingSubstitutions[0]['old_team_id'] . " -> " . $pendingSubstitutions[0]['new_team_id'] . "\n";
            $telegram->sendMessage($chatId, $message);
            exit;
        }

        $playerTeams = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
        $rows        = [];
        $row         = [];
        foreach ($playerTeams as $team) {
            $row[] = '/out ' . $team['name'];
            if(count($row) == 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $telegram->sendMessage(
            $chatId,
            "Els teus equips:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/out') {
        $playersRepo       = new Player();
        $substitutionsRepo = new Substitution();
        $teamsRepo         = new Team();
        $player            = $playersRepo->getPlayerByChatId($chatId);

        $pendingSubstitutions = $substitutionsRepo->getPendingSubstitutionsByPlayerId($player[0]['id']);
        if (is_array($pendingSubstitutions) && count($pendingSubstitutions) > 0) {
            $message = "Ja tens una substitució pendent:\n";
            $message .= $pendingSubstitutions[0]['old_team_id'] . " -> " . $pendingSubstitutions[0]['new_team_id'] . "\n";
            $telegram->sendMessage($chatId, $message);
            exit;
        }

        $oldTeam               = $teamsRepo->getTeamByName($args[1]);
        if (!is_array($oldTeam) || count($oldTeam) == 0) {
            $telegram->sendMessage($chatId, "ERROR, team not found");
            exit;
        }
        $oldTeamId             = $oldTeam[0]['id'];
        $oldTeamPot            = $oldTeam[0]['pot'];
        $oldTeamCountry        = $oldTeam[0]['country'];
        $playerTeams           = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
        $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $playerTeams);
        $alreadyAddedCountries = array_diff($alreadyAddedCountries, [$oldTeamCountry]);
        $possibleNewTeams      = $teamsRepo->getTeamsByPot($oldTeamPot);
        $possibleNewTeams      = array_filter($possibleNewTeams, function($team) use ($alreadyAddedCountries, $oldTeamId) {
            return !in_array($team['country'], $alreadyAddedCountries) && $team['id'] != $oldTeamId;
        });

        if (count($possibleNewTeams) == 0) {
            $telegram->sendMessage($chatId, "ERROR, no hi ha possibilitats de substitució");
            exit;
        }

        $rows = [];
        $row  = [];
        foreach ($possibleNewTeams as $team) {
            $row[] = '/in ' . $team['name'];
            if(count($row) == 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if (count($rows) != 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $telegram->sendMessage(
            $chatId,
            "Nou equip:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /start");
}
?>