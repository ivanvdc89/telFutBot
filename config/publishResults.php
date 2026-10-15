<?php

/**
 * Els missatges de publishResults.php, sense base de dades ni Telegram.
 *
 * Abans, publishResults.php llegia la base de dades, muntava el text i l'enviava
 * tot al mateix fitxer, i el text s'havia quedat difícil de seguir: "1º =>nom",
 * "3 pts || Res", i "JORNADA"/"TOTAL" sense dir de què. Ací viu només el format,
 * perquè es puga provar a tests/publishResultsTest.php.
 *
 * QUÈ S'ENVIA
 *   - Un missatge per jugador, amb les tres competicions: els punts de cada
 *     equip (i què li ha passat, si li ha passat res) i les accions de jugador.
 *   - Un missatge amb la classificació general i els millors de cada competició.
 *   - Un missatge amb la classificació de la jornada.
 *
 * LES POSICIONS
 *
 * Es calculen amb els empats compartits: dos jugadors amb els mateixos punts
 * tenen la mateixa posició i el següent se'n salta una (1, 1, 3).
 */

require_once(__DIR__ . '/pots.php');
require_once(__DIR__ . '/publishActions.php');

/**
 * Claus de match_day_player_points de cada competició, en l'ordre del joc.
 */
const RESULTS_COMPETITION_KEYS = [
    'CHL' => ['sum' => 'chl_sum', 'total' => 'chl_total', 'action' => 'chl_action'],
    'EUL' => ['sum' => 'eul_sum', 'total' => 'eul_total', 'action' => 'eul_action'],
    'COL' => ['sum' => 'col_sum', 'total' => 'col_total', 'action' => 'col_action'],
];

/**
 * Medalles de les tres primeres posicions. La resta duen el número.
 */
const RESULTS_MEDALS = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

/**
 * El distintiu d'una posició: medalla o número.
 */
function resultsMedal(int $position): string
{
    return RESULTS_MEDALS[$position] ?? ($position . '.');
}

/**
 * '1 pt' o 'N pts'.
 */
function resultsPoints(int $points): string
{
    return $points . ($points === 1 || $points === -1 ? ' pt' : ' pts');
}

/**
 * Ordena les files per una clau de punts i hi afig la posició.
 *
 * @param array<int, array> $rows      Files de match_day_player_points.
 * @param string            $pointsKey 'total' o 'match_day_total'.
 *
 * @return array<int, array> Les mateixes files, de més a menys punts, amb 'position'.
 */
function resultsRanking(array $rows, string $pointsKey): array
{
    usort(
        $rows,
        static fn(array $a, array $b): int => (int) $b[$pointsKey] <=> (int) $a[$pointsKey]
    );

    $ranking  = [];
    $position = 0;
    $previous = null;

    foreach ($rows as $index => $row) {
        $points = (int) $row[$pointsKey];

        // Els empatats comparteixen posició; el següent se'n salta una.
        if ($previous === null || $points !== $previous) {
            $position = $index + 1;
            $previous = $points;
        }

        $row['position'] = $position;
        $ranking[]       = $row;
    }

    return $ranking;
}

/**
 * El nom d'un jugador, o #id si no es coneix.
 *
 * @param array<int, string> $playerNames
 */
function resultsPlayerName(array $playerNames, int $playerId): string
{
    return $playerNames[$playerId] ?? ('#' . $playerId);
}

/**
 * El nom d'un equip, o #id si no es coneix.
 *
 * @param array<int, string> $teamNames
 */
function resultsTeamName(array $teamNames, int $teamId): string
{
    return $teamNames[$teamId] ?? ('#' . $teamId);
}

/**
 * L'etiqueta d'una acció desada a match_day_*_points.
 *
 * Estes files guarden el nom de l'acció sense el coixinet ('malDia',
 * 'dobleORes'...), no el tipus de la taula actions ('badDay',
 * 'doubleOrNothing'...), aixina que actionLabel() no hi encaixa. El coixinet es
 * reconstrueix a partir de les etiquetes del publicador, per no tindre'n dues
 * llistes; 'substitució' no hi és i es queda tal qual.
 */
function resultsActionLabel(string $type): string
{
    return in_array('#' . $type, ACTION_PUBLISH_LABELS, true) ? '#' . $type : $type;
}

/**
 * L'acció d'equip, en text: '#dobleORes: Res' o null si no n'hi ha.
 *
 * El JSON de match_day_team_points és {type, result}; '[]' i la cadena buida són
 * les files sense cap acció.
 */
function resultsTeamAction(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '' || trim($raw) === '[]') {
        return null;
    }

    $action = json_decode($raw, true);

    if (!is_array($action) || !isset($action['type'])) {
        return null;
    }

    $label  = resultsActionLabel((string) $action['type']);
    $result = (string) ($action['result'] ?? '');

    return $result === '' ? $label : $label . ': ' . $result;
}

/**
 * Les accions de jugador d'una competició, una línia per acció.
 *
 * @return array<int, string>
 */
function resultsPlayerActions(?string $raw): array
{
    $actions = json_decode((string) $raw, true);

    if (!is_array($actions)) {
        return [];
    }

    $lines = [];
    foreach ($actions as $action) {
        if (!is_array($action) || !isset($action['type'])) {
            continue;
        }

        $label  = resultsActionLabel((string) $action['type']);
        $result = (string) ($action['result'] ?? '');

        $lines[] = $result === '' ? $label : $label . ': ' . $result;
    }

    return $lines;
}

/**
 * La línia d'un equip: els punts, què ha acabat sumant i per què.
 */
function resultsTeamLine(array $teamRow, array $teamNames): string
{
    $teamId = (int) $teamRow['team_id'];
    $points = (int) $teamRow['points'];
    $total  = (int) $teamRow['total'];

    $name = resultsTeamName($teamNames, $teamId);

    // Quan una acció ha canviat els punts de l'equip, s'ensenya el camí:
    // 'Milan: 3 → 6 pts'. Si no, només els punts.
    $line = $total !== $points
        ? $name . ': ' . $points . ' → ' . resultsPoints($total)
        : $name . ': ' . resultsPoints($points);

    $action = resultsTeamAction($teamRow['action'] ?? null);

    return $action === null ? $line : $line . ' — ' . $action;
}

/**
 * El missatge d'un jugador: la seua posició, i les tres competicions.
 *
 * @param array<int, array>  $row       La fila de la general, amb 'position'.
 * @param array<int, array>  $teamRows  Els seus equips (match_day_team_points).
 * @param array<int, string> $teamNames [team_id => nom].
 */
function resultsPlayerText(array $row, array $teamRows, string $name, array $teamNames): string
{
    $lines = [sprintf(
        '%s %s — %s · jornada %s',
        resultsMedal((int) $row['position']),
        $name,
        resultsPoints((int) $row['total']),
        resultsPoints((int) $row['match_day_total'])
    )];

    foreach (RESULTS_COMPETITION_KEYS as $competition => $keys) {
        $lines[] = '';
        $lines[] = sprintf(
            '%s · jornada %s · total %s',
            $competition,
            resultsPoints((int) $row[$keys['sum']]),
            resultsPoints((int) $row[$keys['total']])
        );

        foreach ($teamRows as $teamRow) {
            $pot = (int) ($teamRow['pot'] ?? 0);

            if ((POT_COMPETITIONS[$pot] ?? null) !== $competition) {
                continue;
            }

            $lines[] = '   • ' . resultsTeamLine($teamRow, $teamNames);
        }

        foreach (resultsPlayerActions($row[$keys['action']] ?? null) as $action) {
            $lines[] = '   ↳ ' . $action;
        }
    }

    return implode("\n", $lines);
}

/**
 * La classificació general, amb els punts de la jornada entre parèntesis.
 *
 * @param array<int, array>  $rows        Files de match_day_player_points.
 * @param array<int, string> $playerNames
 */
function resultsGeneralRankingText(array $rows, array $playerNames): string
{
    $lines = ['🏆 Classificació general'];

    foreach (resultsRanking($rows, 'total') as $row) {
        $lines[] = sprintf(
            '%s %s — %s (jornada %s)',
            resultsMedal((int) $row['position']),
            resultsPlayerName($playerNames, (int) $row['player_id']),
            resultsPoints((int) $row['total']),
            resultsPoints((int) $row['match_day_total'])
        );
    }

    return implode("\n", $lines);
}

/**
 * La classificació de la jornada, pels punts d'esta jornada.
 */
function resultsMatchDayRankingText(array $rows, array $playerNames, int $matchDay): string
{
    $lines = ['⚡ Classificació de la jornada ' . $matchDay];

    foreach (resultsRanking($rows, 'match_day_total') as $row) {
        $lines[] = sprintf(
            '%s %s — %s',
            resultsMedal((int) $row['position']),
            resultsPlayerName($playerNames, (int) $row['player_id']),
            resultsPoints((int) $row['match_day_total'])
        );
    }

    return implode("\n", $lines);
}

/**
 * Els millors de cada competició, amb tots els qui empaten al màxim.
 *
 * @return array<int, array{names: array<int, string>, points: int|null}>
 */
function resultsBestsByCompetition(array $rows, array $playerNames): array
{
    $bests = [];

    foreach (RESULTS_COMPETITION_KEYS as $competition => $keys) {
        $bestPoints = null;
        $names      = [];

        foreach ($rows as $row) {
            $points = (int) $row[$keys['total']];

            if ($bestPoints === null || $points > $bestPoints) {
                $bestPoints = $points;
                $names      = [];
            }

            if ($points === $bestPoints) {
                $name = resultsPlayerName($playerNames, (int) $row['player_id']);

                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        $bests[$competition] = ['names' => $names, 'points' => $bestPoints];
    }

    return $bests;
}

/**
 * El text dels millors de cada competició.
 */
function resultsBestsText(array $rows, array $playerNames): string
{
    $lines = ['Millors per competició'];

    foreach (resultsBestsByCompetition($rows, $playerNames) as $competition => $best) {
        $lines[] = sprintf(
            '%s: %s (%s)',
            $competition,
            count($best['names']) > 0 ? implode(', ', $best['names']) : '—',
            resultsPoints((int) ($best['points'] ?? 0))
        );
    }

    return implode("\n", $lines);
}

/**
 * La classificació general i els millors, en un sol missatge.
 */
function resultsSummaryText(array $rows, array $playerNames): string
{
    return resultsGeneralRankingText($rows, $playerNames)
        . "\n\n"
        . resultsBestsText($rows, $playerNames);
}

/**
 * Tots els missatges de la jornada, en l'ordre en què s'han d'enviar.
 *
 * @param array<int, array>       $playerPoints        Files de getAllMatchDayPlayerPoints().
 * @param array<int, array<int, array>> $teamPointsByPlayer [player_id => files de match_day_team_points].
 * @param array<int, string>      $playerNames
 * @param array<int, string>      $teamNames
 *
 * @return array<int, string>
 */
function resultsMessages(
    array $playerPoints,
    array $teamPointsByPlayer,
    array $playerNames,
    array $teamNames,
    int $matchDay
): array {
    $messages = [];

    // Un missatge per jugador, en l'ordre de la general.
    foreach (resultsRanking($playerPoints, 'total') as $row) {
        $playerId = (int) $row['player_id'];

        $messages[] = resultsPlayerText(
            $row,
            $teamPointsByPlayer[$playerId] ?? [],
            resultsPlayerName($playerNames, $playerId),
            $teamNames
        );
    }

    $messages[] = resultsSummaryText($playerPoints, $playerNames);
    $messages[] = resultsMatchDayRankingText($playerPoints, $playerNames, $matchDay);

    return $messages;
}
