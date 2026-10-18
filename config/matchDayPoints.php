<?php

/**
 * El repartiment de punts d'una jornada, sense base de dades ni Telegram.
 *
 * calculateMatchDayPoints.php tenia tota esta faena dins del bucle de jugadors,
 * i per això hi havia dos trossos que no es podien provar i que s'havien quedat
 * escrits a mà: els equips que queden a zero o a doble per les votacions, i el
 * llindar del #socElMillor. Ací viu el que és càlcul pur, perquè ho puguen
 * provar tests/matchDayPointsTest.php sense tocar la base de dades.
 *
 * LES REGLES (les de /normes accions)
 *   - #dobleORes: l'equip més votat de cada competició no suma cap punt i el
 *     segon suma doble. Els empats els decidix la classificació d'equips
 *     (team_standings). Els recomptes són els mateixos que els del publicador
 *     (config/publishActions.php), perquè l'anunci i els punts no es puguen
 *     separar mai.
 *   - #kosAmbEscuts: els KOS_BOICOT_TEAMS equips més votats no sumen punts,
 *     tret que el jugador els hi haja posat un escut. El tall dels quatre també
 *     desempata per classificació d'equips.
 *   - #segurQuePasse: si l'equip apuntat es classifica, se suma l'aposta i el
 *     marge de gols de l'anada ($classifiedTeams).
 *   - #socElMillor: entre els qui l'activen, el màxim de cada competició rep
 *     l'extra i la resta la penalització (matchDayBestPoints()).
 *   - #millorFinal: a la jornada de les finals, la final més votada dona +6 al
 *     seu guanyador (o +3 a cada finalista si va haver empat), la segona +2 (o
 *     +1) i la menys votada -2 al perdedor (o -1 a cada finalista). Els vots
 *     són els que va vore cada jugador.
 *
 * L'ORDRE DE LES ACCIONS SOBRE UN MATEIX EQUIP ÉS EL DE SEMPRE —primer
 * #dobleORes, després #segurQuePasse i al final #kosAmbEscuts—: si més d'una hi
 * encaixa, mana la darrera. No s'ha tocat res d'això.
 */

require_once(__DIR__ . '/publishActions.php');

/**
 * Tot el que les votacions d'una jornada fan als punts dels equips.
 *
 * @param array<int, int>    $doubleOrNothingVoted ids d'equip votats a #dobleORes, un element per vot
 * @param array<int, int>    $kosVoted             ids d'equip votats a #kosAmbEscuts, un per vot
 * @param array<int, string> $teamCompetitions     [team_id => 'CHL'|'EUL'|'COL']
 * @param array<int, int>    $teamRankings         [team_id => posició] de team_standings, per desempatar
 *
 * @return array{nothingTeams: array<int, int>, doubleTeams: array<int, int>, koTeams: array<int, int>}
 */
function matchDayVoteResults(array $doubleOrNothingVoted, array $kosVoted, array $teamCompetitions, array $teamRankings = []): array
{
    $nothingTeams = [];
    $doubleTeams  = [];

    // L'equip més votat i el segon de cada competició. Els vots buits no hi
    // compten: si ningú no ha votat #dobleORes, els dos arrays queden buits.
    // Els empats els resol la classificació d'equips, igual que a l'anunci.
    foreach (actionsDoubleOrNothingResult(actionsVoteTotals($doubleOrNothingVoted), $teamCompetitions, $teamRankings) as $teams) {
        foreach ($teams['top'] as $teamId) {
            $nothingTeams[] = (int) $teamId;
        }

        foreach ($teams['second'] as $teamId) {
            $doubleTeams[] = (int) $teamId;
        }
    }

    return [
        'nothingTeams' => $nothingTeams,
        'doubleTeams'  => $doubleTeams,
        'koTeams'      => array_map('intval', actionsMostVotedTeams(actionsVoteTotals($kosVoted), KOS_BOICOT_TEAMS, $teamRankings)),
    ];
}

/**
 * El llindar del #socElMillor: el màxim de cada competició entre els jugadors
 * que l'han activat eixa jornada.
 *
 * La norma diu que, entre tots els qui l'activen a una competició, els qui sumen
 * més punts reben l'extra i la resta la penalització. Per tant el llindar no és
 * cap constant: és el màxim d'eixa competició, i es compara amb els punts de
 * cada jugador abans d'aplicar #malDia, #guanyarOMorir ni el mateix #socElMillor.
 *
 * @param array<int, array<int, string>> $activatedCompetitions [player_id => ['CHL', 'EUL', ...]]
 * @param array<int, array<string, int>> $playerPoints          [player_id => ['CHL' => punts, ...]]
 *
 * @return array<string, int|null> [competició => màxim, o null si no l'activa ningú]
 */
function matchDayBestPoints(array $activatedCompetitions, array $playerPoints): array
{
    $best = array_fill_keys(ACTION_COMPETITIONS, null);

    foreach ($activatedCompetitions as $playerId => $competitions) {
        foreach ($competitions as $competition) {
            if (!array_key_exists($competition, $best)) {
                continue;
            }

            $points = $playerPoints[$playerId][$competition] ?? null;

            if ($points === null) {
                continue;
            }

            if ($best[$competition] === null || $points > $best[$competition]) {
                $best[$competition] = (int) $points;
            }
        }
    }

    return $best;
}

/**
 * Qui va guanyar cada final, a partir dels punts dels partits dels seus equips.
 *
 * Un equip amb més de 2 punts va guanyar (a les eliminatòries la victòria val
 * 3), un amb 1 va empatar i un amb 0 va perdre. Si la base de dades només en té
 * un (el rival d'un equip exclòs no s'hi desa), els seus punts ja diuen què va
 * passar.
 *
 * @param array<int, int> $teamPoints [team_id => punts del partit]
 *
 * @return array{0: array<int, int>, 1: array<int, int>, 2: bool} guanyadors, perdedors i si va haver empat
 */
function matchDayFinalSides(array $teamPoints): array
{
    if (count($teamPoints) === 1) {
        $teamId = (int) array_key_first($teamPoints);
        $points = $teamPoints[$teamId];

        if ($points === 1) {
            return [[$teamId], [$teamId], true];
        }

        return $points > 1 ? [[$teamId], [], false] : [[], [$teamId], false];
    }

    $most  = max($teamPoints);
    $least = min($teamPoints);

    if ($most === $least) {
        $finalists = array_map('intval', array_keys($teamPoints));

        return [$finalists, $finalists, true];
    }

    return [
        array_map('intval', array_keys(array_filter($teamPoints, static fn(int $points): bool => $points === $most))),
        array_map('intval', array_keys(array_filter($teamPoints, static fn(int $points): bool => $points === $least))),
        false,
    ];
}

/**
 * El que el #millorFinal reparteix, a partir del rànquing de vots i dels
 * resultats de les finals.
 *
 * El rànquing el fa actionsBestFinalRanking() (config/publishActions.php), el
 * mateix que surt a l'anunci del grup, perquè el que es publica i el que es
 * paga no es puguen separar.
 *
 * @param array<string, int> $ranking [competició => vots], de més a menys
 * @param array<int, array>  $results Files de team_results de la jornada de la final
 *
 * @return array<int, array{points: int, result: string}> [team_id => efecte]
 */
function matchDayBestFinalEffects(array $ranking, array $results): array
{
    // El que toca a cada posició del rànquing: el guanyador, el perdedor (només
    // la pitjor final el castiga) i els dos finalistes quan hi ha empat.
    $byRank = [
        ['winner' => 6, 'loser' => 0, 'draw' => 3],
        ['winner' => 2, 'loser' => 0, 'draw' => 1],
        ['winner' => 0, 'loser' => -2, 'draw' => -1],
    ];

    $effects = [];
    $rank    = 0;

    foreach ($ranking as $competition => $votes) {
        if (!isset($byRank[$rank])) {
            break;
        }

        $teamPoints = [];
        foreach ($results as $row) {
            if ((string) $row['competition'] === (string) $competition) {
                $teamPoints[(int) $row['team_id']] = (int) $row['points'];
            }
        }

        // Una final que encara no s'ha jugat no reparteix res, però sí que ocupa
        // el seu lloc al rànquing (els vots ja hi eren).
        if (count($teamPoints) > 0) {
            [$winners, $losers, $isDraw] = matchDayFinalSides($teamPoints);

            if ($isDraw) {
                foreach ($winners as $teamId) {
                    $effects[$teamId] = ['points' => $byRank[$rank]['draw'], 'result' => 'finalista'];
                }
            } else {
                foreach ($winners as $teamId) {
                    $effects[$teamId] = ['points' => $byRank[$rank]['winner'], 'result' => 'guanyador'];
                }

                foreach ($losers as $teamId) {
                    $effects[$teamId] = ['points' => $byRank[$rank]['loser'], 'result' => 'perdedor'];
                }
            }
        }

        $rank++;
    }

    // Un efecte de 0 punts no és cap efecte: fora (el guanyador de la pitjor
    // final no cobra res).
    return array_filter($effects, static fn(array $effect): bool => $effect['points'] !== 0);
}

/**
 * Els punts que fan els equips d'un jugador en una jornada.
 *
 * @param array<int, array> $playerTeams   Files de getTeamsByPlayerId(): id i pot.
 * @param array<int, array> $resultsByTeam [team_id => fila de team_results]: points i competition.
 * @param array             $actions       Les regles d'esta jornada i d'este jugador:
 *   - 'nothingTeams'    ids que queden a 0 per #dobleORes
 *   - 'doubleTeams'     ids que sumen doble per #dobleORes
 *   - 'koTeams'         ids boicotejats per #kosAmbEscuts
 *   - 'classifiedTeams' [team_id => marge de gols de l'anada] de #segurQuePasse
 *   - 'shields'         ids dels equips protegits per este jugador
 *   - 'sureTeam'        l'equip apuntat a #segurQuePasse, o -1
 *   - 'surePoints'      els punts apostats
 *
 * @return array{rows: array<int, array>, points: array<string, int>, wins: array<string, int>}
 *   rows: una fila per equip amb resultat (pot, team_id, points, action, total);
 *   points: la suma de la jornada per competició, amb les accions d'equip ja
 *   aplicades i sense les de jugador (#malDia, #socElMillor, #guanyarOMorir);
 *   wins: els partits guanyats per competició (pels punts del partit, no pels
 *   del joc: a la Conference la victòria val 4, i el comptador mira si en val
 *   més de 2).
 */
function matchDayTeamPoints(array $playerTeams, array $resultsByTeam, array $actions): array
{
    $nothingTeams    = $actions['nothingTeams'] ?? [];
    $doubleTeams     = $actions['doubleTeams'] ?? [];
    $koTeams         = $actions['koTeams'] ?? [];
    $classifiedTeams = $actions['classifiedTeams'] ?? [];
    $shieldTeams     = $actions['shields'] ?? [];
    $sureTeam        = (int) ($actions['sureTeam'] ?? -1);
    $sureTeamPoints  = (int) ($actions['surePoints'] ?? 0);

    $rows   = [];
    $points = array_fill_keys(ACTION_COMPETITIONS, 0);
    $wins   = array_fill_keys(ACTION_COMPETITIONS, 0);

    foreach ($playerTeams as $team) {
        $teamId = (int) $team['id'];
        $result = $resultsByTeam[$teamId] ?? null;

        // Un equip que no ha jugat esta jornada (o que no té resultat carregat)
        // no compta per a res.
        if ($result === null) {
            continue;
        }

        $competition = (string) $result['competition'];
        $basePoints  = (int) $result['points'];
        $totalPoints = $basePoints;
        $action      = null;

        if (in_array($teamId, $nothingTeams, true)) {
            $action      = ['type' => 'dobleORes', 'result' => 'Res'];
            $totalPoints = 0;
        }

        if (in_array($teamId, $doubleTeams, true)) {
            $action      = ['type' => 'dobleORes', 'result' => 'Doble'];
            $totalPoints = 2 * $basePoints;
        }

        if ($teamId === $sureTeam && array_key_exists($teamId, $classifiedTeams)) {
            $action      = ['type' => 'segurQuePasse', 'result' => 'YES'];
            $totalPoints = $basePoints + (int) $classifiedTeams[$teamId] + $sureTeamPoints;
        }

        if (in_array($teamId, $koTeams, true)) {
            if (in_array($teamId, $shieldTeams, true)) {
                $action      = ['type' => 'kosAmbEscuts', 'result' => 'Escut'];
                $totalPoints = $basePoints;
            } else {
                $action      = ['type' => 'kosAmbEscuts', 'result' => 'KO'];
                $totalPoints = 0;
            }
        }

        // team_results ha de portar una de les tres competicions del joc. Si en
        // portara una altra, la fila es desa igualment però no s'hi suma res.
        if (array_key_exists($competition, $points)) {
            $points[$competition] += $totalPoints;

            if ($basePoints > 2) {
                $wins[$competition]++;
            }
        }

        $rows[] = [
            'pot'     => (int) $team['pot'],
            'team_id' => $teamId,
            'points'  => $basePoints,
            'action'  => $action,
            'total'   => $totalPoints,
        ];
    }

    return ['rows' => $rows, 'points' => $points, 'wins' => $wins];
}
