<?php
// Prova de les peces de config/publishActions.php: els recomptes de vots i el
// text que publishActions.php envia al grup. Sense BD ni Telegram.
// S'executa des de l'arrel del projecte: php tests/publishActionsTest.php

require_once(__DIR__ . '/../config/publishActions.php');

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

// ---------- textos petits ----------

echo "--- singulars i plurals ---\n";
check('1 vot', actionsVotesText(1), '1 vot');
check('0 vots', actionsVotesText(0), '0 vots');
check('4 vots', actionsVotesText(4), '4 vots');
check('1 punt', actionsPointsText(1), '1 punt');
check('4 punts', actionsPointsText(4), '4 punts');

echo "\n--- noms units ---\n";
check('cap', actionsJoinNames([]), '');
check('un', actionsJoinNames(['Arsenal']), 'Arsenal');
check('dos', actionsJoinNames(['Arsenal', 'Roma']), 'Arsenal i Roma');
check('tres', actionsJoinNames(['Arsenal', 'Roma', 'Ajax']), 'Arsenal, Roma i Ajax');

echo "\n--- etiquetes ---\n";
check('malDia', actionLabel('badDay'), '#malDia');
check('kosAmbEscuts', actionLabel('kosAndShields'), '#kosAmbEscuts');
check('un tipus desconegut es queda tal qual', actionLabel('foo'), 'foo');

// ---------- recomptes ----------

echo "\n--- vots per equip ---\n";
check('de més a menys', actionsVoteTotals([10, 4, 10, 86, 10]), [10 => 3, 4 => 1, 86 => 1]);
check('sense vots', actionsVoteTotals([]), []);
check(
    'els empatats mantenen l\'ordre en què s\'han votat',
    array_keys(actionsVoteTotals([86, 4])),
    [86, 4]
);

echo "\n--- els més votats (#kosAmbEscuts) ---\n";
$kosTotals = actionsVoteTotals([10, 10, 4, 86, 82, 47, 57, 86]);
check('el recompte', $kosTotals, [10 => 2, 86 => 2, 4 => 1, 82 => 1, 47 => 1, 57 => 1]);
check('els 4 primers', actionsMostVotedTeams($kosTotals, 4), [10, 86, 4, 82]);
check('amb menys vots que llocs', actionsMostVotedTeams(actionsVoteTotals([4]), 4), [4]);
check('sense vots no hi ha ningú', actionsMostVotedTeams([], 4), []);

echo "\n--- resultat de #dobleORes ---\n";
$totals = actionsVoteTotals([10, 10, 4, 86]);
$competitions = [10 => 'CHL', 4 => 'CHL', 86 => 'EUL'];
check('més votat i segon de cada competició', actionsDoubleOrNothingResult($totals, $competitions), [
    'CHL' => ['top' => [10], 'second' => [4]],
    'EUL' => ['top' => [86], 'second' => []],
    'COL' => ['top' => [], 'second' => []],
]);

echo "\n--- #dobleORes amb empat a dalt: mana la classificació ---\n";
$totals = actionsVoteTotals([10, 4, 10, 4, 86]);
$competitions = [10 => 'CHL', 4 => 'CHL', 86 => 'EUL'];
// El 4 va millor classificat que el 10: és el més votat, i el 10 el segon.
check('el ranking decidix qui queda a 0 i qui dobla', actionsDoubleOrNothingResult($totals, $competitions, [4 => 1, 10 => 2]), [
    'CHL' => ['top' => [4], 'second' => [10]],
    'EUL' => ['top' => [86], 'second' => []],
    'COL' => ['top' => [], 'second' => []],
]);
// Sense classificació, l'empat manté l'ordre en què s'ha votat: el 10 primer.
check('sense rankings, el primer votat va davant', actionsDoubleOrNothingResult($totals, $competitions), [
    'CHL' => ['top' => [10], 'second' => [4]],
    'EUL' => ['top' => [86], 'second' => []],
    'COL' => ['top' => [], 'second' => []],
]);

echo "\n--- #dobleORes: el segon pot ser un equip sense vots ---\n";
$totals = actionsVoteTotals([10, 10]);
$competitions = [10 => 'CHL', 86 => 'CHL', 4 => 'CHL', 20 => 'EUL', 21 => 'EUL'];
check('el millor classificat sense vots és el segon', actionsDoubleOrNothingResult($totals, $competitions, [4 => 1, 86 => 2, 10 => 3, 20 => 1, 21 => 2]), [
    'CHL' => ['top' => [10], 'second' => [4]],
    // EUL no té cap vot: mana la classificació, 20 a 0 i 21 dobla.
    'EUL' => ['top' => [20], 'second' => [21]],
    'COL' => ['top' => [], 'second' => []],
]);
check('sense classificació només compten els votats', actionsDoubleOrNothingResult($totals, $competitions), [
    'CHL' => ['top' => [10], 'second' => []],
    'EUL' => ['top' => [], 'second' => []],
    'COL' => ['top' => [], 'second' => []],
]);

echo "\n--- sense cap vot a l'acció no es reparteix res ---\n";
check('ni amb la classificació carregada', actionsDoubleOrNothingResult([], [10 => 'CHL', 4 => 'CHL'], [10 => 1, 4 => 2]), [
    'CHL' => ['top' => [], 'second' => []],
    'EUL' => ['top' => [], 'second' => []],
    'COL' => ['top' => [], 'second' => []],
]);

echo "\n--- #kosAmbEscuts: empat al tall, mana la classificació ---\n";
$kosTie = actionsVoteTotals([10, 4, 86, 82, 10, 4, 86, 82, 39]);
// Quatre equips empatats a 2 vots; el 82 és el millor classificat i el 86 el
// pitjor, així que amb 3 llocs el 86 queda fora.
check(
    'el pitjor classificat dels empatats no entra',
    actionsMostVotedTeams($kosTie, 3, [82 => 1, 10 => 2, 4 => 3, 86 => 4, 39 => 5]),
    [82, 10, 4]
);

echo "\n--- #dobleORes no compta equips de competicions que no coneix ---\n";
check(
    'un equip sense competició no apareix',
    actionsDoubleOrNothingResult(actionsVoteTotals([99]), []),
    ['CHL' => ['top' => [], 'second' => []], 'EUL' => ['top' => [], 'second' => []], 'COL' => ['top' => [], 'second' => []]]
);

echo "\n--- rànquing de #millorFinal ---\n";
check('de més a menys', actionsBestFinalRanking(['CHL' => 1, 'EUL' => 7, 'COL' => 4]), ['EUL' => 7, 'COL' => 4, 'CHL' => 1]);
check('sense vots, les tres a zero', actionsBestFinalRanking([]), ['CHL' => 0, 'EUL' => 0, 'COL' => 0]);
check(
    'els empats mantenen l\'ordre CHL, EUL, COL',
    array_keys(actionsBestFinalRanking(['CHL' => 2, 'EUL' => 2, 'COL' => 2])),
    ['CHL', 'EUL', 'COL']
);

echo "\n--- línies de vots, agrupades ---\n";
check(
    'un vot per equip i jugador, amb el compte',
    actionsVoteLines([
        ['player' => 'Marta', 'team' => 'Arsenal'],
        ['player' => 'Marta', 'team' => 'Arsenal'],
        ['player' => 'Ivan', 'team' => 'Roma'],
    ], 'a'),
    ['- Marta: 2 vots a Arsenal', '- Ivan: 1 vot a Roma']
);
check(
    'amb l\'enllaç de KO',
    actionsVoteLines([['player' => 'Ivan', 'team' => 'Arsenal']], 'de KO a'),
    ['- Ivan: 1 vot de KO a Arsenal']
);

// ---------- el missatge sencer ----------

echo "\n--- missatge complet ---\n";
$announcement = [
    'badDay'          => [['player' => 'Ivan', 'values' => ['CHL', 'EUL']]],
    'iAmTheBest'      => [['player' => 'Marta', 'values' => ['EUL']]],
    'winOrDie'        => [],
    'doubleOrNothing' => [
        'votes' => [
            ['player' => 'Marta', 'team' => 'Arsenal'],
            ['player' => 'Marta', 'team' => 'Arsenal'],
            ['player' => 'Ivan', 'team' => 'Roma'],
        ],
        'totals' => ['Arsenal' => 2, 'Roma' => 1],
        'result' => [
            'CHL' => ['top' => ['Arsenal'], 'second' => ['Roma']],
            'EUL' => ['top' => [], 'second' => []],
            'COL' => ['top' => [], 'second' => []],
        ],
    ],
    'kosAndShields' => [
        'kos'     => [['player' => 'Ivan', 'team' => 'Arsenal']],
        'shields' => [['player' => 'Ivan', 'team' => 'Bayern']],
        'boicot'  => [
            ['team' => 'Arsenal', 'shieldedBy' => []],
            ['team' => 'Bayern', 'shieldedBy' => ['Ivan']],
        ],
    ],
    'bestFinal' => [
        'votes'   => [['player' => 'Ivan', 'competition' => 'CHL', 'votes' => 4]],
        'ranking' => ['CHL' => 7, 'EUL' => 4, 'COL' => 1],
    ],
    'sureToBeQualified' => [['player' => 'Marta', 'team' => 'Barça', 'points' => 1]],
];

$expected = "Accions de la jornada 2:\n"
    . "\n#malDia\n- Ivan: CHL, EUL"
    . "\n\n#socElMillor\n- Marta: EUL"
    . "\n\n#dobleORes"
    . "\n- Marta: 2 vots a Arsenal"
    . "\n- Ivan: 1 vot a Roma"
    . "\n\nEquips més votats (el més votat no sume cap punt i el segon sume doble):"
    . ""
    . "\n- CHL: Arsenal (2 vots, sumarà 0 punts); Roma (1 vot, sumarà doble)"
    . "\n- EUL: cap vot"
    . "\n- COL: cap vot"
    . "\n\n#kosAmbEscuts"
    . "\n- Ivan: 1 vot de KO a Arsenal"
    . "\n- Ivan: 1 escut a Bayern"
    . "\n\nEls 2 equips més votats (no sumen punts):"
    . "\n- Arsenal"
    . "\n- Bayern (amb escut de Ivan, se salva)"
    . "\n\n#millorFinal"
    . "\n- Ivan: 4 vots a CHL"
    . "\n\nRànquing de finals:"
    . "\n- CHL: 7 vots (+6 al guanyador)"
    . "\n- EUL: 4 vots (+2 al guanyador)"
    . "\n- COL: 1 vot (-2 al perdedor)"
    . "\n\n#segurQuePasse"
    . "\n- Marta: Barça (1 punt en joc)";

check('seccions en l\'ordre de /normes i resums calculats', actionsPublishText(2, $announcement), $expected);

echo "\n--- només una acció: les altres seccions no surten ---\n";
check(
    'sense capçaleres buides',
    actionsPublishText(7, ['badDay' => [['player' => 'Ivan', 'values' => ['COL']]]]),
    "Accions de la jornada 7:\n\n#malDia\n- Ivan: COL"
);

echo "\n--- el resum de #dobleORes amb els vots per equip ---\n";
check(
    'top i segon amb el recompte',
    actionsDoubleOrNothingText(
        [
            'CHL' => ['top' => ['Arsenal'], 'second' => ['Roma']],
            'EUL' => ['top' => [], 'second' => []],
        ],
        ['Arsenal' => 3, 'Roma' => 2]
    ),
    [
        'Equips més votats (el més votat no sume cap punt i el segon sume doble):',
        '- CHL: Arsenal (3 vots, sumarà 0 punts); Roma (2 vots, sumarà doble)',
        '- EUL: cap vot',
    ]
);
check(
    'sense recompte, el text és el d\'abans',
    actionsDoubleOrNothingText(['CHL' => ['top' => ['Roma'], 'second' => []]]),
    [
        'Equips més votats (el més votat no sume cap punt i el segon sume doble):',
        '- CHL: Roma (sumarà 0 punts); cap segon equip amb vots',
    ]
);

echo "\n--- una competició sense segon equip votat ---\n";
check(
    'ho diu en comptes d\'inventar-se un equip',
    actionsPublishText(3, [
        'doubleOrNothing' => [
            'votes'   => [['player' => 'Ivan', 'team' => 'Roma']],
            'totals'  => ['Roma' => 1],
            'result'  => ['CHL' => ['top' => ['Roma'], 'second' => []], 'EUL' => ['top' => [], 'second' => []], 'COL' => ['top' => [], 'second' => []]],
        ],
    ]),
    "Accions de la jornada 3:\n\n#dobleORes\n- Ivan: 1 vot a Roma\n\nEquips més votats (el més votat no sume cap punt i el segon sume doble):\n- CHL: Roma (1 vot, sumarà 0 punts); cap segon equip amb vots\n- EUL: cap vot\n- COL: cap vot"
);

echo "\n--- sense cap acció ---\n";
check('no hi ha missatge', actionsPublishText(4, []), '');

echo "\n--- una fila d'#dobleORes oberta sense vots no envia res ---\n";
check(
    'ni capçalera',
    actionsPublishText(4, actionsAnnouncement(
        [['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 3, 'teams' => []])]],
        [],
        [7 => 'Ivan'],
        [10 => 'Arsenal'],
        [10 => 'CHL'],
        1
    )),
    ''
);

// ---------- de les files de la BD al missatge ----------

$playerNames      = [7 => 'Ivan', 8 => 'Marta'];
$teamNames        = [10 => 'Arsenal', 4 => 'Bayern', 86 => 'Roma', 82 => 'Ajax', 39 => 'Betis'];
$teamCompetitions = [10 => 'CHL', 4 => 'CHL', 86 => 'EUL', 82 => 'EUL', 39 => 'COL'];
$allowedVotes     = [7 => 4, 8 => 2];

// Un joc amb tots els tipus d'acció, amb el JSON que desa cada acció del bot.
$rows = [
    ['player_id' => 7, 'type' => 'badDay', 'data' => json_encode(['CHL', 'EUL'])],
    ['player_id' => 8, 'type' => 'iAmTheBest', 'data' => json_encode(['EUL'])],
    ['player_id' => 7, 'type' => 'winOrDie', 'data' => json_encode(['COL'])],
    ['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 4, 'teams' => [10, 10, 86]])],
    ['player_id' => 8, 'type' => 'kosAndShields', 'data' => json_encode(['max' => 2, 'kos' => [10, 4], 'shields' => [4]])],
    ['player_id' => 7, 'type' => 'bestFinal', 'data' => json_encode(['competition' => 'CHL', 'points' => 4])],
    ['player_id' => 8, 'type' => 'sureToBeQualified', 'data' => json_encode(['team' => 10, 'points' => 2])],
];

echo "--- les files de la BD es converteixen en l'anunci ---\n";
$expectedFromRows = "Accions de la jornada 5:\n"
    . "\n#malDia\n- Ivan: CHL, EUL"
    . "\n\n#socElMillor\n- Marta: EUL"
    . "\n\n#guanyarOMorir\n- Ivan: COL"
    . "\n\n#dobleORes"
    . "\n- Ivan: 2 vots a Arsenal"
    . "\n- Ivan: 1 vot a Roma"
    . "\n\nEquips més votats (el més votat no sume cap punt i el segon sume doble):"
    . ""
    . "\n- CHL: Arsenal (2 vots, sumarà 0 punts); cap segon equip amb vots"
    . "\n- EUL: Roma (1 vot, sumarà 0 punts); cap segon equip amb vots"
    . "\n- COL: cap vot"
    . "\n\n#kosAmbEscuts"
    . "\n- Marta: 1 vot de KO a Arsenal"
    . "\n- Marta: 1 vot de KO a Bayern"
    . "\n- Marta: 1 escut a Bayern"
    . "\n\nEls 2 equips més votats (no sumen punts):"
    . "\n- Arsenal"
    . "\n- Bayern (amb escut de Marta, se salva)"
    . "\n\n#millorFinal"
    . "\n- Ivan: 4 vots a CHL"
    . "\n\nRànquing de finals:"
    . "\n- CHL: 4 vots (+6 al guanyador)"
    . "\n- EUL: 0 vots (+2 al guanyador)"
    . "\n- COL: 0 vots (-2 al perdedor)"
    . "\n\n#segurQuePasse"
    . "\n- Marta: Arsenal (2 punts en joc)";

check(
    'el missatge surt dels vots de la jornada',
    actionsPublishText(5, actionsAnnouncement($rows, $allowedVotes, $playerNames, $teamNames, $teamCompetitions, 1)),
    $expectedFromRows
);

echo "\n--- l'anunci desempata amb la classificació d'equips ---\n";
$rowsTie = [
    ['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 2, 'teams' => [10, 4]])],
    ['player_id' => 8, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 2, 'teams' => [10, 4]])],
];
$announcementTie = actionsAnnouncement(
    $rowsTie,
    $allowedVotes,
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1,
    [4 => 1, 10 => 2]
);
check('el millor classificat queda a 0 i l\'altre dobla', $announcementTie['doubleOrNothing']['result']['CHL'], [
    'top'    => ['Bayern'],
    'second' => ['Arsenal'],
]);

echo "\n--- l'anunci pot donar el doble a un equip sense vots ---\n";
$rowsZero = [
    ['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 2, 'teams' => [10]])],
];
$announcementZero = actionsAnnouncement(
    $rowsZero,
    $allowedVotes,
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1,
    [4 => 1, 10 => 2]
);
check('el segon és el millor classificat sense vots', $announcementZero['doubleOrNothing']['result']['CHL'], [
    'top'    => ['Arsenal'],
    'second' => ['Bayern'],
]);
check('i compta 0 vots', $announcementZero['doubleOrNothing']['totals']['Bayern'] ?? null, 0);
check(
    'el text ho diu',
    actionsPublishText(6, $announcementZero),
    "Accions de la jornada 6:\n\n#dobleORes\n- Ivan: 1 vot a Arsenal\n\nEquips més votats (el més votat no sume cap punt i el segon sume doble):\n- CHL: Arsenal (1 vot, sumarà 0 punts); Bayern (0 vots, sumarà doble)\n- EUL: cap vot\n- COL: cap vot"
);

echo "\n--- una competició sense vots també reparteix per classificació ---\n";
$rowsNoVotes = [
    ['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 2, 'teams' => [86]])],
    ['player_id' => 8, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 2, 'teams' => [39]])],
];
$announcementNoVotes = actionsAnnouncement(
    $rowsNoVotes,
    $allowedVotes,
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1,
    [10 => 1, 4 => 2, 86 => 1, 82 => 2, 39 => 1]
);
check('CHL, sense vots, decideix la classificació', $announcementNoVotes['doubleOrNothing']['result']['CHL'], [
    'top'    => ['Arsenal'],
    'second' => ['Bayern'],
]);
check('i els dos compten 0 vots', [
    $announcementNoVotes['doubleOrNothing']['totals']['Arsenal'],
    $announcementNoVotes['doubleOrNothing']['totals']['Bayern'],
], [0, 0]);
check(
    'el text',
    actionsPublishText(7, $announcementNoVotes),
    "Accions de la jornada 7:\n\n#dobleORes\n- Ivan: 1 vot a Roma\n- Marta: 1 vot a Betis\n\nEquips més votats (el més votat no sume cap punt i el segon sume doble):\n- CHL: Arsenal (0 vots, sumarà 0 punts); Bayern (0 vots, sumarà doble)\n- EUL: Roma (1 vot, sumarà 0 punts); Ajax (0 vots, sumarà doble)\n- COL: Betis (1 vot, sumarà 0 punts); cap segon equip amb vots"
);

echo "\n--- files velles i casos raros ---\n";
$legacy = actionsAnnouncement(
    [
        ['player_id' => 7, 'type' => 'bestFinal', 'data' => json_encode(['CHL'])],
        ['player_id' => 7, 'type' => 'sureToBeQualified', 'data' => json_encode([10])],
        ['player_id' => 99, 'type' => 'badDay', 'data' => json_encode(['CHL'])],
        ['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['max' => 1])],
        ['player_id' => 7, 'type' => 'convidat', 'data' => json_encode(['CHL'])],
        ['player_id' => 7, 'type' => 'winOrDie', 'data' => 'això no és JSON'],
    ],
    [7 => 3],
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1
);

check(
    'bestFinal vell: els vots surten de la regla de posició',
    $legacy['bestFinal'],
    ['votes' => [['player' => 'Ivan', 'competition' => 'CHL', 'votes' => 3]], 'ranking' => ['CHL' => 3, 'EUL' => 0, 'COL' => 0]]
);
check(
    'segurQuePasse vell: l\'equip és el primer element del JSON',
    $legacy['sureToBeQualified'],
    [['player' => 'Ivan', 'team' => 'Arsenal', 'points' => 3]]
);
check('jugador sense nom: s\'identifica amb #id', $legacy['badDay'], [['player' => '#99', 'values' => ['CHL']]]);
check('sense vots no hi ha res a dir', $legacy['doubleOrNothing'], ['votes' => [], 'result' => [], 'totals' => []]);
check('un tipus desconegut s\'ignora', array_key_exists('convidat', $legacy), false);
check('un JSON trencat no peta', $legacy['winOrDie'], []);

$minFallback = actionsAnnouncement(
    [['player_id' => 8, 'type' => 'bestFinal', 'data' => json_encode(['CHL'])]],
    [],
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1
);
check(
    'sense classificació, el mínim de la regla de posició',
    $minFallback['bestFinal']['votes'],
    [['player' => 'Marta', 'competition' => 'CHL', 'votes' => 1]]
);

$unknownTeam = actionsAnnouncement(
    [['player_id' => 7, 'type' => 'doubleOrNothing', 'data' => json_encode(['teams' => [123]])]],
    [],
    $playerNames,
    $teamNames,
    [],
    1
);
check('equip desconegut: s\'identifica amb #id', $unknownTeam['doubleOrNothing']['votes'], [['player' => 'Ivan', 'team' => '#123']]);

echo "\n--- #kosAmbEscuts: només entren els 4 més votats ---\n";
$manyKos = actionsAnnouncement(
    [['player_id' => 7, 'type' => 'kosAndShields', 'data' => json_encode(['kos' => [10, 4, 86, 82, 39]])]],
    [],
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1
);
check('el boicot són els 4 primers', array_column($manyKos['kosAndShields']['boicot'], 'team'), ['Arsenal', 'Bayern', 'Roma', 'Ajax']);
check(
    'i el text ho diu',
    actionsPublishText(3, $manyKos),
    "Accions de la jornada 3:\n\n#kosAmbEscuts"
    . "\n- Ivan: 1 vot de KO a Arsenal"
    . "\n- Ivan: 1 vot de KO a Bayern"
    . "\n- Ivan: 1 vot de KO a Roma"
    . "\n- Ivan: 1 vot de KO a Ajax"
    . "\n- Ivan: 1 vot de KO a Betis"
    . "\n\nEls 4 equips més votats (no sumen punts):"
    . "\n- Arsenal\n- Bayern\n- Roma\n- Ajax"
);

echo "\n--- un #millorFinal amb una competició que no toca ---\n";
$rareFinal = actionsAnnouncement(
    [['player_id' => 7, 'type' => 'bestFinal', 'data' => json_encode(['competition' => 'XXX', 'points' => 4])]],
    [],
    $playerNames,
    $teamNames,
    $teamCompetitions,
    1
);
check('el vot surt igualment', $rareFinal['bestFinal']['votes'], [['player' => 'Ivan', 'competition' => 'XXX', 'votes' => 4]]);
check('però no entra al rànquing', $rareFinal['bestFinal']['ranking'], []);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
