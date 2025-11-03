<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");
require_once("models/action.php");

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

$pots           = [1,2,3,4,5,6,7,8,9,10,11,12];
$potNumber      = [0,1,2,3,4,1,2,3,4,1,2,3,4];
$potCompetition = ['X', 'CHL', 'CHL', 'CHL', 'CHL', 'EUL', 'EUL', 'EUL', 'EUL', 'COL', 'COL', 'COL', 'COL'];

$telegram = new BotApi('%TOKEN_ID');

$update = json_decode(file_get_contents('php://input'));

$playersRepo       = new Player();
$substitutionsRepo = new Substitution();
$teamsRepo         = new Team();
$actionsRepo       = new Action();

$matchDay = 5;

if(isset($update->message->text) && $update->message->chat->type === "private") {
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
        if(!isset($args[1])) {
            $keyboard = new ReplyKeyboardMarkup(
                [['/rules basic', '/rules actions']], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Normes disponibles:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } else {
            if ($args[1] === 'basic') {
                $telegram->sendPhoto($chatId, new CURLFile("files/rules.jpg"), "Regles del joc");
                $telegram->sendPhoto($chatId, new CURLFile("files/ch.png"), "Equips de la Champions");
                $telegram->sendPhoto($chatId, new CURLFile("files/el.png"), "Equips de la Europa League");
                $telegram->sendPhoto($chatId, new CURLFile("files/cl.png"), "Equips de la Conference League");
                exit;
            }
            elseif ($args[1] === 'actions') {
                $telegram->sendMessage($chatId, "Norma #malDia:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-L'objectiu és salvar aquells que vagen a tindre una jornada roina per a que no queden despenjats.
-S'ha de tindre en compte que en Conference les victòries donen 4 punts.
-En la competició que penseu que aneu a fer pocs punts s'active per a sumar més punts.
-Si fas 5 punts (o 6 en Conference) com a màxim => sumes 9 punts (o 12 en Conference).
-Si te passes de 5 (o 6 en Conference) => sumes només 2 punt.
-Només té efectes per a la jornada en la que s'active.

Exemple, s'active el #malDia a la Champions:
-Fent 5 o menys punts -> 9 punts
-Fent més de 5 punts, per exemple 7 -> 2 punt

Exemple, s'active el #malDia a la Conference League:
-Fent 6 o menys punts -> 12 punts
-Fent més de 6 punts, per exemple 9 -> 2 punt");

                $telegram->sendMessage($chatId, "Norma #pitjorÉsMillor:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-S'ha de tindre en compte que en Conference les victòries donen 4 punts.
-En la competició que penseu que aneu a fer pocs punts s'aposte a com de mal heu faran els teus equips dient quants punts faran com a màxim (en cada competició pots dir un número diferent).
-Si fan eixos punts o menys t'assegures sumar 12 (o 16 en Conference) menys els punts que has dit.
-Si te passes sumes els punts que has fet -3 (-4 per en Conference).
-No compense apostar a més de 6 punts (o 8 punts en Conference).
-Només té efectes per a la jornada en la que s'active.

Exemple, jo m'activo el #pitjorÉsMillor a la Champions i dic que faré 5 punts:
-Si faig 5 o menys punts -> Sumaré 12 - 5 = 7 punts
-Si faig més de 5 punts, per exemple 7 -> Sumaré els punts -3 = 4

Exemple, jo m'activo el #pitjorÉsMillor a la Europa League i dic que faré 1 punt:
-Si faig 1 o 0 -> Sumaré 12 - 1 = 11 punts
-Si faig més de 1 punt, per exemple 3 -> Sumaré els punts -3 = 0

Exemple, jo m'activo el #pitjorÉsMillor a la Conference League i dic que faré 8 punts:
-Si faig 8 o menys punts -> Sumaré 16 - 8 = 8 punts
-Si faig més de 8 punts, per exemple 16 -> Sumaré els punts -4 = 12");

                $telegram->sendMessage($chatId, "Norma #socElMillor:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-Entre tots els que s'activen la norma a cada competició, se li sumarà 3 (o 4 en COL) punts al jugador o jugadors que sumen més punts i a la resta se'ls restarà 3 (o 4 en COL) punts.
-Si només seu active 1 jugador, directament sume 3 (o 4 en COL) punts més.
-Només té efectes per a la jornada que s'active.

Exemple, si 3 s'activen el #socElMillor en Champions:
-Els que no s'han activat sumen els punts normals
-Si 3 s'han activat:
  -Jugador A: 10 punts (ha sigut el millor entre els que s'han activat) + 3 = 13 punts
  -Jugador B: 10 punts (ha sigut el millor entre els que s'han activat) + 3 = 13 punts
  -Jugador C: 8 punts (no ha fet el màxim) - 3 = 5 punts");

                $telegram->sendMessage($chatId, "Norma #guanyarOMorir:
-Se use en cada competició de forma individual i independent (se pot activar en les 3 competicions, 2, 1 o cap).
-Per cada victòria sumes un punt més als punts que han fet els 4 equips.
-Per cada empat o derrota restes un punt als punts que han fet els 4 equips.
-Només té efectes per a la jornada que s'active.

Exemples, si t'actives el #guanyarOMorir en Champions:
-Si guanyen 2, 1 empat i 1 derrota => 7 punts + 2 - 2 = 7 punts
-Si guanyen tots => 12 + 4 = 16 punts
-Si perden tots => 0 - 4 = -4 punts
-Si empten tots => 4 - 4 = 0 punts");
                exit;
            }
        }
    }

    elseif ($command === '/teams') {
        $player = $playersRepo->getPlayerByChatId($chatId);
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
            //$message_sender = $update->message->from_user->first_name;
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
        if (count($row) != 0) {
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
                if (count($row) != 0) {
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
                    $teams   = $teamsRepo->getCountPlayerTeamsByPot($args[2]);
                    $message = '';
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
                $players = $playersRepo->getAllPlayers();
                $rows    = [];
                $row     = [];
                $row[]   = '/data player all';
                foreach ($players as $player) {
                    $row[] = '/data player ' . $player['name'];
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
                    "Jugadors participants:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } elseif ($args[1] === 'player') {
                if (isset($args[2]) && is_string($args[2]) && strlen($args[2]) > 0) {
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
            [['/substitution', '/badDay'], ['/winOrDie', '/iAmTheBest']], true, true
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
        $player = $playersRepo->getPlayerByChatId($chatId);

        $pendingSubstitutions = $substitutionsRepo->getPendingSubstitutionsByPlayerId($player[0]['id']);

        if($args[1] === 'remove') {
            if (is_array($pendingSubstitutions) && count($pendingSubstitutions) > 0) {
                $substitutionsRepo->removePendingSubstitution($pendingSubstitutions[0]['id']);
                $telegram->sendMessage($chatId, "Substitució eliminada");
            } else {
                $telegram->sendMessage($chatId, "No tens cap substitució pendent");
            }
            exit;
        }

        if (is_array($pendingSubstitutions) && count($pendingSubstitutions) > 0) {
            $oldTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['old_team_id']);
            $newTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['new_team_id']);
            $message = "Ja tens una substitució pendent:\n";
            $message .= $oldTeam[0]['name'] . " -> " . $newTeam[0]['name'] . "\n";
            $keyboard = new ReplyKeyboardMarkup([['/substitution remove', '/start']], true, true);

            $telegram->sendMessage(
                $chatId,
                $message,
                false,
                null,
                null,
                $keyboard
            );
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

    elseif ($command === '/badDay') {
        $activated = false;
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'badDay');

        if (is_array($actions) && count($actions) == 0) {
            if (!$activated) {
                $telegram->sendMessage($chatId, "No disponible");
                exit;
            }

            if ($args[1] === 'ON') {
                $badDayList[]=$args[2];
                $actionsRepo->addAction($player[0]['id'], $matchDay, 'badDay', json_encode($badDayList));
                $butCHL = '/badDay ' . (in_array('CHL', $badDayList) ? 'OFF' : 'ON') . ' CHL';
                $butEUL = '/badDay ' . (in_array('EUL', $badDayList) ? 'OFF' : 'ON') . ' EUL';
                $butCOL = '/badDay ' . (in_array('COL', $badDayList) ? 'OFF' : 'ON') . ' COL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL, $butCOL]
                ], true, true);
            } else {
                $keyboard =
                    new ReplyKeyboardMarkup([['/badDay ON CHL', '/badDay ON EUL', '/badDay ON COL']], true, true);
            }

            $telegram->sendMessage(
                $chatId,
                "#malDia activar ON o desactivar OFF:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } elseif (count($actions) == 1) {
            $badDayList = json_decode($actions[0]['data'], true);
            $messageClosure = "";
            if ($activated) {
                if ($args[1] === 'ON') {
                    $badDayList[] = $args[2];
                    $badDayList   = array_unique($badDayList);
                } elseif ($args[1] === 'OFF') {
                    $badDayList = array_diff($badDayList, [$args[2]]);
                }
                $actionsRepo->updateAction($actions[0]['id'], json_encode($badDayList));

                $butCHL = '/badDay ' . (in_array('CHL', $badDayList) ? 'OFF' : 'ON') . ' CHL';
                $butEUL = '/badDay ' . (in_array('EUL', $badDayList) ? 'OFF' : 'ON') . ' EUL';
                $butCOL = '/badDay ' . (in_array('COL', $badDayList) ? 'OFF' : 'ON') . ' COL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL, $butCOL]
                ], true, true);

                $messageClosure = "\nActivar ON o desactivar OFF:";
            }

            $message = "Actualment tens el #malDia:\n" .
                "- Champions League: " . (in_array('CHL', $badDayList) ? "activat\n" : "desactivat\n") .
                "- Europa League: " . (in_array('EUL', $badDayList) ? "activat\n" : "desactivat\n") .
                "- Conference League: " . (in_array('COL', $badDayList) ? "activat\n" : "desactivat\n");

            $telegram->sendMessage(
                $chatId,
                $message . $messageClosure,
                false,
                null,
                null,
                $keyboard ?? null
            );
            exit;
        }

        $keyboard = new ReplyKeyboardMarkup(
            [['/badDay ON CHL', '/badDay ON EUL', '/badDay ON COL']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "#malDia activar ON o desactivar OFF:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/iAmTheBest') {
        $activated = false;
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'iAmTheBest');

        if (is_array($actions) && count($actions) == 0) {
            if (!$activated) {
                $telegram->sendMessage($chatId, "No disponible");
                exit;
            }

            if ($args[1] === 'ON') {
                $iAmTheBestList[]=$args[2];
                $actionsRepo->addAction($player[0]['id'], $matchDay, 'iAmTheBest', json_encode($iAmTheBestList));
                $butCHL = '/iAmTheBest ' . (in_array('CHL', $iAmTheBestList) ? 'OFF' : 'ON') . ' CHL';
                $butEUL = '/iAmTheBest ' . (in_array('EUL', $iAmTheBestList) ? 'OFF' : 'ON') . ' EUL';
                $butCOL = '/iAmTheBest ' . (in_array('COL', $iAmTheBestList) ? 'OFF' : 'ON') . ' COL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL, $butCOL]
                ], true, true);
            } else {
                $keyboard =
                    new ReplyKeyboardMarkup([['/iAmTheBest ON CHL', '/iAmTheBest ON EUL', '/iAmTheBest ON COL']], true, true);
            }

            $telegram->sendMessage(
                $chatId,
                "#socElMillor activar ON o desactivar OFF:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } elseif (count($actions) == 1) {
            $iAmTheBestList = json_decode($actions[0]['data'], true);
            $messageClosure = "";
            if ($activated) {
                if ($args[1] === 'ON') {
                    $iAmTheBestList[] = $args[2];
                    $iAmTheBestList   = array_unique($iAmTheBestList);
                } elseif ($args[1] === 'OFF') {
                    $iAmTheBestList = array_diff($iAmTheBestList, [$args[2]]);
                }
                $actionsRepo->updateAction($actions[0]['id'], json_encode($iAmTheBestList));

                $butCHL = '/iAmTheBest ' . (in_array('CHL', $iAmTheBestList) ? 'OFF' : 'ON') . ' CHL';
                $butEUL = '/iAmTheBest ' . (in_array('EUL', $iAmTheBestList) ? 'OFF' : 'ON') . ' EUL';
                $butCOL = '/iAmTheBest ' . (in_array('COL', $iAmTheBestList) ? 'OFF' : 'ON') . ' COL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL, $butCOL]
                ], true, true);

                $messageClosure = "\nActivar ON o desactivar OFF:";
            }

            $message = "Actualment tens el #socElMillor:\n" .
                "- Champions League: " . (in_array('CHL', $iAmTheBestList) ? "activat\n" : "desactivat\n") .
                "- Europa League: " . (in_array('EUL', $iAmTheBestList) ? "activat\n" : "desactivat\n") .
                "- Conference League: " . (in_array('COL', $iAmTheBestList) ? "activat\n" : "desactivat\n");

            $telegram->sendMessage(
                $chatId,
                $message . $messageClosure,
                false,
                null,
                null,
                $keyboard ?? null
            );
            exit;
        }

        $keyboard = new ReplyKeyboardMarkup(
            [['/iAmTheBest ON CHL', '/iAmTheBest ON EUL', '/iAmTheBest ON COL']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "#socElMillor activar ON o desactivar OFF:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/winOrDie') {
        $activated = false;
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'winOrDie');

        if (is_array($actions) && count($actions) == 0) {
            if (!$activated) {
                $telegram->sendMessage($chatId, "No disponible");
                exit;
            }

            if ($args[1] === 'ON') {
                $winOrDietList[]=$args[2];
                $actionsRepo->addAction($player[0]['id'], $matchDay, 'winOrDie', json_encode($winOrDietList));
                $butCHL = '/winOrDie ' . (in_array('CHL', $winOrDietList) ? 'OFF' : 'ON') . ' CHL';
                $butEUL = '/winOrDie ' . (in_array('EUL', $winOrDietList) ? 'OFF' : 'ON') . ' EUL';
                $butCOL = '/winOrDie ' . (in_array('COL', $winOrDietList) ? 'OFF' : 'ON') . ' COL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL, $butCOL]
                ], true, true);
            } else {
                $keyboard =
                    new ReplyKeyboardMarkup([['/winOrDie ON CHL', '/winOrDie ON EUL', '/winOrDie ON COL']], true, true);
            }

            $telegram->sendMessage(
                $chatId,
                "#guanyarOMorir activar ON o desactivar OFF:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } elseif (count($actions) == 1) {
            $winOrDietList = json_decode($actions[0]['data'], true);
            $messageClosure = "";
            if ($activated) {
                if ($args[1] === 'ON') {
                    $winOrDietList[] = $args[2];
                    $winOrDietList   = array_unique($winOrDietList);
                } elseif ($args[1] === 'OFF') {
                    $winOrDietList = array_diff($winOrDietList, [$args[2]]);
                }
                $actionsRepo->updateAction($actions[0]['id'], json_encode($winOrDietList));

                $butCHL = '/winOrDie ' . (in_array('CHL', $winOrDietList) ? 'OFF' : 'ON') . ' CHL';
                $butEUL = '/winOrDie ' . (in_array('EUL', $winOrDietList) ? 'OFF' : 'ON') . ' EUL';
                $butCOL = '/winOrDie ' . (in_array('COL', $winOrDietList) ? 'OFF' : 'ON') . ' COL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL, $butCOL]
                ], true, true);

                $messageClosure = "\nActivar ON o desactivar OFF:";
            }

            $message = "Actualment tens el #guanyarOMorir:\n" .
                       "- Champions League: " . (in_array('CHL', $winOrDietList) ? "activat\n" : "desactivat\n") .
                       "- Europa League: " . (in_array('EUL', $winOrDietList) ? "activat\n" : "desactivat\n") .
                       "- Conference League: " . (in_array('COL', $winOrDietList) ? "activat\n" : "desactivat\n");

            $telegram->sendMessage(
                $chatId,
                $message . $messageClosure,
                false,
                null,
                null,
                $keyboard ?? null
            );
            exit;
        }

        $keyboard = new ReplyKeyboardMarkup(
            [['/winOrDie ON CHL', '/winOrDie ON EUL', '/winOrDie ON COL']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "#guanyarOMorir activar ON o desactivar OFF:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/out') {
        $player = $playersRepo->getPlayerByChatId($chatId);

        $pendingSubstitutions = $substitutionsRepo->getPendingSubstitutionsByPlayerId($player[0]['id']);
        if (is_array($pendingSubstitutions) && count($pendingSubstitutions) > 0) {
            $oldTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['old_team_id']);
            $newTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['new_team_id']);
            $message = "Ja tens una substitució pendent:\n";
            $message .= $oldTeam[0]['name'] . " -> " . $newTeam[0]['name'] . "\n";
            $telegram->sendMessage($chatId, $message);
            exit;
        }

        $oldTeam = $teamsRepo->getTeamByName($args[1]);
        if (!is_array($oldTeam) || count($oldTeam) == 0) {
            $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
            exit;
        }
        $oldTeamId         = $oldTeam[0]['id'];
        $oldTeamPot        = $oldTeam[0]['pot'];
        $oldTeamCountry    = $oldTeam[0]['country'];
        $playerTeams       = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
        $alreadyAddedTeams = array_map(function ($team) {return $team['id'];}, $playerTeams);
        if (!in_array($oldTeamId, $alreadyAddedTeams)) {
            $telegram->sendMessage($chatId, "ERROR, este equip no és teu");
            exit;
        }

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
        if (count($row) != 0) {
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

    elseif ($command === '/in') {
        $player = $playersRepo->getPlayerByChatId($chatId);

        $pendingSubstitutions = $substitutionsRepo->getPendingSubstitutionsByPlayerId($player[0]['id']);
        if (is_array($pendingSubstitutions) && count($pendingSubstitutions) > 0) {
            $oldTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['old_team_id']);
            $newTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['new_team_id']);
            $message = "Ja tens una substitució pendent:\n";
            $message .= $oldTeam[0]['name'] . " -> " . $newTeam[0]['name'] . "\n";
            $telegram->sendMessage($chatId, $message);
            exit;
        }

        $newTeam = $teamsRepo->getTeamByName($args[1]);
        if (!is_array($newTeam) || count($newTeam) == 0) {
            $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
            exit;
        }

        $playerTeams = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
        foreach ($playerTeams as $team) {
            if ($team['pot'] == $newTeam[0]['pot']) {
                $oldTeam = $team;
                break;
            }
        }

        $alreadyAddedTeams = array_map(function ($team) {return $team['id'];}, $playerTeams);
        if (in_array($newTeam[0]['id'], $alreadyAddedTeams)) {
            $telegram->sendMessage($chatId, "ERROR, ja tens aquest equip");
            exit;
        }

        $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $playerTeams);
        $alreadyAddedCountries = array_diff($alreadyAddedCountries, [$oldTeam['country']]);
        if (in_array($newTeam[0]['country'], $alreadyAddedCountries)) {
            $telegram->sendMessage($chatId, "ERROR, ja tens un equip d'aquest país");
            exit;
        }

        $substitutionsRepo->addSubstitution($player[0]['id'], 4, $oldTeam['id'], $newTeam[0]['id'], $newTeam[0]['competition']);

        $telegram->sendMessage($chatId, "Substitució guardada: " . $oldTeam['name'] . " -> " . $newTeam[0]['name']);
        exit;
    }

    elseif ($command === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /start");
}
?>