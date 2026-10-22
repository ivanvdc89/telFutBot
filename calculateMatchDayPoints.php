<?php

/**
 * Reparteix els punts d'una jornada: equips, accions i classificació general.
 *
 * COM S'USA
 *
 *   php calculateMatchDayPoints.php                 # la jornada en curs
 *   php calculateMatchDayPoints.php 2               # una jornada concreta
 *   php calculateMatchDayPoints.php 2 --recalculate  # refer una jornada ja calculada
 *
 * ELS PUNTS NO ES CALCULEN DUES VEGADES
 *
 * Els totals són acumulatius: si es tornara a passar la mateixa jornada, la
 * sumaria un altre cop (getLastMatchDayByPlayer() torna la fila de la jornada
 * que s'està calculant) i a més deixaria files duplicades. Per això, si la
 * jornada ja té punts, l'script s'atura i no toca res; per refer-la de debò cal
 * demanar-ho amb --recalculate, que esborra les files d'eixa jornada abans de
 * tornar-les a escriure.
 *
 * LES ACCIONS DE VOTACIÓ
 *
 * #dobleORes i #kosAmbEscuts es reparteixen a partir de les votacions desades a
 * actions (els mateixos recomptes que publica publishActions.php, a
 * config/publishActions.php), #millorFinal es paga a la jornada de les finals, i
 * #segurQuePasse necessita que algú haja dit a mà quins equips passen de ronda a
 * la jornada eliminatòria ($classifiedTeams). El llindar de #socElMillor és el
 * màxim de cada competició entre els qui l'activen, i per saber-lo cal fer una
 * primera passada sense escriure res.
 *
 * LES SUBSTITUCIONS
 *
 * El cost de cada substitució es descompta d'ací, però només de les que ja estan
 * aplicades (pending = 0). Si n'hi haguera cap de pendent, l'script s'atura: si
 * no, cobraria el cost sense que l'equip haguera canviat.
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/matchDay.php");
require_once("config/results.php");
require_once("config/matchDayPoints.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/teamStanding.php");
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
$standingsRepo           = new TeamStanding();
$matchDayTeamPointRepo   = new MatchDayTeamPoint();
$matchDayPlayerPointRepo = new MatchDayPlayerPoint();
$teamResultRepo          = new TeamResult();
$actionsRepo             = new Action();
$substitutionsRepo       = new Substitution();
$matchDay                = currentMatchDay();
$recalculate             = in_array('--recalculate', array_slice($GLOBALS['argv'], 1), true);

// ------------------------------------------- substitucions sense aplicar

$pendingSubstitutions = $substitutionsRepo->getPendingSubstitutionsByMatchDay($matchDay);
if (count($pendingSubstitutions) > 0) {
    fwrite(STDERR, "Hi ha " . count($pendingSubstitutions) . " substitucions pendents d'aplicar a la jornada $matchDay.\n\n");
    fwrite(STDERR, "Cal aplicar-les abans de calcular els punts: si no, es cobraria el cost sense\n");
    fwrite(STDERR, "que l'equip haguera canviat.\n\n");
    fwrite(STDERR, "  php applySubstitutions.php\n\n");
    fwrite(STDERR, "(Si alguna no s'ha de fer, el jugador la pot traure amb /substitució remove.)\n");
    exit(1);
}

// ---------------------------------------------------- guarda de reexecució

if ($matchDayPlayerPointRepo->matchDayHasPoints($matchDay)
    || $matchDayTeamPointRepo->matchDayHasPoints($matchDay)
) {
    if (!$recalculate) {
        fwrite(STDERR, "La jornada $matchDay ja té punts calculats.\n\n");
        fwrite(STDERR, "Tornar-los a calcular els sumaria un altre cop, aixina que no s'ha tocat res.\n");
        fwrite(STDERR, "Si de debò s'ha de refer la jornada:\n");
        fwrite(STDERR, "  php calculateMatchDayPoints.php $matchDay --recalculate\n");
        exit(1);
    }

    $matchDayPlayerPointRepo->deleteByMatchDay($matchDay);
    $matchDayTeamPointRepo->deleteByMatchDay($matchDay);
}

// ----------------------------------------------- les votacions de la jornada

// Foto de la classificació general abans de tocar-hi res: este script va
// escrivint players.points jugador a jugador, i totes les validacions de posició
// han de vore la mateixa general, que és la d'abans d'esta jornada.
$allowedVotes = $playersRepo->getAllowedVotesByPlayer();

$teamCompetitions = [];
foreach ($teamsRepo->getAllTeams() as $team) {
    $teamCompetitions[(int) $team['id']] = (string) $team['competition'];
}

// Posició de cada equip a la seua competició: desempata els vots empatats de
// #dobleORes i de #kosAmbEscuts. És la mateixa foto que fa servir
// publishActions.php, perquè l'anunci i els punts no es puguen separar.
$teamRankings = $standingsRepo->getRankingsByTeam();

$doubleOrNothingVoted = [];
foreach ($actionsRepo->getActions($matchDay, 'doubleOrNothing') as $action) {
    $actionData = json_decode($action['data'], true);

    foreach ($actionData['teams'] ?? [] as $teamId) {
        $doubleOrNothingVoted[] = (int) $teamId;
    }
}

$kosVoted = [];
foreach ($actionsRepo->getActions($matchDay, 'kosAndShields') as $action) {
    $actionData = json_decode($action['data'], true);

    foreach ($actionData['kos'] ?? [] as $teamId) {
        $kosVoted[] = (int) $teamId;
    }
}

[
    'nothingTeams' => $nothingTeams,
    'doubleTeams'  => $doubleTeams,
    'koTeams'      => $koTeams,
] = matchDayVoteResults($doubleOrNothingVoted, $kosVoted, $teamCompetitions, $teamRankings);

// Els equips que passen de ronda i el marge de gols de l'anada no es poden
// deduir dels resultats de hui: són una dada de la jornada eliminatòria, i es
// continuen posant a mà ací quan en toca una. Un exemple de les files antigues:
//   $classifiedTeams = [2 => -1, 73 => 1];
$classifiedTeams = [];

// El #millorFinal només es paga a la jornada de les finals. El rànquing de vots
// és el mateix que surt a l'anunci de publishActions.php, i qui guanya o perd
// cada final ix dels resultats.
$bestFinalEffects = [];
if (resultsIsFinalMatchDay($matchDay)) {
    $bestFinalVotes = [];
    foreach ($actionsRepo->getActions($matchDay, 'bestFinal') as $action) {
        $actionData  = json_decode($action['data'], true);
        $competition = $actionData['competition'] ?? ($actionData[0] ?? null);

        if (!is_string($competition) || !in_array($competition, ACTION_COMPETITIONS, true)) {
            continue;
        }

        // El pes del vot és el que va vore el jugador; les files antigues no el
        // portaven i es tornen a calcular amb la general d'abans d'esta jornada.
        $bestFinalVotes[$competition] = ($bestFinalVotes[$competition] ?? 0)
            + (int) ($actionData['points']
                ?? $allowedVotes[(int) $action['player_id']]
                ?? Player::MIN_VOTES);
    }

    if (count($bestFinalVotes) > 0) {
        $bestFinalEffects = matchDayBestFinalEffects(
            actionsBestFinalRanking($bestFinalVotes),
            $teamResultRepo->getResultsByMatchDay($matchDay)
        );
    }
}

// Les substitucions que es descompten són només les ja aplicades; si n'hi haguera
// cap de pendent, l'script ja s'hauria aturat més amunt.
$executedSubstitutions = [];
foreach ($substitutionsRepo->getExecutedSubstitutionsByMatchDay($matchDay) as $substitution) {
    $executedSubstitutions[(int) $substitution['player_id']][] = $substitution;
}

// --------------------------- primera passada: els punts, sense escriure res

$players  = $playersRepo->getAllPlayers();
$computed = [];

foreach ($players as $player) {
    $playerId    = (int) $player['id'];
    $playerTeams = $teamsRepo->getTeamsByPlayerId($playerId);

    $resultsByTeam = [];
    foreach ($playerTeams as $team) {
        $teamResult = $teamResultRepo->getResultByTeamIdAndMatchDay($team['id'], $matchDay);

        if (count($teamResult) > 0) {
            $resultsByTeam[(int) $team['id']] = $teamResult[0];
        }
    }

    $shieldTeams = [];
    $shieldRows  = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'kosAndShields');
    if (count($shieldRows) > 0) {
        $kosAndShieldsData = json_decode($shieldRows[0]['data'], true);

        foreach ($kosAndShieldsData['shields'] ?? [] as $teamId) {
            $shieldTeams[] = (int) $teamId;
        }
    }

    $sureToBeQualifiedTeam   = -1;
    $sureToBeQualifiedPoints = 0;
    $sureRows                = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'sureToBeQualified');
    if (count($sureRows) > 0) {
        $actionData = json_decode($sureRows[0]['data'], true);

        // basic.php desa ['team' => id, 'points' => N]; les files antigues només
        // portaven [id]. Els punts són la regla de posició (1 el líder i 1 més
        // per cada 10 punts de diferència, màxim 4), no un valor del JSON.
        $sureToBeQualifiedTeam   = (int) ($actionData['team'] ?? $actionData[0] ?? -1);
        $sureToBeQualifiedPoints = (int) ($actionData['points']
            ?? $allowedVotes[$playerId]
            ?? Player::MIN_VOTES);
    }

    // #millorFinal: el que li toca a este jugador per les finals que té. Un
    // jugador pot tindre els dos finalistes, i aleshores suma els dos efectes.
    $bestFinal = array_fill_keys(ACTION_COMPETITIONS, 0);
    foreach ($playerTeams as $team) {
        $teamId = (int) $team['id'];

        if (!isset($bestFinalEffects[$teamId])) {
            continue;
        }

        $competition = $teamCompetitions[$teamId] ?? null;

        if ($competition !== null && array_key_exists($competition, $bestFinal)) {
            $bestFinal[$competition] += $bestFinalEffects[$teamId]['points'];
        }
    }

    $computed[$playerId] = [
        'lastMatchDay' => $matchDayPlayerPointRepo->getLastMatchDayByPlayer($playerId),
        'teams'        => matchDayTeamPoints($playerTeams, $resultsByTeam, [
            'nothingTeams'    => $nothingTeams,
            'doubleTeams'     => $doubleTeams,
            'koTeams'         => $koTeams,
            'classifiedTeams' => $classifiedTeams,
            'shields'         => $shieldTeams,
            'sureTeam'        => $sureToBeQualifiedTeam,
            'surePoints'      => $sureToBeQualifiedPoints,
        ]),
        'bestFinal'     => $bestFinal,
        'substitutions' => $executedSubstitutions[$playerId] ?? [],
    ];
}

// ------------------------------- el llindar del #socElMillor d'esta jornada

$iAmTheBest = [];
foreach ($actionsRepo->getActions($matchDay, 'iAmTheBest') as $action) {
    $playerId = (int) $action['player_id'];

    if (!isset($computed[$playerId])) {
        continue;
    }

    if (!isset($iAmTheBest[$playerId])) {
        $iAmTheBest[$playerId] = [];
    }

    foreach (json_decode($action['data'], true) ?? [] as $competition) {
        // Les jornades simulades (la Conference a la 1 i a la 8) no es juguen i
        // l'acció no s'hi aplica.
        if (is_string($competition) && !resultsIsSimulatedLeagueMatchDay($competition, $matchDay)) {
            $iAmTheBest[$playerId][] = $competition;
        }
    }
}

$playerPoints = [];
foreach ($computed as $playerId => $data) {
    $playerPoints[$playerId] = $data['teams']['points'];
}

$best       = matchDayBestPoints($iAmTheBest, $playerPoints);
$bestCHL    = $best['CHL'] ?? 0;
$bestEUL    = $best['EUL'] ?? 0;
$bestCOL    = $best['COL'] ?? 0;

// ------------------- segona passada: les accions de jugador, i escriure-ho

foreach ($players as $player) {
    $playerId     = (int) $player['id'];
    $lastMatchDay = $computed[$playerId]['lastMatchDay'];
    $teamPoints   = $computed[$playerId]['teams'];

    foreach ($teamPoints['rows'] as $row) {
        $matchDayTeamPointRepo->addMatchDayTeamPoint(
            $playerId,
            $matchDay,
            $row['pot'],
            $row['team_id'],
            $row['points'],
            $row['action'] === null ? '' : json_encode($row['action']),
            $row['total']
        );
    }

    $chlPoints = $teamPoints['points']['CHL'];
    $chlWins   = $teamPoints['wins']['CHL'];
    $eulPoints = $teamPoints['points']['EUL'];
    $eulWins   = $teamPoints['wins']['EUL'];
    $colPoints = $teamPoints['points']['COL'];
    $colWins   = $teamPoints['wins']['COL'];

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'badDay');
    $chlPointsAfterAction = $chlPoints;
    $eulPointsAfterAction = $eulPoints;
    $colPointsAfterAction = $colPoints;
    $chlAction = [];
    $eulAction = [];
    $colAction = [];
    if (isset($actions[0])) {
        $actionData = json_decode($actions[0]['data'], true);
        foreach ($actionData ?? [] as $competition) {
            // Jornada simulada (la Conference a la 1 i a la 8): aquí no s'hi juga
            // i l'acció no s'ha d'aplicar. El bot ja no deixa activar-la-hi, això
            // cobreix les files que hi poguessen quedar d'abans.
            if (resultsIsSimulatedLeagueMatchDay($competition, $matchDay)) {
                continue;
            }

            // Els punts d'equip ja porten #dobleORes (i la resta d'accions
            // d'equip) aplicats: el llindar de #malDia es mira sobre eixe total.
            if ($competition === "CHL") {
                $badDay = matchDayBadDayPoints($chlPoints, $competition);
                $chlAction[] = ["type" => "malDia", "result" => $badDay['result']];
                $chlPointsAfterAction = $badDay['points'];
            }
            if ($competition === "EUL") {
                $badDay = matchDayBadDayPoints($eulPoints, $competition);
                $eulAction[] = ["type" => "malDia", "result" => $badDay['result']];
                $eulPointsAfterAction = $badDay['points'];
            }
            if ($competition === "COL") {
                $badDay = matchDayBadDayPoints($colPoints, $competition);
                $colAction[] = ["type" => "malDia", "result" => $badDay['result']];
                $colPointsAfterAction = $badDay['points'];
            }
        }
    }

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'iAmTheBest');
    foreach ($actions as $action) {
        $actionData = json_decode($action['data'], true);
        foreach ($actionData ?? [] as $competition) {
            if (resultsIsSimulatedLeagueMatchDay($competition, $matchDay)) {
                continue;
            }

            // La comparació es fa amb els punts que ja porten #dobleORes i la
            // resta d'accions d'equip aplicades.
            if ($competition === "CHL") {
                $bestAction = matchDayBestActionPoints($chlPoints, $bestCHL, $competition);
                $chlAction[] = ["type" => "socElMillor", "result" => $bestAction['result']];
                $chlPointsAfterAction += $bestAction['points'];
            }
            if ($competition === "EUL") {
                $bestAction = matchDayBestActionPoints($eulPoints, $bestEUL, $competition);
                $eulAction[] = ["type" => "socElMillor", "result" => $bestAction['result']];
                $eulPointsAfterAction += $bestAction['points'];
            }
            if ($competition === "COL") {
                $bestAction = matchDayBestActionPoints($colPoints, $bestCOL, $competition);
                $colAction[] = ["type" => "socElMillor", "result" => $bestAction['result']];
                $colPointsAfterAction += $bestAction['points'];
            }
        }
    }

    $actions = $actionsRepo->getActionsByPlayerId($playerId, $matchDay, 'winOrDie');
    foreach ($actions as $action) {
        $actionData = json_decode($action['data'], true);
        foreach ($actionData ?? [] as $competition) {
            if (resultsIsSimulatedLeagueMatchDay($competition, $matchDay)) {
                continue;
            }

            if ($competition === "CHL") {
                $result               = -4 + $chlWins * 2;
                $chlAction[]          = ["type" => "guanyarOMorir", "result" => $result];
                $chlPointsAfterAction += $result;
            }
            if ($competition === "EUL") {
                $result               = -4 + $eulWins * 2;
                $eulAction[]          = ["type" => "guanyarOMorir", "result" => $result];
                $eulPointsAfterAction += $result;
            }
            if ($competition === "COL") {
                $result               = -4 + $colWins * 2;
                $colAction[]          = ["type" => "guanyarOMorir", "result" => $result];
                $colPointsAfterAction += $result;
            }
        }
    }

    // #millorFinal: el que reparteixen les finals d'esta jornada (buit quan no
    // és la jornada de les finals o el jugador no hi té cap finalista).
    foreach (ACTION_COMPETITIONS as $competition) {
        $bestFinalPoints = $computed[$playerId]['bestFinal'][$competition];

        if ($bestFinalPoints === 0) {
            continue;
        }

        $bestFinalAction = ["type" => "millorFinal", "result" => $bestFinalPoints];

        if ($competition === "CHL") {
            $chlAction[]          = $bestFinalAction;
            $chlPointsAfterAction += $bestFinalPoints;
        }
        if ($competition === "EUL") {
            $eulAction[]          = $bestFinalAction;
            $eulPointsAfterAction += $bestFinalPoints;
        }
        if ($competition === "COL") {
            $colAction[]          = $bestFinalAction;
            $colPointsAfterAction += $bestFinalPoints;
        }
    }

    foreach ($computed[$playerId]['substitutions'] as $substitution) {
        $pointsCost = 0 - $substitution['points_cost'];
        if ($substitution['competition'] === "CHL") {
            $chlAction[]          = ["type" => "substitució", "result" => $pointsCost];
            $chlPointsAfterAction += $pointsCost;
        }
        if ($substitution['competition'] === "EUL") {
            $eulAction[]          = ["type" => "substitució", "result" => $pointsCost];
            $eulPointsAfterAction += $pointsCost;
        }
        if ($substitution['competition'] === "COL") {
            $colAction[]          = ["type" => "substitució", "result" => $pointsCost];
            $colPointsAfterAction += $pointsCost;
        }
    }

    $matchDayTotal = $chlPointsAfterAction + $eulPointsAfterAction + $colPointsAfterAction;
    $total         = $lastMatchDay['total'] + $matchDayTotal;

    $matchDayPlayerPointRepo->addMatchDayPlayerPoint(
        $playerId,
        $matchDay,
        $chlPoints,
        json_encode($chlAction),
        $chlPointsAfterAction,
        $chlPointsAfterAction + $lastMatchDay['chl_total'],
        $eulPoints,
        json_encode($eulAction),
        $eulPointsAfterAction,
        $eulPointsAfterAction + $lastMatchDay['eul_total'],
        $colPoints,
        json_encode($colAction),
        $colPointsAfterAction,
        $colPointsAfterAction + $lastMatchDay['col_total'],
        '',
        $matchDayTotal,
        $total
    );

    // players.points és la classificació general: el total acumulat després
    // d'esta jornada. Només s'hi escriu si la jornada que s'està calculant és
    // la darrera del jugador, perquè reprocessar una jornada antiga no ha de
    // deixar la classificació actual amb valors vells.
    if (!isset($lastMatchDay['match_day']) || $matchDay >= (int) $lastMatchDay['match_day']) {
        $playersRepo->updatePlayerPoints($playerId, $total);
    }
}
