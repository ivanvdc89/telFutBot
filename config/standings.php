<?php

/**
 * La classificació d'equips: l'ordre i el càlcul, sense base de dades.
 *
 * La classificació surt de team_results (els punts i els gols de cada equip i
 * jornada) i aquí només es decideix en quin ordre queden els equips i qui és
 * primer de cada competició. La taula team_standings i l'script
 * calculateTeamStandings.php són els que hi posen les dades.
 *
 * ELS CRITERIS D'ORDRE, en aquest ordre
 *
 *   1. Punts (de més a menys).
 *   2. Diferència de gols (de més a menys).
 *   3. Gols a favor (de més a menys).
 *   4. Pot, del més petit al més gran. És l'últim recurs, i és el cap de sèrie
 *      del joc: UEFA, quan dos equips queden idèntics, tira de coeficient, i el
 *      pot és el que s'hi assembla.
 *   5. Nom de l'equip, perquè dos equips que ho empatin absolutament tot quedin
 *      sempre en el mateix ordre i el desempat sigui reproduïble.
 *
 * LES POSICIONS SÓN PER COMPETICIÓ: cada equip es classifica amb els seus.
 */

/**
 * Darrera jornada que compta per a la classificació.
 *
 * La classificació és la de la fase de lliga, i la lliga del joc són les 8
 * primeres jornades (RESULTS_LEAGUE_MATCH_DAYS a config/results.php). La 9 ja no
 * hi compta: és la jornada del premi de final de fase, amb els equips que passen
 * i els eliminats (calculateEndLeaguePhasePoints.php).
 *
 * Si el format de la competició canvia, aquest número és el primer a ajustar.
 */
const STANDINGS_LAST_MATCH_DAY = 8;

/**
 * Primer equip de la llista: cert si $a va abans que $b.
 *
 * @param array $rowA Suma d'un equip: points, goals_for, goals_against...
 * @param array $teamA El seu equip: pot i nom.
 * @param array $rowB Suma de l'altre equip.
 * @param array $teamB L'altre equip.
 */
function standingsCompare(array $rowA, array $teamA, array $rowB, array $teamB): int
{
    // Punts, diferència de gols i gols a favor: com més, millor.
    $numbersA = [
        (int) $rowA['points'],
        (int) $rowA['goals_for'] - (int) $rowA['goals_against'],
        (int) $rowA['goals_for'],
    ];
    $numbersB = [
        (int) $rowB['points'],
        (int) $rowB['goals_for'] - (int) $rowB['goals_against'],
        (int) $rowB['goals_for'],
    ];

    foreach ($numbersA as $index => $value) {
        if ($value !== $numbersB[$index]) {
            return $numbersB[$index] <=> $value;
        }
    }

    // El cap de sèrie: el pot més baix va davant.
    $potA = (int) ($teamA['pot'] ?? 0);
    $potB = (int) ($teamB['pot'] ?? 0);
    if ($potA !== $potB) {
        return $potA <=> $potB;
    }

    // I el nom, perquè dos equips idèntics quedin sempre en el mateix ordre.
    $nameComparison = (string) ($teamA['name'] ?? '') <=> (string) ($teamB['name'] ?? '');
    if ($nameComparison !== 0) {
        return $nameComparison;
    }

    return (int) $rowA['team_id'] <=> (int) $rowB['team_id'];
}

/**
 * Les files de team_standings: una per equip, amb la seva posició.
 *
 * @param array<int, array{competition:string, points:int, goals_for:int, goals_against:int, match_days:int}> $totals Sumes per equip (team_id => fila), tal com surten de team_results.
 * @param array<int, array{pot:int, name:string}>                                                              $teams  Els equips (team_id => pot i nom).
 *
 * @return array<int, array{team_id:int, competition:string, ranking:int, points:int, goals_for:int, goals_against:int, goal_difference:int, match_days:int}>
 */
function standingsRows(array $totals, array $teams): array
{
    $byCompetition = [];
    foreach ($totals as $teamId => $row) {
        $byCompetition[(string) $row['competition']][(int) $teamId] = $row;
    }

    // Les competicions, en l'ordre del joc i no en el que doni la base de dades.
    $competitionOrder = array_flip(['CHL', 'EUL', 'COL']);
    uksort(
        $byCompetition,
        static fn(string $a, string $b): int => ($competitionOrder[$a] ?? PHP_INT_MAX) <=> ($competitionOrder[$b] ?? PHP_INT_MAX)
    );

    $rows = [];
    foreach ($byCompetition as $competition => $competitionTotals) {
        $competitionRows = [];
        foreach ($competitionTotals as $teamId => $totalsRow) {
            $competitionRows[] = [
                'team_id'         => (int) $teamId,
                'competition'     => (string) $competition,
                'points'          => (int) $totalsRow['points'],
                'goals_for'       => (int) $totalsRow['goals_for'],
                'goals_against'   => (int) $totalsRow['goals_against'],
                'goal_difference' => (int) $totalsRow['goals_for'] - (int) $totalsRow['goals_against'],
                'match_days'      => (int) $totalsRow['match_days'],
            ];
        }

        usort(
            $competitionRows,
            static fn(array $a, array $b): int => standingsCompare($a, $teams[$a['team_id']] ?? [], $b, $teams[$b['team_id']] ?? [])
        );

        $ranking = 1;
        foreach ($competitionRows as $row) {
            $row['ranking'] = $ranking++;
            $rows[]         = $row;
        }
    }

    return $rows;
}
