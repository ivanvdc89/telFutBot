<?php

/**
 * El traductor de noms contra la taula teams de debò.
 *
 * La prova de TeamNameResolverTest.php fa servir una llista inventada per
 * provar les regles. Aquesta fa servir la taula real de la temporada 2026/2027
 * (tests/fixtures/teams-2027.php) i els noms tal com surten a la pàgina de
 * kassiesa, que és on es veu si el joc funciona o no:
 *
 *   - "PSV Eindhoven" ha de trobar "PSV", no "PSVEindhoven";
 *   - "Sparta Praha" ha de trobar "SpartaPraha";
 *   - "FC Salzburg" ha de trobar "Salzburg";
 *   - "Red Star Belgrade" ha de trobar "CrvenaZvezda".
 *
 * Si algun dia es canvia la taula teams i un nom deixa de reconèixer-se, és
 * aquí on es nota abans de calcular la jornada.
 *
 *   php tests/teamNameResolverRealTeamsTest.php
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

$teams    = require __DIR__ . '/fixtures/teams-2027.php';
$resolver = new TeamNameResolver($teams, require __DIR__ . '/../config/teamAliases.php');

/**
 * Nom de kassiesa -> nom de la taula teams, per competició.
 *
 * Són els partits de la jornada 1 de la fase de lliga, tal com els dona
 * https://kassiesa.net/uefa/data/method5/match2027.html
 */
$expected = [
    'CHL' => [
        'AEK Athens'          => 'AEKAthens',
        'AS Roma'             => 'Roma',
        'Arsenal'             => 'Arsenal',
        'Aston Villa'         => 'AstonVilla',
        'Atlético Madrid'     => 'AtleticoMadrid',
        'Bayern München'      => 'BayernMunich',
        'Bodø/Glimt'          => 'BodoGlimt',
        'Borussia Dortmund'   => 'BorussiaDortmund',
        'Club Brugge'         => 'ClubBrugge',
        'Como 1907'           => 'Como',
        'FC Barcelona'        => 'Barcelona',
        'FC Porto'            => 'Porto',
        'Fenerbahçe'          => 'Fenerbahce',
        'Feyenoord'           => 'Feyenoord',
        'Galatasaray'         => 'Galatasaray',
        'Internazionale'      => 'Inter',
        'LASK'                => 'LASK',
        'Lille OSC'           => 'Lille',
        'Liverpool'           => 'Liverpool',
        'Manchester City'     => 'ManchesterCity',
        'Manchester United'   => 'ManchesterUnited',
        'Napoli'              => 'Napoli',
        'PSV Eindhoven'       => 'PSV',
        'Paris Saint-Germain' => 'ParisSaintGermain',
        'RB Leipzig'          => 'RBLeipzig',
        'RC Lens'             => 'Lens',
        'Real Betis'          => 'RealBetis',
        'Real Madrid'         => 'RealMadrid',
        'Sabah FK'            => 'Sabah',
        'Shakhtar Donetsk'    => 'ShakhtarDonetsk',
        'Slavia Praha'        => 'SlaviaPrague',
        'Slovan Bratislava'   => 'SlovanBratislava',
        'Sporting CP Lisbon'  => 'SportingCP',
        'VfB Stuttgart'       => 'Stuttgart',
        'Viking Stavanger'    => 'Viking',
        'Villarreal'          => 'Villarreal',
    ],
    'EUL' => [
        '1899 Hoffenheim'      => 'Hoffenheim',
        'AC Milan'             => 'Milan',
        'AFC Bournemouth'      => 'Bournemouth',
        'AZ Alkmaar'           => 'AZ',
        'Anderlecht'           => 'Anderlecht',
        'Ararat-Armenia'       => 'AraratArmenia',
        'Bayer Leverkusen'     => 'Leverkusen',
        'Benfica'              => 'Benfica',
        'Besiktas'             => 'Besiktas',
        'Celta de Vigo'        => 'Celta',
        'Celtic'               => 'Celtic',
        'Crystal Palace'       => 'CrystalPalace',
        'Dinamo Zagreb'        => 'GNKDinamo',
        'FC Salzburg'          => 'Salzburg',
        'Ferencváros'          => 'Ferencvaros',
        'Jagiellonia Bialystok' => 'Jagiellonia',
        'Juventus'             => 'Juventus',
        'Lech Poznan'          => 'LechPoznan',
        'Levski Sofia'         => 'LevskiSofia',
        'Lillestrøm SK'        => 'Lillestrom',
        'NEC Nijmegen'         => 'NEC',
        'NK Celje'             => 'Celje',
        'OFI Heraklion'        => 'OFICrete',
        'Olympiakos Piraeus'   => 'Olympiacos',
        'Olympique Lyon'       => 'Lyon',
        'Olympique Marseille'  => 'Marseille',
        'Omonia Nicosia'       => 'Omonia',
        'Real Sociedad'        => 'RealSociedad',
        'Sparta Praha'         => 'SpartaPraha',
        'Stade Rennais'        => 'Rennes',
        'Sturm Graz'           => 'SturmGraz',
        'Sunderland AFC'       => 'Sunderland',
        'Union Saint-Gilloise' => 'UnionSG',
        'União Torreense'      => 'Torreense',
        'Viktoria Plzen'       => 'ViktoriaPlzen',
    ],
    'COL' => [
        'Atalanta'                => 'Atalanta',
        'Sporting Braga'          => 'Braga',
        'Ajax'                    => 'Ajax',
        'SC Freiburg'             => 'Freiburg',
        'AS Monaco'               => 'Monaco',
        'FC København'            => 'Copenhagen',
        'FC Midtjylland'          => 'Midtjylland',
        'Red Star Belgrade'       => 'CrvenaZvezda',
        'KAA Gent'                => 'Gent',
        'Panathinaikos'           => 'Panathinaikos',
        'Pafos FC'                => 'Pafos',
        'Brighton & Hove Albion'  => 'Brighton',
        'FC Lugano'               => 'Lugano',
        'Getafe CF'               => 'Getafe',
        'KuPS Kuopio'             => 'KuPSKuopio',
        'FC Twente Enschede'      => 'Twente',
        'Lincoln Red Imps'        => 'LincolnRedImps',
        'Borac Banja Luka'        => 'Borac',
        'Sint-Truiden'            => 'SintTruidense',
        'SK Brann Bergen'         => 'Brann',
        'Hearts FC'               => 'Hearts',
        'Kairat Almaty'           => 'KairatAlmaty',
        'Trabzonspor'             => 'Trabzonspor',
        'Universitatea Craiova'   => 'UCraiova',
        'Riga FC'                 => 'Riga',
        'Hajduk Split'            => 'HajdukSplit',
        'FK Jablonec'             => 'Jablonec',
        'FC Nordsjælland'         => 'Nordsjaelland',
        'AGF Aarhus'              => 'Aarhus',
        "Inter Club d'Escaldes"   => 'InterEscaldes',
        'FC Thun'                 => 'Thun',
        'CSKA Sofia'              => 'CSKASofia',
        'Kauno Zalgiris'          => 'KaunoZalgiris',
        'Mjällby AIF'             => 'Mjallby',
        'Iberia 1999'             => 'IberiaTbilisi',
        'Egnatia Rrogozhinë'      => 'Egnatia',
    ],
];

/**
 * Noms de kassiesa que NO s'han de resoldre mai.
 *
 * Són els equips dels estats exclosos (vegeu RESULTS_EXCLUDED_COUNTRIES a
 * config/results.php): no són a la taula teams ni hi han de ser, i la prova ho
 * deixa escrit. El que impedeix de debò que se'n desin punts és el filtre per
 * país de ResultsPlanner (vegeu resultsPlannerTest.php), que actua abans de
 * mirar cap nom.
 */
$missing = [
    'EUL' => ['Hapoel Beer-Sheva'],
];

// --------------------------------------------------------------- comprovacions

foreach ($expected as $competition => $pairs) {
    echo "--- $competition: els noms de kassiesa han de trobar el seu equip ---\n";

    foreach ($pairs as $source => $teamName) {
        $result = $resolver->resolve($source, $competition);
        check(sprintf('%-24s -> %s', $source, $teamName), $result['name'] ?? '???', $teamName);
    }

    echo "\n";
}

echo "--- i aquests no s'han de resoldre mai (estats exclosos) ---\n";
foreach ($missing as $competition => $names) {
    foreach ($names as $name) {
        check(sprintf('[%s] %s queda pendent', $competition, $name), $resolver->resolve($name, $competition), null);
    }
}

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
