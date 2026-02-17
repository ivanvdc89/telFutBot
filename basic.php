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

$matchDay = 11;
$actionsActivated = true;

if(isset($update->message->text) && $update->message->chat->type === "private") {
    $chatId  = $update->message->chat->id;
    $text    = $update->message->text;
    $args    = explode(" ", $text);
    $command = $args[0];

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
    
    elseif ($command === '/normes' || $command === '/rules') {
        if(!isset($args[1])) {
            $keyboard = new ReplyKeyboardMarkup(
                [['/normes bàsiques', '/normes accions']], true, true
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
            if ($args[1] === 'bàsiques') {
                $telegram->sendPhoto($chatId, new CURLFile("files/rules.jpg"), "Regles del joc");
                $telegram->sendPhoto($chatId, new CURLFile("files/ch.png"), "Equips de la Champions");
                $telegram->sendPhoto($chatId, new CURLFile("files/el.png"), "Equips de la Europa League");
                $telegram->sendPhoto($chatId, new CURLFile("files/cl.png"), "Equips de la Conference League");
                exit;
            }
            elseif ($args[1] === 'accions') {
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

                $telegram->sendMessage($chatId, "Norma #dobleORes:
-Hi ha una votació a repartir entre els equips de les 3 competicions.
-L'equip més votat de cada competició no sumarà cap punt, el segon més votat de cada competició sumarà doble (0, 2 o 6/8 depenent del seu resultat).
-Tots els vots valen igual. Se pot donar més d'un vot a un equip. Si hi ha empat entre equips (encara que sigue a 0 vots), l'ordre serà el de la classificació.
-Depenent dels punts en la general tenim més vots o menys. 1 vot el primer i després cada 10 punts de diferència el jugador té un vot més. Com a màxim 4 vots");

                $telegram->sendMessage($chatId, "Norma #kosAmbEscuts:
-Hi ha una votació per a boicotejar equips, els 4 més votats no sumaran punts independenment del resultat del seu partit.
-Tots els vots de boicot valen igual. Se pot donar més d'un vot a un equip. Si hi ha empat entre equips, el que ha està millor en la classificació serà l'equip boicotejat.
-Depenent de la nostra posició tenim més vots o menys. 1 vot el primer i després cada 10 punts de diferència el jugador té un vot més. Com a màxim 4.
-Per a protegir-se del boicot se usen escuts. Cada escut protegeix un dels teus equips del boicot i per a tu sumarà igual que sempre.
-Amb només un escut l'equip ja està totalment protegit i sumarà punts.
-No cal posar més d'un escut al mateix equip.");

                $telegram->sendMessage($chatId, "Norma #segurQuePasse:
-Depenent de la nostra posició tenim més o menys punts disponibles. 1 punt el primer i després cada 10 punts de diferència el jugador té un punt més. Com a màxim 4.
-S'ha d'elegir quin dels nostres equips passaran de ronda. Si se classifique se sumaran, a part dels punts del partit, els punts que te toquen.
-Depenent del partit d'anada, si s'acerte que passarà, se sumaran més punts, 1 punt per cada gol de desventaja que porten del primer partit. 1 punt menys per cada gol de ventaja que porten del primer partit.

Per exemple, vas últim i te correspón 4 punts i marques que el equip1 (va perdre 1-0) passarà de ronda:
-Si no passe sumes els punts normal del partit (0, 1 o 3)
-Si passe, a més dels punts del partit, sumaràs els 4 punts que has apostat + 1 perqué tenie un gol de desventaja del primer partit.

Altre exemple, vas primer i te correspón 1 punt i marques que el equip2 (va empatar 1-1) passarà de ronda:
-Si no passe sumes els punts normal del partit (0, 1 o 3)
-Si passe, a més dels punts del partit, sumaràs el punt que s'ha apostat + 0 perqué no tenie cap gol de desventaja del primer partit.

Interese apostar per equips amb mal resultat, si han guanyat el primer partit per bastants gols pot ser no te compense ja que els gols de ventaja resten.");
                exit;
            }
        }
    }

    elseif ($command === '/equips' || $command === '/teams') {
        $player = $playersRepo->getPlayerByChatId($chatId);
        if (is_array($player) && count($player) > 0){
            $playerId              = $player[0]['id'];
            $alreadyAddedTeams     = $teamsRepo->getTeamsByPlayerId($playerId);
            $alreadyAddedPots      = array_map(function($team) { return $team['pot']; }, $alreadyAddedTeams);
            $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $alreadyAddedTeams);

            $remainingPots = array_diff($pots, $alreadyAddedPots);
            //if (count($remainingPots) == 0) {
            $message = '';
            foreach ($alreadyAddedTeams as $team) {
                $pot = $potNumber[$team['pot']];
                $message .= $team['name'] . " (" . $team['competition'] . " Pot " . $pot . ")\n";
            }
            $telegram->sendMessage($chatId, $message);
            exit;
            //}
            //$nextPot = min($remainingPots);
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
            $potCompetition[$nextPot] . " Pot " . $potNumber[$nextPot] . " equips:",
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
                    $potCompetition[$nextPot] . " Pot " . $potNumber[$nextPot] . " equips:",
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
                        $players = $teamsRepo->getPlayersByTeam($team['id']);
                        foreach ($players as $player) {
                            $message .= "   - " . $player['name'] . "\n";
                        }
                        $message .= "\n";
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

    elseif ($command === '/accions' || $command === '/actions') {
        $keyboard = new ReplyKeyboardMarkup(
            [['/substitució', '/segurQuePasse']], true, true
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

    elseif ($command === '/substitució') {
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
            if (!isset($oldTeam[0])) {
                $oldTeamName = 'Empty';
            } else {
                $oldTeamName = $oldTeam[0]['name'];
            }
            $newTeam = $teamsRepo->getTeamById($pendingSubstitutions[0]['new_team_id']);
            $message = "Ja tens una substitució pendent:\n";
            $message .= $oldTeamName . " -> " . $newTeam[0]['name'] . "\n";
            $keyboard = new ReplyKeyboardMarkup([['/substitució remove', '/inici']], true, true);

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
        $emptyPots   = [
            '/out CHL_Pot_1', '/out CHL_Pot_2', '/out CHL_Pot_3', '/out CHL_Pot_4',
            '/out EUL_Pot_1', '/out EUL_Pot_2', '/out EUL_Pot_3', '/out EUL_Pot_4',
            '/out COL_Pot_1', '/out COL_Pot_2', '/out COL_Pot_3', '/out COL_Pot_4'
        ];
        foreach ($playerTeams as $team) {
            $row[] = '/out ' . $team['name'];
            unset($emptyPots[$team['pot']-1]);
            if (count($row) == 3) {
                $rows[] = $row;
                $row    = [];
            }
        }
        foreach ($emptyPots as $pot) {
            $row[] = $pot;
            if (count($row) == 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) != 0) {
            $rows[] = $row;
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

    elseif ($command === '/dobleORes') {
        $actionsActivated = false;

        $player    = $playersRepo->getPlayerByChatId($chatId);
        $actions   = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'doubleOrNothing');
        if (!$actionsActivated || (is_array($actions) && count($actions) == 0)) {
            $telegram->sendMessage($chatId, "No disponible");
            exit;
        }
        $doubleOrNothingData = json_decode($actions[0]['data'], true);

        if ($args[1] == 'borrar') {
            $doubleOrNothingData['teams'] = [];
            $actionsRepo->updateAction($actions[0]['id'], json_encode($doubleOrNothingData));

            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/dobleORes', '/inici']
                ], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Vots borrats",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        }

        if ($doubleOrNothingData['max'] == count($doubleOrNothingData['teams'])) {
            $message = "Vots màxims: " . $doubleOrNothingData['max'] . "\n" .
                       "Equipts votats:\n";

            foreach ($doubleOrNothingData['teams'] as $team) {
                $teamInfo = $teamsRepo->getTeamById($team);
                $message .= "- " . $teamInfo[0]['name'] . "\n";
            }

            $telegram->sendMessage(
                $chatId,
                $message,
                false,
                null,
                null,
                null
            );

            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/dobleORes borrar', '/inici']
                ], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Ja tens tots els vots fets",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        }

        if(!isset($args[1])) {
            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/dobleORes pot 1', '/dobleORes pot 2', '/dobleORes pot 3', '/dobleORes pot 4'],
                    ['/dobleORes pot 5', '/dobleORes pot 6', '/dobleORes pot 7', '/dobleORes pot 8'],
                    ['/dobleORes pot 9', '/dobleORes pot 10', '/dobleORes pot 11', '/dobleORes pot 12']
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
        } elseif ($args[1] == 'pot') {
            if (isset($args[2]) && is_numeric($args[2]) && $args[2] >= 1 && $args[2] <= 12) {
                $teams   = $teamsRepo->getTeamsByPot($args[2]);
                $rows    = [];
                $row     = [];
                foreach ($teams as $team) {
                    $row[] = '/dobleORes vot ' . $team['name'];
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
                    "Equips del pot " . $args[2] . ":",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } else {
                $telegram->sendMessage($chatId, "ERROR, pot invàlid");
                exit;
            }
        } elseif ($args[1] == 'vot') {
            $team = $teamsRepo->getTeamByName($args[2]);
            if (!is_array($team) || count($team) == 0) {
                $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
                exit;
            }
            $doubleOrNothingData['teams'][] = $team[0]['id'];
            $actionsRepo->updateAction($actions[0]['id'], json_encode($doubleOrNothingData));

            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/dobleORes', '/inici']
                ], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Vot guardat",
                false,
                null,
                null,
                $keyboard
            );

            $message = "Vots màxims: " . $doubleOrNothingData['max'] . "\n" .
                       "Equipts votats:\n";

            foreach ($doubleOrNothingData['teams'] as $team) {
                $teamInfo = $teamsRepo->getTeamById($team);
                $message .= "- " . $teamInfo[0]['name'] . "\n";
            }

            $telegram->sendMessage(
                $chatId,
                $message,
                false,
                null,
                null,
                null
            );
            exit;

        }
    }

    elseif ($command === '/kos') {
        $actionsActivated = false;
        $player    = $playersRepo->getPlayerByChatId($chatId);
        $actions   = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'kosAndShields');
        if (!$actionsActivated || (is_array($actions) && count($actions) == 0)) {
            $telegram->sendMessage($chatId, "No disponible");
            exit;
        }
        $kosAndShieldsData = json_decode($actions[0]['data'], true);

        if ($args[1] == 'borrar') {
            $kosAndShieldsData['kos'] = [];
            $kosAndShieldsData['shields'] = [];
            $actionsRepo->updateAction($actions[0]['id'], json_encode($kosAndShieldsData));

            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/kos', '/inici']
                ], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Vots i escuts borrats",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        }

        if ($kosAndShieldsData['max'] == count($kosAndShieldsData['kos']) && $kosAndShieldsData['max'] == count($kosAndShieldsData['shields'])) {
            $message = "Vots màxims: " . $kosAndShieldsData['max'] . "\n" .
                       "Equipts votats:\n";
            foreach ($kosAndShieldsData['kos'] as $team) {
                $teamInfo = $teamsRepo->getTeamById($team);
                $message .= "- " . $teamInfo[0]['name'] . "\n";
            }

            $message .= "\nEscuts:\n";
            foreach ($kosAndShieldsData['shields'] as $team) {
                $teamInfo = $teamsRepo->getTeamById($team);
                $message .= "- " . $teamInfo[0]['name'] . "\n";
            }

            $telegram->sendMessage(
                $chatId,
                $message,
                false,
                null,
                null,
                null
            );

            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/kos borrar', '/inici']
                ], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Ja tens tots els vots fets",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        }

        if(!isset($args[1])) {
            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/kos ko_pot 1', '/kos ko_pot 2', '/kos ko_pot 3', '/kos ko_pot 4'],
                    ['/kos ko_pot 5', '/kos ko_pot 6', '/kos ko_pot 7', '/kos ko_pot 8'],
                    ['/kos ko_pot 9', '/kos ko_pot 10', '/kos ko_pot 11', '/kos ko_pot 12'],
                    ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos']
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
        }
        elseif ($args[1] == 'ko_pot') {
            if($kosAndShieldsData['max'] == count($kosAndShieldsData['kos'])) {
                $keyboard = new ReplyKeyboardMarkup(
                    [
                        ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos']
                    ], true, true
                );
                $telegram->sendMessage(
                    $chatId,
                    "Ja has fet el màxim de vots de kos, ara pots posar escuts si vols:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            }
            if (isset($args[2]) && is_numeric($args[2]) && $args[2] >= 1 && $args[2] <= 12) {
                $teams   = $teamsRepo->getTeamsByPot($args[2]);
                $rows    = [];
                $row     = [];
                foreach ($teams as $team) {
                    $row[] = '/kos ko_vot ' . $team['name'];
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
                    "Equips del pot " . $args[2] . ":",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            } else {
                $telegram->sendMessage($chatId, "ERROR, pot invàlid");
                exit;
            }
        }
        elseif ($args[1] == 'ko_vot') {
            if($kosAndShieldsData['max'] == count($kosAndShieldsData['kos'])) {
                $keyboard = new ReplyKeyboardMarkup(
                    [
                        ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos']
                    ], true, true
                );
                $telegram->sendMessage(
                    $chatId,
                    "Ja has fet el màxim de vots de kos, ara pots posar escuts si vols:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            }
            $team = $teamsRepo->getTeamByName($args[2]);
            if (!is_array($team) || count($team) == 0) {
                $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
                exit;
            }
            $kosAndShieldsData['kos'][] = $team[0]['id'];
            $actionsRepo->updateAction($actions[0]['id'], json_encode($kosAndShieldsData));

            $keyboard = new ReplyKeyboardMarkup(
                [
                    ['/kos', '/inici']
                ], true, true
            );
            $telegram->sendMessage(
                $chatId,
                "Vot guardat",
                false,
                null,
                null,
                $keyboard
            );

            $message = "Vots màxims: " . $kosAndShieldsData['max'] . "\n" .
                       "Equipts votats:\n";

            foreach ($kosAndShieldsData['kos'] as $team) {
                $teamInfo = $teamsRepo->getTeamById($team);
                $message .= "- " . $teamInfo[0]['name'] . "\n";
            }

            $message .= "\nEscuts:\n";
            foreach ($kosAndShieldsData['shields'] as $team) {
                $teamInfo = $teamsRepo->getTeamById($team);
                $message .= "- " . $teamInfo[0]['name'] . "\n";
            }

            $telegram->sendMessage(
                $chatId,
                $message,
                false,
                null,
                null,
                null
            );
            exit;
        }
        elseif ($args[1] == 'escut') {
            if ($kosAndShieldsData['max'] == count($kosAndShieldsData['shields'])) {
                $keyboard = new ReplyKeyboardMarkup(
                    [
                        [
                            '/kos ko_pot 1',
                            '/kos ko_pot 2',
                            '/kos ko_pot 3',
                            '/kos ko_pot 4'
                        ],
                        [
                            '/kos ko_pot 5',
                            '/kos ko_pot 6',
                            '/kos ko_pot 7',
                            '/kos ko_pot 8'
                        ],
                        [
                            '/kos ko_pot 9',
                            '/kos ko_pot 10',
                            '/kos ko_pot 11',
                            '/kos ko_pot 12'
                        ]
                    ], true, true
                );
                $telegram->sendMessage(
                    $chatId,
                    "Ja has posat el màxim d'escuts, ara pots votat els kos si vols:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            }

            if (isset($args[2]) && in_array($args[2], ['CHL', 'EUL', 'COL'])) {
                $playerTeams = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
                $rows        = [];
                $row         = [];
                foreach ($playerTeams as $team) {
                    if (in_array($team['id'], $kosAndShieldsData['shields'])) {
                        continue;
                    }
                    if ($team['competition'] !== $args[2]) {
                        continue;
                    }
                    $row[] = '/kos escut ' . $team['name'];
                    if (count($row) == 3) {
                        $rows[] = $row;
                        $row    = [];
                    }
                }
                if (count($row) != 0) {
                    $rows[] = $row;
                }

                $keyboard = new ReplyKeyboardMarkup($rows, true, true);

                $telegram->sendMessage(
                    $chatId,
                    "Els teus equips per a posar escut:",
                    false,
                    null,
                    null,
                    $keyboard
                );
                exit;
            }
            elseif (isset($args[2])) {
                $team = $teamsRepo->getTeamByName($args[2]);
                if (!is_array($team) || count($team) == 0) {
                    $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
                    exit;
                }

                if (in_array($args[2], $kosAndShieldsData['shields'])) {
                    $telegram->sendMessage($chatId, "ERROR, l'equip ja té l'escut activat");
                    exit;
                }

                $kosAndShieldsData['shields'][] = $team[0]['id'];
                $actionsRepo->updateAction($actions[0]['id'], json_encode($kosAndShieldsData));

                $keyboard = new ReplyKeyboardMarkup(
                    [
                        ['/kos', '/inici']
                    ], true, true
                );
                $telegram->sendMessage(
                    $chatId,
                    "Escut guardat",
                    false,
                    null,
                    null,
                    $keyboard
                );

                $message = "Vots màxims: " . $kosAndShieldsData['max'] . "\n" . "Equipts votats:\n";

                foreach ($kosAndShieldsData['kos'] as $team) {
                    $teamInfo = $teamsRepo->getTeamById($team);
                    $message  .= "- " . $teamInfo[0]['name'] . "\n";
                }

                $message .= "\nEscuts:\n";
                foreach ($kosAndShieldsData['shields'] as $team) {
                    $teamInfo = $teamsRepo->getTeamById($team);
                    $message  .= "- " . $teamInfo[0]['name'] . "\n";
                }

                $telegram->sendMessage(
                    $chatId,
                    $message,
                    false,
                    null,
                    null,
                    null
                );
                exit;
            }
        }
    }

    elseif ($command === '/malDia') {
        $actionsActivated = false;
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'badDay');

        if (is_array($actions) && count($actions) == 0) {
            if (!$actionsActivated) {
                $telegram->sendMessage($chatId, "No disponible");
                exit;
            }

            if ($args[1] === 'Activar') {
                $badDayList[]=$args[2];
                $actionsRepo->addAction($player[0]['id'], $matchDay, 'badDay', json_encode($badDayList));
                $butCHL = '/malDia ' . (in_array('CHL', $badDayList) ? 'Desactivar' : 'Activar') . ' CHL';
                $butEUL = '/malDia ' . (in_array('EUL', $badDayList) ? 'Desactivar' : 'Activar') . ' EUL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL]
                ], true, true);
            } else {
                $keyboard =
                    new ReplyKeyboardMarkup([['/malDia Activar CHL', '/malDia Activar EUL']], true, true);
            }

            $telegram->sendMessage(
                $chatId,
                "#malDia activar o desactivar:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } elseif (count($actions) == 1) {
            $badDayList = json_decode($actions[0]['data'], true);
            $messageClosure = "";
            if ($actionsActivated) {
                if ($args[1] === 'Activar') {
                    $badDayList[] = $args[2];
                    $badDayList   = array_unique($badDayList);
                } elseif ($args[1] === 'Desactivar') {
                    $badDayList = array_diff($badDayList, [$args[2]]);
                }
                $actionsRepo->updateAction($actions[0]['id'], json_encode($badDayList));

                $butCHL = '/malDia ' . (in_array('CHL', $badDayList) ? 'Desactivar' : 'Activar') . ' CHL';
                $butEUL = '/malDia ' . (in_array('EUL', $badDayList) ? 'Desactivar' : 'Activar') . ' EUL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL]
                ], true, true);

                $messageClosure = "\nActivar o desactivar:";
            }

            $message = "Actualment tens el #malDia:\n" .
                "- Champions League: " . (in_array('CHL', $badDayList) ? "activat\n" : "desactivat\n") .
                "- Europa League: " . (in_array('EUL', $badDayList) ? "activat\n" : "desactivat\n");

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
            [['/malDia Activar CHL', '/malDia Activar EUL']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "#malDia activar o desactivar:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/socElMillor') {
        $actionsActivated = false;
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'iAmTheBest');

        if (is_array($actions) && count($actions) == 0) {
            if (!$actionsActivated) {
                $telegram->sendMessage($chatId, "No disponible");
                exit;
            }

            if ($args[1] === 'Activar') {
                $iAmTheBestList[]=$args[2];
                $actionsRepo->addAction($player[0]['id'], $matchDay, 'iAmTheBest', json_encode($iAmTheBestList));
                $butCHL = '/socElMillor ' . (in_array('CHL', $iAmTheBestList) ? 'Desactivar' : 'Activar') . ' CHL';
                $butEUL = '/socElMillor ' . (in_array('EUL', $iAmTheBestList) ? 'Desactivar' : 'Activar') . ' EUL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL]
                ], true, true);
            } else {
                $keyboard =
                    new ReplyKeyboardMarkup([['/socElMillor Activar CHL', '/socElMillor Activar EUL']], true, true);
            }

            $telegram->sendMessage(
                $chatId,
                "#socElMillor activar o desactivar:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } elseif (count($actions) == 1) {
            $iAmTheBestList = json_decode($actions[0]['data'], true);
            $messageClosure = "";
            if ($actionsActivated) {
                if ($args[1] === 'Activar') {
                    $iAmTheBestList[] = $args[2];
                    $iAmTheBestList   = array_unique($iAmTheBestList);
                } elseif ($args[1] === 'Desactivar') {
                    $iAmTheBestList = array_diff($iAmTheBestList, [$args[2]]);
                }
                $actionsRepo->updateAction($actions[0]['id'], json_encode($iAmTheBestList));

                $butCHL = '/socElMillor ' . (in_array('CHL', $iAmTheBestList) ? 'Desactivar' : 'Activar') . ' CHL';
                $butEUL = '/socElMillor ' . (in_array('EUL', $iAmTheBestList) ? 'Desactivar' : 'Activar') . ' EUL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL]
                ], true, true);

                $messageClosure = "\nActivar o desactivar:";
            }

            $message = "Actualment tens el #socElMillor:\n" .
                "- Champions League: " . (in_array('CHL', $iAmTheBestList) ? "activat\n" : "desactivat\n") .
                "- Europa League: " . (in_array('EUL', $iAmTheBestList) ? "activat\n" : "desactivat\n");

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
            [['/socElMillor Activar CHL', '/socElMillor Activar EUL']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "#socElMillor activar o desactivar:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/guanyarOMorir') {
        $actionsActivated = false;
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'winOrDie');

        if (is_array($actions) && count($actions) == 0) {
            if (!$actionsActivated) {
                $telegram->sendMessage($chatId, "No disponible");
                exit;
            }

            if ($args[1] === 'Activar') {
                $winOrDietList[]=$args[2];
                $actionsRepo->addAction($player[0]['id'], $matchDay, 'winOrDie', json_encode($winOrDietList));
                $butCHL = '/guanyarOMorir ' . (in_array('CHL', $winOrDietList) ? 'Desactivar' : 'Activar') . ' CHL';
                $butEUL = '/guanyarOMorir ' . (in_array('EUL', $winOrDietList) ? 'Desactivar' : 'Activar') . ' EUL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL]
                ], true, true);
            } else {
                $keyboard =
                    new ReplyKeyboardMarkup([['/guanyarOMorir Activar CHL', '/guanyarOMorir Activar EUL']], true, true);
            }

            $telegram->sendMessage(
                $chatId,
                "#guanyarOMorir activar o desactivar:",
                false,
                null,
                null,
                $keyboard
            );
            exit;
        } elseif (count($actions) == 1) {
            $winOrDietList = json_decode($actions[0]['data'], true);
            $messageClosure = "";
            if ($actionsActivated) {
                if ($args[1] === 'Activar') {
                    $winOrDietList[] = $args[2];
                    $winOrDietList   = array_unique($winOrDietList);
                } elseif ($args[1] === 'Desactivar') {
                    $winOrDietList = array_diff($winOrDietList, [$args[2]]);
                }
                $actionsRepo->updateAction($actions[0]['id'], json_encode($winOrDietList));

                $butCHL = '/guanyarOMorir ' . (in_array('CHL', $winOrDietList) ? 'Desactivar' : 'Activar') . ' CHL';
                $butEUL = '/guanyarOMorir ' . (in_array('EUL', $winOrDietList) ? 'Desactivar' : 'Activar') . ' EUL';
                $keyboard = new ReplyKeyboardMarkup([
                    [$butCHL, $butEUL]
                ], true, true);

                $messageClosure = "\nActivar o desactivar:";
            }

            $message = "Actualment tens el #guanyarOMorir:\n" .
                       "- Champions League: " . (in_array('CHL', $winOrDietList) ? "activat\n" : "desactivat\n") .
                       "- Europa League: " . (in_array('EUL', $winOrDietList) ? "activat\n" : "desactivat\n");

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
            [['/guanyarOMorir Activar CHL', '/guanyarOMorir Activar EUL']], true, true
        );
        $telegram->sendMessage(
            $chatId,
            "#guanyarOMorir activar o desactivar:",
            false,
            null,
            null,
            $keyboard
        );
        exit;
    }

    elseif ($command === '/segurQuePasse') {
        //$actionsActivated = false;
        if (!$actionsActivated) {
            $telegram->sendMessage($chatId, "No disponible");
            exit;
        }
        $player  = $playersRepo->getPlayerByChatId($chatId);
        $actions = $actionsRepo->getActionsByPlayerId($player[0]['id'], $matchDay, 'sureToBeQualified');

        if (is_array($actions) && count($actions) == 1) {
            $sureToBeQualifiedInfo = json_decode($actions[0]['data'], true);
            if (count($sureToBeQualifiedInfo) == 1) {
                if ($args[1] == 'Borrar') {
                    $actionsRepo->updateAction($actions[0]['id'], json_encode([]));
                    unset($args[1]);
                }  else {
                    $keyboard = new ReplyKeyboardMarkup([['/segurQuePasse Borrar']], true, true);
                    $teamInfo = $teamsRepo->getTeamById($sureToBeQualifiedInfo[0]);
                    $message  = "Actualment tens activat el #segurQuePasse amb el " . $teamInfo[0]['name'] . "\n";

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
            } elseif (isset($args[1])) {
                $team = $teamsRepo->getTeamByName($args[1]);
                if (!is_array($team) || count($team) == 0) {
                    $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
                    exit;
                }
                $actionsRepo->updateAction($player[0]['id'], json_encode([$team[0]['id']]));

                $message = "Has activat el #segurQuePasse amb el " . $team[0]['name'] . "\n";

                $telegram->sendMessage(
                    $chatId,
                    $message,
                    false,
                    null,
                    null,
                    null
                );
                exit;
            }
        }

        if (isset($args[1])) {
            $team = $teamsRepo->getTeamByName($args[1]);
            if (!is_array($team) || count($team) == 0) {
                $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
                exit;
            }
            $actionsRepo->addAction($player[0]['id'], $matchDay, 'sureToBeQualified', json_encode([$team[0]['id']]));

            $message = "Has activat el #segurQuePasse amb el " . $team[0]['name'] . "\n";

            $telegram->sendMessage(
                $chatId,
                $message,
                false,
                null,
                null,
                null
            );
            exit;
        }

        $playerTeams = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
        $rows        = [];
        $row         = [];
        foreach ($playerTeams as $team) {
            $row[] = '/segurQuePasse ' . $team['name'];
            if (count($row) == 3) {
                $rows[] = $row;
                $row    = [];
            }
        }
        if (count($row) != 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);
        $telegram->sendMessage(
            $chatId,
            "#segurQuePasse activar amb quin equip:",
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

        if (str_contains($args[1], '_Pot_')) {
            if ($args[1] == 'CHL_Pot_1' || $args[1] == 'CHL_Pot_2' || $args[1] == 'CHL_Pot_3' || $args[1] == 'CHL_Pot_4') {
                $oldTeamPot = str_replace('CHL_Pot_', '', $args[1]);
            } elseif ($args[1] == 'EUL_Pot_1' || $args[1] == 'EUL_Pot_2' || $args[1] == 'EUL_Pot_3' || $args[1] == 'EUL_Pot_4') {
                $oldTeamPot = str_replace('EUL_Pot_', '', $args[1]) + 4;
            } elseif ($args[1] == 'COL_Pot_1' || $args[1] == 'COL_Pot_2' || $args[1] == 'COL_Pot_3' || $args[1] == 'COL_Pot_4') {
                $oldTeamPot = str_replace('COL_Pot_', '', $args[1]) + 8;
            } else  {
                $telegram->sendMessage($chatId, "ERROR, l'equip no existeix");
                exit;
            }

            $playerTeams           = $teamsRepo->getTeamsByPlayerId($player[0]['id']);
            $alreadyAddedCountries = array_map(function($team) { return $team['country']; }, $playerTeams);
            $possibleNewTeams      = $teamsRepo->getTeamsByPot($oldTeamPot);
            $possibleNewTeams      = array_filter($possibleNewTeams, function($team) use ($alreadyAddedCountries) {
                return !in_array($team['country'], $alreadyAddedCountries);
            });

            if (count($possibleNewTeams) == 0) {
                $telegram->sendMessage($chatId, "ERROR, no hi ha possibilitats de substitució");
                exit;
            }

            $rows = [];
            $row = [];
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
        $oldTeam = [
            'id' => 0,
            'name' => 'Empty',
            'pot' => $newTeam[0]['pot'],
            'country' => 0
        ];
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

        $substitutionsRepo->addSubstitution($player[0]['id'], $matchDay, $oldTeam['id'], $newTeam[0]['id'], $newTeam[0]['competition']);

        $telegram->sendMessage($chatId, "Substitució guardada: " . $oldTeam['name'] . " -> " . $newTeam[0]['name']);
        exit;
    }

    elseif ($command === '/configuració' || $command === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /inici");
}
?>