<?php

/**
 * Les classificacions que es poden consultar pel xat, sense base de dades ni
 * Telegram.
 *
 *   - La general: tots els jugadors pels punts de players.points.
 *   - La de cada competició: els mateixos jugadors pels punts que han fet els
 *     seus quatre equips en CHL, EUL o COL.
 *
 * El format és el de publishResults.php: les files alineades amb espais dins
 * d'un bloc de codi, i les posicions compartides (1, 1, 3) quan hi ha empat.
 */

require_once(__DIR__ . '/publishResults.php');

/**
 * Els noms dels jugadors, amb #id per als qui encara no en tenen.
 *
 * @param array<int, array> $players Files de getAllPlayers().
 *
 * @return array<int, string>
 */
function classificationPlayerNames(array $players): array
{
    $names = [];

    foreach ($players as $player) {
        $playerId = (int) $player['id'];
        $name     = (string) ($player['name'] ?? '');

        $names[$playerId] = $name !== '' ? $name : ('#' . $playerId);
    }

    return $names;
}

/**
 * Les files de la classificació general.
 *
 * @param array<int, array> $players Files de getAllPlayers().
 *
 * @return array<int, array{player_id: int, total: int}>
 */
function classificationGeneralRows(array $players): array
{
    $rows = [];

    foreach ($players as $player) {
        $rows[] = [
            'player_id' => (int) $player['id'],
            'total'     => (int) ($player['points'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * Les files de la classificació d'una competició.
 *
 * @param array<int, array> $players      Files de getAllPlayers().
 * @param array<int, array> $latestTotals [player_id => última fila de match_day_player_points].
 * @param string            $competition  CHL, EUL o COL.
 *
 * @return array<int, array{player_id: int, total: int}>
 */
function classificationCompetitionRows(array $players, array $latestTotals, string $competition): array
{
    $keys = RESULTS_COMPETITION_KEYS[$competition] ?? null;

    // Sense cap jornada calculada no hi ha res a ordenar: no és una taula de
    // zeros, és que encara no ha començat.
    if ($keys === null || $latestTotals === []) {
        return [];
    }

    $pointsKey = $keys['total'];
    $rows      = [];

    foreach ($players as $player) {
        $playerId = (int) $player['id'];

        $rows[] = [
            'player_id' => $playerId,
            'total'     => (int) ($latestTotals[$playerId][$pointsKey] ?? 0),
        ];
    }

    return $rows;
}

/**
 * El text d'una classificació.
 *
 * @param array<int, array>  $rows        Files amb player_id i total.
 * @param array<int, string> $playerNames
 * @param int|null           $limit       Només els primers N, o null per a tots.
 *                                        Les posicions es calculen sobre tota la
 *                                        classificació abans de retallar: el
 *                                        cinqué ha de continuar sent el cinqué.
 */
function classificationText(
    string $title,
    array $rows,
    array $playerNames,
    ?int $limit = null
): string {
    $hasPoints = false;

    foreach ($rows as $row) {
        // Pot ser negatiu (per exemple, amb #guanyarOMorir): el que importa és
        // si hi ha algun punt, no si el màxim és zero.
        if ((int) $row['total'] !== 0) {
            $hasPoints = true;
        }
    }

    if (count($rows) === 0 || !$hasPoints) {
        return $title . "\n\nEncara no hi ha cap punt.";
    }

    $ranking = resultsRanking($rows, 'total');

    if ($limit !== null) {
        $ranking = array_slice($ranking, 0, $limit);
    }

    $lines = resultsRankingLines(
        $ranking,
        $playerNames,
        'total',
        false
    );

    if ($limit !== null && count($rows) > $limit) {
        $lines[] = '';
        $lines[] = '… i ' . (count($rows) - $limit) . ' jugadors més.';
    }

    return $title . "\n\n" . implode("\n", $lines);
}
