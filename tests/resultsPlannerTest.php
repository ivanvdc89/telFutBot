<?php

/**
 * Què s'ha de desar d'una jornada, sense xarxa i sense base de dades.
 *
 * És la part on es decideix cada punt, i on es veu la regla dels estats exclosos:
 * d'un partit amb un equip exclòs només se'n desa el rival, i això no ha de
 * bloquejar la jornada ni sortir com a error.
 *
 *   php tests/resultsPlannerTest.php
 */

require_once(__DIR__ . '/../sources/ResultsPlanner.php');

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

/** Font de partits en memòria. */
final class FakeResultsSource implements ResultsSource
{
    /** @var array<string, array<int, array>> */
    private array $matches;

    public function __construct(array $matches)
    {
        $this->matches = $matches;
    }

    public function getMatches(string $competition, int $matchDay): array
    {
        return $this->matches[$competition] ?? [];
    }
}

/** Un partit, amb el país del local i del visitant. */
function partit(string $home, string $homeCountry, int $homeGoals, int $awayGoals, string $away, string $awayCountry): array
{
    return [
        'home'         => $home,
        'away'         => $away,
        'home_country' => $homeCountry,
        'away_country' => $awayCountry,
        'home_goals'   => $homeGoals,
        'away_goals'   => $awayGoals,
    ];
}

/** Les files a desar, en text, per comparar la llista sencera. */
function rowsText(array $plan): array
{
    $rows = array_map(
        static fn(array $row): string => sprintf('%s %s %d', $row['competition'], $row['teamName'], $row['points']),
        $plan['rows']
    );
    sort($rows);

    return $rows;
}

$teams = [
    ['id' => 41, 'name' => 'Lyon', 'competition' => 'EUL'],
    ['id' => 49, 'name' => 'GNKDinamo', 'competition' => 'EUL'],
    ['id' => 52, 'name' => 'SpartaPraha', 'competition' => 'EUL'],
    ['id' => 7, 'name' => 'Arsenal', 'competition' => 'CHL'],
    ['id' => 3, 'name' => 'RealMadrid', 'competition' => 'CHL'],
];
$resolver = new TeamNameResolver($teams, []);

function planner(array $matches, TeamNameResolver $resolver): ResultsPlanner
{
    return new ResultsPlanner(new FakeResultsSource($matches), $resolver, array_keys(RESULTS_EXCLUDED_COUNTRIES));
}

// ------------------------------------------------------------------- resultats

echo "--- victòria, empat i derrota ---\n";
$plan = planner(['EUL' => [
    partit('Olympique Lyon', 'Fra', 2, 0, 'Sparta Praha', 'Cze'),
    partit('Sparta Praha', 'Cze', 1, 1, 'GNK Dinamo', 'Cro'),
]], $resolver)->plan(['EUL'], 1);

check('tres punts al guanyador i cap al perdedor, i un a cada un a l\'empat', rowsText($plan), [
    'EUL GNKDinamo 1',
    'EUL Lyon 3',
    'EUL SpartaPraha 1',
]);

// ----------------------------------------------------------------------- exclosos

echo "\n--- un equip exclòs: només es desa el rival ---\n";
$plan = planner(['EUL' => [
    partit('Hapoel Beer-Sheva', 'Isr', 0, 0, 'GNK Dinamo', 'Cro'),
    partit('Olympique Lyon', 'Fra', 1, 2, 'Maccabi Tel-Aviv', 'Isr'),
]], $resolver)->plan(['EUL'], 1);

check('el rival de l\'exclòs sí que es desa', rowsText($plan), [
    'EUL GNKDinamo 1',
    'EUL Lyon 0',
]);
check('i no és cap problema', $plan['problems'], []);
check('l\'equip exclòs queda marcat', $plan['matches'][0]['home']['excluded'], true);
check('el rival no', $plan['matches'][0]['away']['excluded'], false);
check('els punts del rival es calculen igual (1 a l\'empat)', $plan['matches'][0]['away']['points'], 1);
check('si perd, rep 0', $plan['matches'][1]['home']['points'], 0);

echo "\n--- si tots dos són exclosos, no es desa res ---\n";
$plan = planner(['EUL' => [
    partit('Hapoel Beer-Sheva', 'Isr', 0, 1, 'Maccabi Tel-Aviv', 'Isr'),
]], $resolver)->plan(['EUL'], 1);
check('cap fila', rowsText($plan), []);
check('i cap problema', $plan['problems'], []);

echo "\n--- el codi de país és el que mana ---\n";
check("'Isr' està exclòs", resultsCountryIsExcluded('Isr'), true);
check("'Esp' no", resultsCountryIsExcluded('Esp'), false);
check('un país buit no', resultsCountryIsExcluded(''), false);
check('un país desconegut no', resultsCountryIsExcluded(null), false);

// --------------------------------------------------------------------- problemes

echo "\n--- un equip que no és exclòs i no es reconeix sí que és un problema ---\n";
$plan = planner(['EUL' => [
    partit('Olympique Lyon', 'Fra', 2, 1, 'Equip Inventat', 'Atl'),
]], $resolver)->plan(['EUL'], 1);

check('el resol·lubles es desa igualment', rowsText($plan), ['EUL Lyon 3']);
check('i l\'altre queda com a problema', count($plan['problems']), 1);
check('amb el seu nom', $plan['problems'][0]['name'], 'Equip Inventat');
check('i la competició', $plan['problems'][0]['competition'], 'EUL');

// ------------------------------------------------------------------------ buit

echo "\n--- competicions sense partits ---\n";
$plan = planner(['EUL' => [
    partit('Olympique Lyon', 'Fra', 1, 0, 'Sparta Praha', 'Cze'),
]], $resolver)->plan(['EUL', 'COL'], 1);

check('la que té partits no surt a la llista de buides', in_array('EUL', $plan['empty'], true), false);
check('la que no en té, sí', $plan['empty'], ['COL']);

// --------------------------------------------------------------------- duplicats

echo "\n--- si la font repetís un partit, no es compta dues vegades ---\n";
$repeated = partit('Olympique Lyon', 'Fra', 3, 0, 'Sparta Praha', 'Cze');
$plan = planner(['EUL' => [$repeated, $repeated]], $resolver)->plan(['EUL'], 1);
check('dues files i no quatre', count($plan['rows']), 2);

// ------------------------------------------------------------------- altres jornades

echo "\n--- la competició filtra el traductor ---\n";
// "Sparta Praha" és de l'EUL: si es busqués a la CHL, no s'hauria de resoldre.
$plan = planner(['CHL' => [
    partit('Sparta Praha', 'Cze', 1, 0, 'Arsenal', 'Eng'),
]], $resolver)->plan(['CHL'], 1);
check('el de la CHL es resol', rowsText($plan), ['CHL Arsenal 0']);
check('i el de l\'EUL, no', count($plan['problems']), 1);
check('i és el Sparta', $plan['problems'][0]['name'], 'Sparta Praha');

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
