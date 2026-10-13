<?php

/**
 * Recalcula la classificació d'equips (team_standings) a partir de team_results.
 *
 *     php calculateTeamStandings.php
 *
 * NOMÉS COMPTEN LES JORNADES DE LA FASE DE LLIGA
 *
 * La classificació és la de la lligueta: les jornades 1 a
 * STANDINGS_LAST_MATCH_DAY (config/standings.php). La jornada 9 és la del premi
 * de final de fase (calculateEndLeaguePhasePoints.php, amb els que passen i els
 * eliminats) i les eliminatòries tampoc no hi entren.
 *
 * QUÈ HI FA
 *
 * Suma, per equip, els punts i els gols de team_results, els ordena amb els
 * criteris de config/standings.php i reescriu team_standings sencera dins d'una
 * transacció. No toca team_results ni cap altra taula, i per tant es pot tornar
 * a executar sempre que calgui: quan s'hi canviï res, el resultat és el mateix.
 *
 * Va després de getResults.php (és quan team_results ja té la jornada). El fan
 * servir els desempats per vots de #dobleORes i #kosAmbEscuts.
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/standings.php");
require_once("models/team.php");
require_once("models/teamResult.php");
require_once("models/teamStanding.php");

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$teamsRepo     = new Team();
$resultsRepo   = new TeamResult();
$standingsRepo = new TeamStanding();

$totals = $resultsRepo->getTotalsUpToMatchDay(STANDINGS_LAST_MATCH_DAY);

if ($totals === []) {
    echo "No hi ha cap resultat a team_results fins a la jornada " . STANDINGS_LAST_MATCH_DAY . ": no es calcula res.\n";
    exit;
}

// El pot i el nom de cada equip: són els dos últims criteris de desempat.
$teams = [];
foreach ($teamsRepo->getAllTeams() as $team) {
    $teams[(int) $team['id']] = [
        'pot'  => (int) $team['pot'],
        'name' => (string) $team['name'],
    ];
}

$rows = standingsRows($totals, $teams);

$standingsRepo->beginTransaction();

try {
    if ($standingsRepo->replaceStandings($rows) === false) {
        throw new RuntimeException("no s'ha pogut desar la classificació");
    }

    $standingsRepo->commit();
} catch (Throwable $e) {
    $standingsRepo->rollBack();

    fwrite(STDERR, "ERROR: no s'ha escrit res, la transacció s'ha desfet.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Classificació recalculada amb les jornades 1-" . STANDINGS_LAST_MATCH_DAY
    . " (" . count($rows) . " equips).\n";

foreach (['CHL', 'EUL', 'COL'] as $competition) {
    $competitionRows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => $row['competition'] === $competition
    ));

    if ($competitionRows === []) {
        continue;
    }

    echo "\n" . $competition . "\n";

    foreach ($competitionRows as $row) {
        printf(
            "  %2d. %-20s %2d pts  %2d-%2d  %+d\n",
            $row['ranking'],
            $teams[$row['team_id']]['name'] ?? ('#' . $row['team_id']),
            $row['points'],
            $row['goals_for'],
            $row['goals_against'],
            $row['goal_difference']
        );
    }
}
