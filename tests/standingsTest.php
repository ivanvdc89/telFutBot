<?php
// Prova de la classificació d'equips (config/standings.php): l'ordre dels
// criteris de desempat, que les posicions siguin per competició i que no
// s'hi coli cap paraula reservada d'SQL. Sense BD ni Telegram.
// S'executa des de l'arrel del projecte: php tests/standingsTest.php

require_once(__DIR__ . '/../config/results.php');
require_once(__DIR__ . '/../config/standings.php');

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

/** L'ordre de les files, en text, per comparar la classificació sencera. */
function order(array $rows): array
{
    return array_map(
        static fn(array $row): string => $row['competition'] . ' pos' . $row['ranking'] . ' equip' . $row['team_id'],
        $rows
    );
}

// ------------------------------------------------------------------- la regla

echo "--- fins a quina jornada compta ---\n";
check('la classificació acaba a la jornada 8', STANDINGS_LAST_MATCH_DAY, 8);
check(
    'que és la darrera de la fase de lliga',
    STANDINGS_LAST_MATCH_DAY,
    RESULTS_LEAGUE_MATCH_DAYS['to']
);

// --------------------------------------------------------------- els criteris

// CHL: una parella per a cada criteri de desempat.
$totals = [
    // 9 punts: ningú no l'atrapa.
    1  => ['competition' => 'CHL', 'points' => 9, 'goals_for' => 6, 'goals_against' => 2, 'match_days' => 3],
    // 6 punts amb +3: per davant dels altres de 6.
    2  => ['competition' => 'CHL', 'points' => 6, 'goals_for' => 5, 'goals_against' => 2, 'match_days' => 3],
    // 6 punts i +2, amb més gols a favor que el 4.
    3  => ['competition' => 'CHL', 'points' => 6, 'goals_for' => 4, 'goals_against' => 2, 'match_days' => 3],
    4  => ['competition' => 'CHL', 'points' => 6, 'goals_for' => 3, 'goals_against' => 1, 'match_days' => 3],
    // Tres equips idèntics (3 punts, 2-2): decideixen el pot i el nom.
    6  => ['competition' => 'CHL', 'points' => 3, 'goals_for' => 2, 'goals_against' => 2, 'match_days' => 3],
    7  => ['competition' => 'CHL', 'points' => 3, 'goals_for' => 2, 'goals_against' => 2, 'match_days' => 3],
    5  => ['competition' => 'CHL', 'points' => 3, 'goals_for' => 2, 'goals_against' => 2, 'match_days' => 3],
    // Una altra competició: la seva classificació comença de nou.
    8  => ['competition' => 'EUL', 'points' => 12, 'goals_for' => 7, 'goals_against' => 1, 'match_days' => 4],
    9  => ['competition' => 'EUL', 'points' => 0, 'goals_for' => 1, 'goals_against' => 6, 'match_days' => 4],
    // I un equip del qual no sabem ni el pot ni el nom: no ha de petar.
    10 => ['competition' => 'COL', 'points' => 1, 'goals_for' => 1, 'goals_against' => 1, 'match_days' => 1],
];

$teams = [
    1 => ['pot' => 1, 'name' => 'Alfa'],
    2 => ['pot' => 2, 'name' => 'Bravo'],
    3 => ['pot' => 2, 'name' => 'Charlie'],
    4 => ['pot' => 3, 'name' => 'Delta'],
    5 => ['pot' => 3, 'name' => 'Zulu'],
    6 => ['pot' => 1, 'name' => 'Alfa'],
    7 => ['pot' => 1, 'name' => 'Beta'],
    8 => ['pot' => 5, 'name' => 'Echo'],
    9 => ['pot' => 6, 'name' => 'Foxtrot'],
];

echo "\n--- punts, diferència de gols, gols a favor, pot i nom ---\n";
$rows = standingsRows($totals, $teams);

check('l\'ordre sencer', order($rows), [
    'CHL pos1 equip1',   // 9 punts
    'CHL pos2 equip2',   // 6 punts, +3
    'CHL pos3 equip3',   // 6 punts, +2, 4 gols a favor
    'CHL pos4 equip4',   // 6 punts, +2, 3 gols a favor
    'CHL pos5 equip6',   // 3 punts, pot 1, Alfa
    'CHL pos6 equip7',   // 3 punts, pot 1, Beta
    'CHL pos7 equip5',   // 3 punts, pot 3
    'EUL pos1 equip8',
    'EUL pos2 equip9',
    'COL pos1 equip10',
]);

echo "\n--- la diferència de gols i les jornades sumades ---\n";
$byTeam = [];
foreach ($rows as $row) {
    $byTeam[$row['team_id']] = $row;
}
check('la diferència es calcula', $byTeam[1]['goal_difference'], 4);
check('i pot ser negativa', $byTeam[9]['goal_difference'], -5);
check('les jornades sumades passen tal qual', $byTeam[8]['match_days'], 4);
check('i un equip desconegut no peta', $byTeam[10]['ranking'], 1);

echo "\n--- un empat absolut es decideix pel pot, i després pel nom ---\n";
check(
    'el pot més baix va davant',
    [$byTeam[6]['ranking'], $byTeam[5]['ranking']],
    [5, 7]
);
check(
    'i amb el mateix pot, el nom',
    [$byTeam[6]['ranking'], $byTeam[7]['ranking']],
    [5, 6]
);

// ------------------------------------------------------- paraules reservades

echo "\n--- cap paraula reservada d'SQL ---\n";
$resultModel   = file_get_contents(__DIR__ . '/../models/teamResult.php');
$standingModel = file_get_contents(__DIR__ . '/../models/teamStanding.php');
$schema        = file_get_contents(__DIR__ . '/../database/team_standings.sql');

// gf i gc són les que van fer fallar la consulta de la classificació: com que
// són reserves, no poden anar ni d'àlies ni de nom de columna.
foreach (['gf', 'gc', 'position', 'rank', 'groups', 'rows'] as $reserved) {
    check(
        "cap àlies ni columna '$reserved' al model",
        preg_match('/\bas\s+`?' . $reserved . '`?\b/i', $resultModel . $standingModel) === 1
            || preg_match('/^\s*`?' . $reserved . '`?\s+/mi', $schema) === 1,
        false
    );
}

check('la posició es diu ranking', str_contains($schema, 'ranking INT NOT NULL'), true);
check(
    'i l\'agregat fa servir total_goals_for',
    str_contains($resultModel, 'as total_goals_for'),
    true
);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
