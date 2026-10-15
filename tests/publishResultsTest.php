<?php
// Prova dels missatges de publishResults.php (config/publishResults.php): el
// detall de cada jugador, les classificacions alineades, els millors, els
// empats i el bloc de codi. Sense BD ni Telegram.
// S'executa des de l'arrel del projecte: php tests/publishResultsTest.php

require_once(__DIR__ . '/../config/publishResults.php');

$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    printf("%s %s\n", $ok ? '  ok  ' : ' FAIL ', $label);
    if (!$ok) {
        printf("         esperat : %s\n", json_encode($expected, JSON_UNESCAPED_UNICODE));
        printf("         obtingut: %s\n", json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

/** Un text de diverses línies, per no haver d'escriure \n per tot arreu. */
function text(array $lines): string
{
    return implode("\n", $lines);
}

// --------------------------------------------------------------- les dades

// Dos jugadors: Ivan (17 pts, jornada 7) i Marta (12 pts, jornada 9).
$playerPoints = [
    [
        'player_id'        => 1,
        'match_day'        => 2,
        'chl_points'       => 3,
        'chl_action'       => json_encode([['type' => 'malDia', 'result' => 'KO']]),
        'chl_sum'          => 2,
        'chl_total'        => 5,
        'eul_points'       => 1,
        'eul_action'       => '[]',
        'eul_sum'          => 1,
        'eul_total'        => 4,
        'col_points'       => 4,
        'col_action'       => '',
        'col_sum'          => 4,
        'col_total'        => 8,
        'match_day_action' => '',
        'match_day_total'  => 7,
        'total'            => 17,
    ],
    [
        'player_id'        => 2,
        'match_day'        => 2,
        'chl_points'       => 3,
        'chl_action'       => '[]',
        'chl_sum'          => 9,
        'chl_total'        => 12,
        'eul_points'       => 0,
        'eul_action'       => '[]',
        'eul_sum'          => 0,
        'eul_total'        => 0,
        'col_points'       => 0,
        'col_action'       => '[]',
        'col_sum'          => 0,
        'col_total'        => 0,
        'match_day_action' => '',
        'match_day_total'  => 9,
        'total'            => 12,
    ],
];

$teamPointsByPlayer = [
    1 => [
        ['pot' => 1, 'team_id' => 10, 'points' => 3, 'total' => 0, 'action' => json_encode(['type' => 'dobleORes', 'result' => 'Res'])],
        ['pot' => 2, 'team_id' => 4,  'points' => 3, 'total' => 3, 'action' => ''],
        ['pot' => 5, 'team_id' => 20, 'points' => 1, 'total' => 1, 'action' => null],
        ['pot' => 9, 'team_id' => 86, 'points' => 4, 'total' => 8, 'action' => json_encode(['type' => 'dobleORes', 'result' => 'Doble'])],
    ],
    2 => [
        ['pot' => 3, 'team_id' => 5, 'points' => 3, 'total' => 9, 'action' => json_encode(['type' => 'segurQuePasse', 'result' => 'YES'])],
    ],
];

$playerNames = [1 => 'Ivan', 2 => 'Marta'];
$teamNames   = [10 => 'Arsenal', 4 => 'Bayern', 20 => 'Roma', 86 => 'Celtic', 5 => 'Chelsea'];

$messages = resultsMessages($playerPoints, $teamPointsByPlayer, $playerNames, $teamNames, 2);
check('un missatge per jugador, la general i la jornada', count($messages), 4);

// ------------------------------------------------------- el detall del jugador

echo "--- el missatge d'un jugador ---\n";

check('Ivan', $messages[0]['text'], text([
    '🥇 Ivan — 17 pts · jornada 7 pts',
    '',
    'CHL · jornada 2 pts · total 5 pts',
    '   • Arsenal: 3 → 0 pts — #dobleORes: Res',
    '   • Bayern: 3 pts',
    '   ↳ #malDia: KO',
    '',
    'EUL · jornada 1 pt · total 4 pts',
    '   • Roma: 1 pt',
    '',
    'COL · jornada 4 pts · total 8 pts',
    '   • Celtic: 4 → 8 pts — #dobleORes: Doble',
]));

check('Marta', $messages[1]['text'], text([
    '🥈 Marta — 12 pts · jornada 9 pts',
    '',
    'CHL · jornada 9 pts · total 12 pts',
    '   • Chelsea: 3 → 9 pts — #segurQuePasse: YES',
    '',
    'EUL · jornada 0 pts · total 0 pts',
    '',
    'COL · jornada 0 pts · total 0 pts',
]));

// Els detalls van sense parse_mode: aixina cap nom pot trencar el missatge.
check('els detalls van sense format', [$messages[0]['parse_mode'], $messages[1]['parse_mode']], [null, null]);

// ----------------------------------------------------------- les classificacions

echo "\n--- la classificació general ---\n";

check('alineada', resultsGeneralRankingText($playerPoints, $playerNames), text([
    '🏆 Classificació general',
    '1. Ivan   17 pts (jornada 7 pts)',
    '2. Marta  12 pts (jornada 9 pts)',
]));

echo "\n--- la classificació de la jornada ---\n";

check('alineada', resultsMatchDayRankingText($playerPoints, $playerNames, 2), text([
    '⚡ Classificació de la jornada 2',
    '1. Marta  9 pts',
    '2. Ivan   7 pts',
]));

echo "\n--- els millors, amb la general ---\n";

check('general i millors, en un sol text', resultsSummaryText($playerPoints, $playerNames), text([
    '🏆 Classificació general',
    '1. Ivan   17 pts (jornada 7 pts)',
    '2. Marta  12 pts (jornada 9 pts)',
    '',
    'Millors per competició',
    'CHL: Marta (12 pts)',
    'EUL: Ivan (4 pts)',
    'COL: Ivan (8 pts)',
]));

echo "\n--- el bloc de codi ---\n";

check(
    'les classificacions van dins d\'<pre> i amb HTML',
    [$messages[2]['parse_mode'], $messages[3]['parse_mode'], $messages[2]['text'] === '<pre>' . resultsSummaryText($playerPoints, $playerNames) . '</pre>'],
    ['HTML', 'HTML', true]
);

check('el text del bloc s\'escapa', resultsPre('A & B < C'), '<pre>A &amp; B &lt; C</pre>');

// ----------------------------------------------------------- els empats i medalles

echo "\n--- les posicions amb empats ---\n";

$tied = resultsRanking([
    ['player_id' => 1, 'total' => 10, 'match_day_total' => 5],
    ['player_id' => 2, 'total' => 10, 'match_day_total' => 3],
    ['player_id' => 3, 'total' => 4, 'match_day_total' => 1],
], 'total');

check('els empatats comparteixen posició i el següent se la salta', array_column($tied, 'position'), [1, 1, 3]);
check('i l\'ordre dels iguals es manté', array_column($tied, 'player_id'), [1, 2, 3]);

check('medalla al primer (al detall del jugador)', resultsMedal(1), '🥇');
check('medalla al tercer', resultsMedal(3), '🥉');
check('número a partir del quart', resultsMedal(4), '4.');

check('singular', resultsPoints(1), '1 pt');
check('singular en negatiu', resultsPoints(-1), '-1 pt');
check('zero en plural', resultsPoints(0), '0 pts');
check('plural', resultsPoints(3), '3 pts');

echo "\n--- l'amplària de les columnes ---\n";

// Noms de mides distintes i xifres d'una i de tres xifres: totes les columnes
// han de començar al mateix lloc.
$wideRows = [
    ['player_id' => 1, 'total' => 5, 'match_day_total' => 5],
    ['player_id' => 2, 'total' => 100, 'match_day_total' => 1],
    ['player_id' => 3, 'total' => 5, 'match_day_total' => 5],
];

check('les columnes s\'omplin pel més llarg', resultsRankingLines(
    resultsRanking($wideRows, 'total'),
    [1 => 'A', 2 => 'NomMoltLlarg', 3 => 'Bé'],
    'total',
    true
), [
    '1. NomMoltLlarg  100 pts (jornada 1 pt)',
    '2. A' . str_repeat(' ', 15) . '5 pts (jornada 5 pts)',
    '2. Bé' . str_repeat(' ', 14) . '5 pts (jornada 5 pts)',
]);

check('els accents conten com un caràcter', resultsPad('Bé', 5), 'Bé   ');

// ------------------------------------------------------- les accions i les files

echo "\n--- les accions ---\n";

check('acció d\'equip', resultsTeamAction('{"type":"kosAmbEscuts","result":"Escut"}'), '#kosAmbEscuts: Escut');
check('sense acció (null)', resultsTeamAction(null), null);
check('sense acció (buit)', resultsTeamAction(''), null);
check('sense acció (llista buida)', resultsTeamAction('[]'), null);
check('JSON que no és una acció', resultsTeamAction('{"foo":1}'), null);

check('accions de jugador', resultsPlayerActions(json_encode([
    ['type' => 'guanyarOMorir', 'result' => 2],
    ['type' => 'substitució', 'result' => -6],
])), ['#guanyarOMorir: 2', 'substitució: -6']);
check('sense accions de jugador', resultsPlayerActions('[]'), []);

echo "\n--- un equip sense nom conegut ---\n";
check(
    's\'identifica amb #id',
    resultsTeamLine(['pot' => 1, 'team_id' => 123, 'points' => 3, 'total' => 3, 'action' => ''], []),
    '#123: 3 pts'
);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
