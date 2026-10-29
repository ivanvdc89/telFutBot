<?php
// Prova del repartiment de punts d'una jornada (config/matchDayPoints.php):
// els recomptes de les votacions (#dobleORes, #kosAmbEscuts), els punts de cada
// equip (escuts, segurQuePasse) i el llindar del #socElMillor. Sense BD ni
// Telegram.
// S'executa des de l'arrel del projecte: php tests/matchDayPointsTest.php

require_once(__DIR__ . '/../config/results.php');
require_once(__DIR__ . '/../config/matchDayPoints.php');

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

/**
 * El que mira el test d'una fila de matchDayTeamPoints: el que es desa a la
 * base de dades i el que acaba sumant.
 */
function rowView(array $row): array
{
    return [
        'team'   => $row['team_id'],
        'points' => $row['points'],
        'total'  => $row['total'],
        'action' => $row['action'] === null ? null : $row['action']['type'] . '/' . $row['action']['result'],
    ];
}

// ------------------------------------------------------- #dobleORes i #kos

echo "--- sense cap vot no es toca res ---\n";
check('votacions buides', matchDayVoteResults([], [], []), [
    'nothingTeams' => [],
    'doubleTeams'  => [],
    'koTeams'      => [],
]);

// Dos equips a CHL, tres a EUL i un a COL, per vore el repartiment per
// competició. L'equip 10 i el 4 són de competicions distintes.
$teamCompetitions = [
    10 => 'CHL',
    86 => 'CHL',
    4  => 'EUL',
    5  => 'EUL',
    20 => 'COL',
];

echo "\n--- #dobleORes: el més votat a 0 i el segon a doble, per competició ---\n";
$votes = matchDayVoteResults([10, 10, 86, 4, 5, 20, 20], [10, 10, 4, 86, 20], $teamCompetitions);

check('el més votat de cada competició queda a zero', $votes['nothingTeams'], [10, 4, 20]);
// A EUL els dos equips tenen un vot: sense classificació, el primer votat (4)
// és el més votat i el 5 el segon. A COL només n'hi ha un, i a CHL el segon és
// el 86.
check('el segon més votat suma doble', $votes['doubleTeams'], [86, 5]);
check('els 4 més votats del boicot', $votes['koTeams'], [10, 4, 86, 20]);

echo "\n--- #dobleORes: l'empat el decidix la classificació ---\n";
// 10 i 86 empatats a 2 vots a CHL; el 86 va millor classificat.
$tie = matchDayVoteResults([10, 86, 10, 86], [], $teamCompetitions, [86 => 1, 10 => 2]);
check('el millor classificat queda a zero', $tie['nothingTeams'], [86]);
check('l\'altre suma doble', $tie['doubleTeams'], [10]);

echo "\n--- #dobleORes: el segon pot ser un equip sense vots ---\n";
// A CHL només el 10 té vots: el 86, sense vots, és el millor classificat i
// suma doble. A EUL no hi ha cap vot, així que mana la classificació: el 4
// queda a 0 i el 5 dobla. A COL tampoc no hi ha vots ni classificació, i no
// s'inventa res.
$zeroSecond = matchDayVoteResults([10], [], $teamCompetitions, [86 => 1, 10 => 2, 4 => 1, 5 => 2]);
check('el més votat queda a zero', $zeroSecond['nothingTeams'], [10, 4]);
check('el millor classificat sense vots suma doble', $zeroSecond['doubleTeams'], [86, 5]);

echo "\n--- sense cap vot a l'acció no es reparteix res ---\n";
$noVotes = matchDayVoteResults([], [], $teamCompetitions, [86 => 1, 10 => 2, 4 => 1, 5 => 2, 20 => 1]);
check('ni amb la classificació carregada', [$noVotes['nothingTeams'], $noVotes['doubleTeams']], [[], []]);

echo "\n--- #kosAmbEscuts: el tall dels 4 també desempata per classificació ---\n";
$kosTie = matchDayVoteResults([], [10, 86, 20, 4, 5], $teamCompetitions, [5 => 1, 4 => 2, 20 => 3, 86 => 4, 10 => 5]);
check('el pitjor classificat dels empatats queda fora', $kosTie['koTeams'], [5, 4, 20, 86]);

// El boicot només agafa KOS_BOICOT_TEAMS (4) equips, i els que no tenen cap vot
// no hi entren.
$many = matchDayVoteResults([], [1, 1, 2, 2, 3, 3, 4, 4, 5, 5], []);
check('el boicot són els 4 primers', $many['koTeams'], [1, 2, 3, 4]);

echo "\n--- #kosAmbEscuts sense vots: mana la classificació ---\n";
// Sense cap vot, però amb la classificació carregada, la norma diu que queden
// bloquejats els 4 equips amb més punts.
$kosNoVotes = matchDayVoteResults([], [], $teamCompetitions, [], [5 => 9, 4 => 7, 20 => 5, 86 => 3, 10 => 1]);
check('els 4 amb més punts', $kosNoVotes['koTeams'], [5, 4, 20, 86]);

$kosPointsTie = matchDayVoteResults([], [], $teamCompetitions, [4 => 1, 5 => 2], [5 => 5, 4 => 5]);
check('empat de punts: mana la posició', $kosPointsTie['koTeams'], [4, 5]);

// Amb vots, la classificació no decidix res: només compta el que s'ha votat.
$kosWithVotes = matchDayVoteResults([], [10], $teamCompetitions, [], [5 => 99, 10 => 0]);
check('amb vots, només compten els vots', $kosWithVotes['koTeams'], [10]);

echo "\n--- #kosPais: el país arrossega tots els seus equips ---\n";
$pais = matchDayVoteResults([], [], $teamCompetitions, [], [], [
    'paisos' => [
        'votes'  => ['Spain', 'Spain', 'England'],
        'teams'  => ['Spain' => [10, 4], 'England' => [86], 'Italy' => [5]],
        'points' => ['Spain' => 12, 'England' => 9, 'Italy' => 3],
    ],
]);
check('els 2 països més votats, amb tots els seus equips', $pais['koTeams'], [10, 4, 86]);

echo "\n--- #kosPais: l'empat el decidix la suma de punts ---\n";
$paisTie = matchDayVoteResults([], [], $teamCompetitions, [], [], [
    'paisos' => [
        'votes'  => ['Spain', 'England', 'Italy'],
        'teams'  => ['Spain' => [10], 'England' => [86], 'Italy' => [4]],
        'points' => ['Spain' => 9, 'England' => 5, 'Italy' => 5],
    ],
]);
// Tres països empatats a 1 vot: entren els 2 de més punts. England i Italy
// empaten a 5 i decideix el nom.
check('mana la suma de punts', $paisTie['koTeams'], [10, 86]);

echo "\n--- #kosPais sense vots: els 2 països amb més punts ---\n";
$paisNoVotes = matchDayVoteResults([], [], $teamCompetitions, [], [], [
    'paisos' => [
        'votes'  => [],
        'teams'  => ['Spain' => [10], 'England' => [86], 'Italy' => [4]],
        'points' => ['Spain' => 3, 'England' => 9, 'Italy' => 5],
    ],
]);
check('els de més punts', $paisNoVotes['koTeams'], [86, 4]);

echo "\n--- #kosPots: el pot arrossega els seus equips ---\n";
$pots = matchDayVoteResults([], [], $teamCompetitions, [], [], [
    'pots' => [
        'votes'  => [1, 1, 5],
        'teams'  => ['1' => [10, 5], '5' => [86], '9' => [4]],
        'points' => ['1' => 7, '5' => 2, '9' => 6],
    ],
]);
check('els 2 pots més votats', $pots['koTeams'], [10, 5, 86]);

// Un equip no s'ha de repetir si hi entra per dos camins.
$repeated = matchDayVoteResults([], [10], $teamCompetitions, [], [], [
    'paisos' => [
        'votes'  => ['Spain'],
        'teams'  => ['Spain' => [10]],
        'points' => ['Spain' => 9],
    ],
]);
check('sense duplicats', $repeated['koTeams'], [10]);

// ------------------------------------------------------ els punts per equip

// Tres equips del jugador, un per competició.
$playerTeams = [
    ['id' => 10, 'pot' => 1],
    ['id' => 4, 'pot' => 5],
    ['id' => 20, 'pot' => 9],
];
$resultsByTeam = [
    10 => ['team_id' => 10, 'points' => 3, 'competition' => 'CHL'],
    4  => ['team_id' => 4, 'points' => 1, 'competition' => 'EUL'],
    20 => ['team_id' => 20, 'points' => 4, 'competition' => 'COL'],
];

echo "\n--- sense accions: els punts del partit, tal qual ---\n";
$plain = matchDayTeamPoints($playerTeams, $resultsByTeam, []);
check('les files', array_map('rowView', $plain['rows']), [
    ['team' => 10, 'points' => 3, 'total' => 3, 'action' => null],
    ['team' => 4, 'points' => 1, 'total' => 1, 'action' => null],
    ['team' => 20, 'points' => 4, 'total' => 4, 'action' => null],
]);
check('la suma per competició', $plain['points'], ['CHL' => 3, 'EUL' => 1, 'COL' => 4]);
// La victòria val 3 a CHL i EUL i 4 a COL, i el comptador mira si en val més de 2.
check('les victòries', $plain['wins'], ['CHL' => 1, 'EUL' => 0, 'COL' => 1]);
check(
    'un equip sense resultat no compta ni deixa fila',
    matchDayTeamPoints([['id' => 99, 'pot' => 1]], [], [])['rows'],
    []
);

echo "\n--- #dobleORes ---\n";
$double = matchDayTeamPoints($playerTeams, $resultsByTeam, [
    'nothingTeams' => [10],
    'doubleTeams'  => [20],
]);
check('el més votat a zero i el segon a doble', array_map('rowView', $double['rows']), [
    ['team' => 10, 'points' => 3, 'total' => 0, 'action' => 'dobleORes/Res'],
    ['team' => 4, 'points' => 1, 'total' => 1, 'action' => null],
    ['team' => 20, 'points' => 4, 'total' => 8, 'action' => 'dobleORes/Doble'],
]);
check('i la suma ho arreplega', $double['points'], ['CHL' => 0, 'EUL' => 1, 'COL' => 8]);

echo "\n--- #guanyarOMorir: les victòries compten pel resultat del partit ---\n";
// El script fa -4 + 2 * wins, així que estos wins han de ser els del partit i
// no els dels punts que deixen les accions.
$zeroedWin = matchDayTeamPoints($playerTeams, $resultsByTeam, ['nothingTeams' => [10]]);
check('una victòria anul·lada compta com a victòria', $zeroedWin['wins']['CHL'], 1);
check('encara que els punts queden a 0', $zeroedWin['points']['CHL'], 0);

$doubledWin = matchDayTeamPoints($playerTeams, $resultsByTeam, ['doubleTeams' => [20]]);
check('una victòria doblada compta una sola vegada', $doubledWin['wins']['COL'], 1);
check('i els punts es doblen', $doubledWin['points']['COL'], 8);

$koWin = matchDayTeamPoints($playerTeams, $resultsByTeam, ['koTeams' => [10]]);
check('una victòria anul·lada pel KO també compta', $koWin['wins']['CHL'], 1);

$koPais = matchDayTeamPoints($playerTeams, $resultsByTeam, ['koTeams' => [10], 'koType' => 'kosPais']);
check('el resultat porta el tipus del boicot', $koPais['rows'][0]['action'], ['type' => 'kosPais', 'result' => 'KO']);

echo "\n--- #malDia: el llindar es mira després de #dobleORes ---\n";
check('5 punts a CHL -> OK', matchDayBadDayPoints(5, 'CHL'), ['points' => 9, 'result' => 'OK']);
check('6 punts a CHL -> KO', matchDayBadDayPoints(6, 'CHL'), ['points' => 2, 'result' => 'KO']);
check('6 punts a COL -> OK', matchDayBadDayPoints(6, 'COL'), ['points' => 12, 'result' => 'OK']);
check('7 punts a COL -> KO', matchDayBadDayPoints(7, 'COL'), ['points' => 2, 'result' => 'KO']);

// El 10 fa 3 punts; doblat, CHL passa de 3 a 6 i #malDia deixa de ser OK.
$doubled = matchDayTeamPoints($playerTeams, $resultsByTeam, ['doubleTeams' => [10]]);
check('el total doblat de CHL', $doubled['points']['CHL'], 6);
check('amb el doble, #malDia CHL és KO', matchDayBadDayPoints($doubled['points']['CHL'], 'CHL')['result'], 'KO');

// Amb dos equips de CHL (3 + 4) i el de 4 anul·lat, el total baixa de 7 a 3.
$twoChlTeams = [
    ['id' => 10, 'pot' => 1],
    ['id' => 11, 'pot' => 2],
    ['id' => 4,  'pot' => 5],
];
$twoChlResults = [
    10 => ['team_id' => 10, 'points' => 3, 'competition' => 'CHL'],
    11 => ['team_id' => 11, 'points' => 4, 'competition' => 'CHL'],
    4  => ['team_id' => 4,  'points' => 1, 'competition' => 'EUL'],
];
$zeroed = matchDayTeamPoints($twoChlTeams, $twoChlResults, ['nothingTeams' => [11]]);
check('el total anul·lat de CHL', $zeroed['points']['CHL'], 3);
check('amb l\'anul·lació, #malDia CHL és OK', matchDayBadDayPoints($zeroed['points']['CHL'], 'CHL')['result'], 'OK');

echo "\n--- #kosAmbEscuts ---\n";
$ko = matchDayTeamPoints($playerTeams, $resultsByTeam, ['koTeams' => [10]]);
check('l\'equip boicotejat no suma', rowView($ko['rows'][0]), [
    'team' => 10, 'points' => 3, 'total' => 0, 'action' => 'kosAmbEscuts/KO',
]);

$shielded = matchDayTeamPoints($playerTeams, $resultsByTeam, [
    'koTeams' => [10],
    'shields' => [10],
]);
check('amb escut se salva', rowView($shielded['rows'][0]), [
    'team' => 10, 'points' => 3, 'total' => 3, 'action' => 'kosAmbEscuts/Escut',
]);
check('i la competició no se n\'ressent', $shielded['points'], ['CHL' => 3, 'EUL' => 1, 'COL' => 4]);

echo "\n--- #segurQuePasse ---\n";
$qualified = matchDayTeamPoints($playerTeams, $resultsByTeam, [
    'classifiedTeams' => [10 => 2],
    'sureTeam'        => 10,
    'surePoints'      => 4,
]);
check('classificat: punts + marge de l\'anada + aposta', rowView($qualified['rows'][0]), [
    'team' => 10, 'points' => 3, 'total' => 9, 'action' => 'segurQuePasse/YES',
]);

$notQualified = matchDayTeamPoints($playerTeams, $resultsByTeam, [
    'classifiedTeams' => [],
    'sureTeam'        => 10,
    'surePoints'      => 4,
]);
check('no classificat: res', rowView($notQualified['rows'][0]), [
    'team' => 10, 'points' => 3, 'total' => 3, 'action' => null,
]);

echo "\n--- l'ordre de les accions sobre un mateix equip ---\n";
// Si més d'una acció hi encaixa, mana la darrera: #kosAmbEscuts per damunt de
// #dobleORes, i #segurQuePasse per damunt de #dobleORes.
$bothKo = matchDayTeamPoints($playerTeams, $resultsByTeam, [
    'doubleTeams' => [10],
    'koTeams'     => [10],
]);
check('el boicot mana sobre el doble', rowView($bothKo['rows'][0]), [
    'team' => 10, 'points' => 3, 'total' => 0, 'action' => 'kosAmbEscuts/KO',
]);

$bothSure = matchDayTeamPoints($playerTeams, $resultsByTeam, [
    'doubleTeams'     => [10],
    'classifiedTeams' => [10 => 1],
    'sureTeam'        => 10,
    'surePoints'      => 2,
]);
check('l\'aposta mana sobre el doble', rowView($bothSure['rows'][0]), [
    'team' => 10, 'points' => 3, 'total' => 6, 'action' => 'segurQuePasse/YES',
]);

// ------------------------------------------------- el llindar del #socElMillor

echo "\n--- #socElMillor: el millor es decidix després de #dobleORes ---\n";
check('4 punts i màxim 4 -> OK', matchDayBestActionPoints(4, 4, 'CHL'), ['points' => 3, 'result' => 'OK']);
check('3 punts i màxim 4 -> KO', matchDayBestActionPoints(3, 4, 'CHL'), ['points' => -3, 'result' => 'KO']);
check('5 punts i màxim 5 a COL -> OK', matchDayBestActionPoints(5, 5, 'COL'), ['points' => 4, 'result' => 'OK']);
check('4 punts i màxim 5 a COL -> KO', matchDayBestActionPoints(4, 5, 'COL'), ['points' => -4, 'result' => 'KO']);

// A fa 7 punts de CHL, però #dobleORes li n'anul·la 4 i queda amb 3. B en fa 4.
// Sense #dobleORes el millor seria A; amb l'anul·lació el millor és B.
$zeroedA = matchDayTeamPoints($twoChlTeams, $twoChlResults, ['nothingTeams' => [11]]);
$plainB  = matchDayTeamPoints(
    [['id' => 12, 'pot' => 3]],
    [12 => ['team_id' => 12, 'points' => 4, 'competition' => 'CHL']],
    []
);
$best = matchDayBestPoints(
    [1 => ['CHL'], 2 => ['CHL']],
    [
        1 => ['CHL' => $zeroedA['points']['CHL']],
        2 => ['CHL' => $plainB['points']['CHL']],
    ]
);
check('el màxim és el 4 de B', $best['CHL'], 4);
check('A, amb 3, queda KO', matchDayBestActionPoints($zeroedA['points']['CHL'], $best['CHL'], 'CHL')['result'], 'KO');
check('B, amb 4, queda OK', matchDayBestActionPoints($plainB['points']['CHL'], $best['CHL'], 'CHL')['result'], 'OK');

// I amb un equip doblat, A passa de 3 a 6 i supera B (4): el millor canvia.
$doubledA = matchDayTeamPoints($playerTeams, $resultsByTeam, ['doubleTeams' => [10]]);
$bestDoubled = matchDayBestPoints(
    [1 => ['CHL'], 2 => ['CHL']],
    [
        1 => ['CHL' => $doubledA['points']['CHL']],
        2 => ['CHL' => $plainB['points']['CHL']],
    ]
);
check('el màxim ara és el 6 de A', $bestDoubled['CHL'], 6);
check('A, amb 6, queda OK', matchDayBestActionPoints($doubledA['points']['CHL'], $bestDoubled['CHL'], 'CHL')['result'], 'OK');
check('B, amb 4, queda KO', matchDayBestActionPoints($plainB['points']['CHL'], $bestDoubled['CHL'], 'CHL')['result'], 'KO');

echo "\n--- #socElMillor: el màxim de cada competició ---\n";
$activated = [
    1 => ['CHL'],
    2 => ['CHL', 'EUL'],
    3 => ['COL'],
];
$points = [
    1 => ['CHL' => 5, 'EUL' => 0, 'COL' => 0],
    2 => ['CHL' => 7, 'EUL' => 3, 'COL' => 0],
    3 => ['CHL' => 0, 'EUL' => 0, 'COL' => 2],
];

$best = matchDayBestPoints($activated, $points);
check('CHL el mana el 7 del jugador 2', $best['CHL'], 7);
check('EUL el mana el 3', $best['EUL'], 3);
check('COL el mana el 2', $best['COL'], 2);

$bestOne = matchDayBestPoints([1 => ['CHL']], [1 => ['CHL' => 0]]);
check('si només l\'activa un, el llindar és el seu', $bestOne['CHL'], 0);
check('les competicions sense ningú no tenen llindar', [$bestOne['EUL'], $bestOne['COL']], [null, null]);

$bestNone = matchDayBestPoints([], []);
check('sense activacions, cap llindar', $bestNone, ['CHL' => null, 'EUL' => null, 'COL' => null]);

$bestUnknown = matchDayBestPoints([1 => ['XXX', 'CHL']], [1 => ['CHL' => 4]]);
check('una competició que no és del joc s\'ignora', $bestUnknown, ['CHL' => 4, 'EUL' => null, 'COL' => null]);

// --------------------------------------------------------------- #millorFinal

echo "\n--- #millorFinal: només a la jornada de les finals ---\n";
check('la final és la jornada 18', resultsIsFinalMatchDay(18), true);
check('les semifinals no', resultsIsFinalMatchDay(17), false);

// Tres finals: CHL 2-0 (guanya el 10), EUL 1-1 (empaten el 4 i el 5) i COL 0-2
// (guanya el 86).
$finalResults = [
    ['team_id' => 10, 'competition' => 'CHL', 'points' => 3],
    ['team_id' => 20, 'competition' => 'CHL', 'points' => 0],
    ['team_id' => 4,  'competition' => 'EUL', 'points' => 1],
    ['team_id' => 5,  'competition' => 'EUL', 'points' => 1],
    ['team_id' => 86, 'competition' => 'COL', 'points' => 3],
    ['team_id' => 82, 'competition' => 'COL', 'points' => 0],
];

echo "\n--- el rànquing de vots mana ---\n";
// CHL la més votada, EUL segona, COL la menys votada.
$effects = matchDayBestFinalEffects(['CHL' => 9, 'EUL' => 5, 'COL' => 2], $finalResults);

check('la final més votada: +6 al guanyador', $effects[10], ['points' => 6, 'result' => 'guanyador']);
check('i res al seu perdedor', $effects[20] ?? null, null);
check('la segona: +1 a cada finalista de l\'empat', [
    $effects[4] ?? null,
    $effects[5] ?? null,
], [
    ['points' => 1, 'result' => 'finalista'],
    ['points' => 1, 'result' => 'finalista'],
]);
check('la pitjor: -2 al perdedor', $effects[82], ['points' => -2, 'result' => 'perdedor']);
check('i el guanyador de la pitjor no cobra res', $effects[86] ?? null, null);
check('sense vots no reparteix res', matchDayBestFinalEffects([], $finalResults), []);

echo "\n--- els empats a la final ---\n";
// EUL és la més votada i va acabar en empat: +3 a cada finalista.
$draw = matchDayBestFinalEffects(['EUL' => 5, 'CHL' => 0, 'COL' => 0], $finalResults);
check('empat a la més votada: +3 a cada finalista', [$draw[4], $draw[5]], [
    ['points' => 3, 'result' => 'finalista'],
    ['points' => 3, 'result' => 'finalista'],
]);

echo "\n--- una final que encara no s'ha jugat ---\n";
// CHL és la més votada però no té resultat: ocupa el seu lloc al rànquing i no
// reparteix res; EUL (segona) no passa a cobrar el premi de la primera.
$partial = matchDayBestFinalEffects(['CHL' => 9, 'EUL' => 5, 'COL' => 2], [
    ['team_id' => 4,  'competition' => 'EUL', 'points' => 1],
    ['team_id' => 5,  'competition' => 'EUL', 'points' => 1],
    ['team_id' => 86, 'competition' => 'COL', 'points' => 3],
    ['team_id' => 82, 'competition' => 'COL', 'points' => 0],
]);
check('no reparteix però no mou el rànquing', [
    $partial[4]['points'] ?? null,
    $partial[82]['points'] ?? null,
], [1, -2]);

echo "\n--- un finalista amb el rival exclòs ---\n";
// Si l'altre finalista no és al joc (estat exclòs), només es desa un equip i els
// seus punts ja diuen si va guanyar, empatar o perdre.
$oneRow = matchDayBestFinalEffects(['CHL' => 9, 'EUL' => 0, 'COL' => 0], [
    ['team_id' => 10, 'competition' => 'CHL', 'points' => 3],
]);
check('amb 3 punts va guanyar', $oneRow[10], ['points' => 6, 'result' => 'guanyador']);

$oneRowLoser = matchDayBestFinalEffects(['CHL' => 9, 'EUL' => 5, 'COL' => 0], [
    ['team_id' => 20, 'competition' => 'COL', 'points' => 0],
]);
check('amb 0 punts va perdre, i la pitjor final el castiga', $oneRowLoser[20], ['points' => -2, 'result' => 'perdedor']);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
