<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/secrets.php");
require_once("config/matchDay.php");
require_once("config/actions.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");
require_once("models/action.php");
require_once("actions/SubstitutionAction.php");
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

$pots           = [1,2,3,4,5,6,7,8,9,10,11,12];
$potNumber      = [0,1,2,3,4,1,2,3,4,1,2,3,4];
$potCompetition = ['X', 'CHL', 'CHL', 'CHL', 'CHL', 'EUL', 'EUL', 'EUL', 'EUL', 'COL', 'COL', 'COL', 'COL'];

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
        $substitutionAction->run($command);
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
                $telegram->sendPhoto($chatId, new CURLFile("files/rules.png"), "Regles del joc");
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

                $telegram->sendMessage($chatId, "Norma #millorFinal:
-Només té efectes per a les finals.
-Hi ha una votació per a donar més punts a la final de cada competició.
-La final més votada donarà 6 punts més al guanyador del partit (3 a cada finalista si hi ha empat al minut 90)
-La segona final més votada donarà 2 punts més al guanyador del partit (1 a cada finalista si hi ha empat al minut 90)
-La final menys votada restarà 2 punts al perdedor del partit (-1 a cada finalista si hi ha empat al minut 90)
-Depenent de la posició del jugador en la classificació, el vot té més importància. 1 per al primer i després cada 10 punts de diferència el jugador sume un vot més. Com a màxim 4");

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
                    $message = 'Equipos del pot ' . $args[2] . ":\n";
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

    elseif ($command === '/configuració' || $command === '/settings') {
        $telegram->sendMessage($chatId, "Configuració");
        exit;
    }

    $telegram->sendMessage($chatId, "Comença utilitzant els botons -> /inici");
}
?>