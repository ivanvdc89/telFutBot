<?php

/**
 * El traductor de noms de kassiesa.net als noms de la taula teams.
 *
 * Aquesta és la part que pot fer més mal: si un equip es confon amb un altre,
 * rep punts que no toquen i ningú no se n'adona. Per això les proves no miren
 * només els casos fàcils, sinó també que:
 *
 *   - "Omonia Nicosia" trobi "Omonia" i "Olympique Lyon" trobi "Lyon" sense
 *     confondre'ls amb "Olympiacos";
 *   - la competició separi equips amb el mateix nom ("Sporting" és CP a l'EUL i
 *     Braga a l'EUL, però a la CHL no hi ha cap dels dos);
 *   - quan dos candidats estan empatats, no s'endevini: es retorna null.
 *
 *   php tests/teamNameResolverTest.php
 */

require_once(__DIR__ . '/../sources/TeamNameResolver.php');

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

$teams = [];
$nextId = 1;
foreach ([
    // [nom a la BD, competició]
    ['SpartaPrague', 'EUL'],
    ['Omonia', 'EUL'],
    ['Olympiacos', 'EUL'],
    ['Lyon', 'EUL'],
    ['Celje', 'EUL'],
    ['Copenhagen', 'EUL'],
    ['VilaReal', 'EUL'],
    ['PSVEindhoven', 'EUL'],
    ['SportingCP', 'EUL'],
    ['Braga', 'EUL'],
    ['UnionSaintGilloise', 'EUL'],
    ['RedBullSalzburg', 'EUL'],
    ['Brann', 'EUL'],
    ['Ferencvaros', 'EUL'],
    ['DynamoKyiv', 'EUL'],
    ['DynamoZagreb', 'EUL'],
    ['Lille', 'EUL'],
    ['Lillestrom', 'EUL'],
    ['LausanneSport', 'COL'],
    ['GoAheadEagles', 'COL'],
    ['BKHacken', 'COL'],
    ['RakowCzestochowa', 'COL'],
    ['Inter', 'CHL'],
    ['BayernMunich', 'CHL'],
    ['BodøGlimt', 'CHL'],
    ['Barcelona', 'CHL'],
    ['RealMadrid', 'CHL'],
    ['RealSociedad', 'CHL'],
    ['ParisSaintGermain', 'CHL'],
] as [$name, $competition]) {
    $teams[] = ['id' => $nextId++, 'name' => $name, 'competition' => $competition];
}

$resolver = new TeamNameResolver($teams, require __DIR__ . '/../config/teamAliases.php');

/** El nom de la BD que resol un nom de la font, o "???" si no s'hi atreveix. */
function resolved(TeamNameResolver $resolver, string $name, string $competition): string
{
    $result = $resolver->resolve($name, $competition);

    return $result['name'] ?? '???';
}

// ------------------------------------------------------------------- casos

echo "--- noms amb ciutat o paraula de club de més ---\n";
check('Sparta Praha -> SpartaPrague', resolved($resolver, 'Sparta Praha', 'EUL'), 'SpartaPrague');
check('Omonia Nicosia -> Omonia', resolved($resolver, 'Omonia Nicosia', 'EUL'), 'Omonia');
check('Olympiakos Piraeus -> Olympiacos', resolved($resolver, 'Olympiakos Piraeus', 'EUL'), 'Olympiacos');
check('Olympique Lyon -> Lyon (i no Olympiacos)', resolved($resolver, 'Olympique Lyon', 'EUL'), 'Lyon');
check('NK Celje -> Celje', resolved($resolver, 'NK Celje', 'EUL'), 'Celje');
check('SK Brann Bergen -> Brann', resolved($resolver, 'SK Brann Bergen', 'EUL'), 'Brann');
check('Lillestrøm SK -> Lillestrom', resolved($resolver, 'Lillestrøm SK', 'EUL'), 'Lillestrom');
check('Lille OSC -> Lille', resolved($resolver, 'Lille OSC', 'EUL'), 'Lille');

echo "\n--- un començament de paraula no és una coincidència ---\n";
// "Lillestrøm" comença per "Lille": si es tragués el Lille de la BD, el nom no
// s'ha de resoldre a Lille.
$withoutLillestrom = new TeamNameResolver(
    array_values(array_filter($teams, static fn(array $team): bool => $team['name'] !== 'Lillestrom')),
    []
);
check('sense el Lillestrom a la BD, no cau al Lille', resolved($withoutLillestrom, 'Lillestrøm SK', 'EUL'), '???');

echo "\n--- noms en una altra llengua ---\n";
check('FC København -> Copenhagen', resolved($resolver, 'FC København', 'EUL'), 'Copenhagen');
check('Bayern München -> BayernMunich', resolved($resolver, 'Bayern München', 'CHL'), 'BayernMunich');
check('Bodø/Glimt -> BodøGlimt', resolved($resolver, 'Bodø/Glimt', 'CHL'), 'BodøGlimt');

echo "\n--- diferències d'escriptura ---\n";
check('Villarreal -> VilaReal', resolved($resolver, 'Villarreal', 'EUL'), 'VilaReal');
check('PSV Eindhoven -> PSVEindhoven', resolved($resolver, 'PSV Eindhoven', 'EUL'), 'PSVEindhoven');
check('FC Salzburg -> RedBullSalzburg', resolved($resolver, 'FC Salzburg', 'EUL'), 'RedBullSalzburg');
check('Ferencváros -> Ferencvaros', resolved($resolver, 'Ferencváros', 'EUL'), 'Ferencvaros');

echo "\n--- prefixos de club que la BD no porta ---\n";
check('Lausanne Sports -> LausanneSport', resolved($resolver, 'Lausanne Sports', 'COL'), 'LausanneSport');
check('Go Ahead Eagles -> GoAheadEagles', resolved($resolver, 'Go Ahead Eagles', 'COL'), 'GoAheadEagles');
check('BK Häcken -> BKHacken', resolved($resolver, 'BK Häcken', 'COL'), 'BKHacken');
check('Raków Czestochowa -> RakowCzestochowa', resolved($resolver, 'Raków Czestochowa', 'COL'), 'RakowCzestochowa');

echo "\n--- el mateix nom en dues competicions ---\n";
check('a l\'EUL, Sporting CP és SportingCP', resolved($resolver, 'Sporting CP Lisbon', 'EUL'), 'SportingCP');
check('i Sporting Braga és Braga', resolved($resolver, 'Sporting Braga', 'EUL'), 'Braga');
check('a la CHL no hi ha cap Sporting', resolved($resolver, 'Sporting CP Lisbon', 'CHL'), '???');
check('ni cap Real Sociedad a la CHL... ', resolved($resolver, 'Real Sociedad', 'EUL'), '???');

echo "\n--- àlies ---\n";
check('Internazionale -> Inter', resolved($resolver, 'Internazionale', 'CHL'), 'Inter');
check('el nom oficial també hi arriba', resolved($resolver, 'Inter Milan', 'CHL'), 'Inter');

echo "\n--- quan no s'hi pot comprometre, no ho fa ---\n";
$ambiguous = $resolver->rank('Dynamo', 'EUL');
check('"Dynamo" podria ser Kyiv o Zagreb', $ambiguous['ambiguous'], true);
check('i no tria cap dels dos', $ambiguous['id'], null);
check('però ensenya els candidats', count($ambiguous['candidates']) >= 2, true);

$tooShort = $resolver->rank('Real', 'CHL');
check('un tros curt i genèric no arrossega cap equip', $tooShort['id'], null);

$unknown = $resolver->rank('Maccabi Tel-Aviv', 'EUL');
check('un equip que no hi és no es resol', $unknown['id'], null);
check('i no és ambigu', $unknown['ambiguous'], false);

echo "\n--- normalització ---\n";
check('Beşiktaş', TeamNameResolver::normalize('Beşiktaş'), 'besiktas');
check('Bodø/Glimt', TeamNameResolver::normalize('Bodø/Glimt'), 'bodoglimt');
check('União Torreense', TeamNameResolver::normalize('União Torreense'), 'uniaotorreense');
check('Ferencváros', TeamNameResolver::normalize('Ferencváros'), 'ferencvaros');
check('Saint-Gilloise', TeamNameResolver::normalize('Saint-Gilloise'), 'saintgilloise');

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
