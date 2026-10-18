<?php
// Prova de les etiquetes de pot (config/pots.php). Les comandes del bot fan
// servir CHL_1...COL_4 en lloc dels pots globals 1-12, però la base de dades
// continua guardant el número global.
// S'executa des de l'arrel del projecte: php tests/potsTest.php

require_once(__DIR__ . '/../config/pots.php');

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

echo "--- les etiquetes, en ordre ---\n";
check('les 12 etiquetes', potLabels(), [
    'CHL_1', 'CHL_2', 'CHL_3', 'CHL_4',
    'EUL_1', 'EUL_2', 'EUL_3', 'EUL_4',
    'COL_1', 'COL_2', 'COL_3', 'COL_4',
]);
check('la primera és CHL_1', potLabel(1), 'CHL_1');
check('l\'última de la Champions', potLabel(4), 'CHL_4');
check('el pot global 5 és EUL_1', potLabel(5), 'EUL_1');
check('el pot global 8 és EUL_4', potLabel(8), 'EUL_4');
check('el pot global 9 és COL_1', potLabel(9), 'COL_1');
check('el pot global 12 és COL_4', potLabel(12), 'COL_4');

echo "\n--- de l'etiqueta al pot global ---\n";
foreach (POTS as $globalPot) {
    check('anada i tornada del pot ' . $globalPot, potFromLabel(potLabel($globalPot)), $globalPot);
}
check('EUL_1 és el 5', potFromLabel('EUL_1'), 5);
check('COL_4 és el 12', potFromLabel('COL_4'), 12);
check('les minúscules també valen', potFromLabel('eul_2'), 6);

echo "\n--- els números globals antics continuen valent ---\n";
// Els teclats que ja tenen els jugadors a Telegram porten /dobleORes pot 5,
// /kos ko_pot 12... i no s'han de quedar sense resposta.
check('pot 5', potFromLabel('5'), 5);
check('pot 12', potFromLabel('12'), 12);
check('pot 01', potFromLabel('01'), 1);

echo "\n--- el que no és cap pot ---\n";
foreach ([
    'CHL_0'  => 'número zero',
    'CHL_5'  => 'fora de la competició',
    'EUL_9'  => 'fora de la competició',
    'ESP_1'  => 'competició desconeguda',
    '0'      => 'global zero',
    '13'     => 'global fora de rang',
    '3.5'    => 'decimal',
    '1e1'    => 'notació científica',
    ''       => 'buit',
    'abc'    => 'text',
] as $label => $case) {
    check($case, potFromLabel($label), null);
}

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
