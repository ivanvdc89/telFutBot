<?php

/**
 * El menú /accions ha de sortir dels interruptors, no d'una llista fixa.
 *
 * Cobreix els quatre estats de la temporada, perquè tancar els canvis ha de
 * treure /substitució del menú i obrir les accions ha de fer-hi aparèixer les
 * tres accions de competició i la votació que toca (només una: #dobleORes o un
 * boicot de KO). El boicot de KO ix amb dos botons —vots i escuts— i tots dos
 * apunten al mateix boicot. Un menú que ofereix coses que ja no es poden fer és
 * pitjor que no tenir menú.
 *
 * Sense base de dades: actionsMenuRows() només mira dos booleans i una cadena.
 *
 *   php tests/actionsMenuTest.php
 */

require_once(__DIR__ . '/../config/actions.php');

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

$SUBSTITUCIONS = ['/substitució'];
$ACCIONS       = ['/malDia', '/socElMillor', '/guanyarOMorir'];
$DOBLE         = ['/dobleORes'];

echo "--- abans del termini: només canvis ---\n";
check('els canvis hi són i les accions no', actionsMenuRows(true, false, 'kosPais'), [$SUBSTITUCIONS]);

echo "\n--- el termini tancat i les accions encara no obertes ---\n";
// És l'estona d'aplicar i publicar els canvis: no hi ha res a oferir.
check('el menú queda buit', actionsMenuRows(false, false, 'kosPais'), []);

echo "\n--- després d'obrir les accions: la votació de la jornada ---\n";
check(
    'les tres accions i els dos botons del boicot, i els canvis no',
    actionsMenuRows(false, true, 'kosPais'),
    [$ACCIONS, ['/kosPais', '/kosPais escut']]
);
check('cada boicot té els seus dos botons', actionsMenuRows(false, true, 'kosAndShields'), [$ACCIONS, ['/kos', '/kos escut']]);
check('i el de pots', actionsMenuRows(false, true, 'kosPots'), [$ACCIONS, ['/kosPots', '/kosPots escut']]);

echo "\n--- cada boicot té el seu comandament d'escuts ---\n";
check('per equips', kosShieldCommand('kosAndShields'), '/kos escut');
check('per països', kosShieldCommand('kosPais'), '/kosPais escut');
check('per pots', kosShieldCommand('kosPots'), '/kosPots escut');
check('i sense boicot no n\'hi ha', kosShieldCommand('doubleOrNothing'), '');

echo "\n--- la jornada de #dobleORes ---\n";
check(
    'surt el doble o res i cap boicot',
    actionsMenuRows(false, true, 'doubleOrNothing'),
    [$ACCIONS, $DOBLE]
);

echo "\n--- mai dues votacions al menú ---\n";
check('amb #dobleORes no hi ha cap botó de KO', actionsMenuRows(false, true, 'doubleOrNothing'), [$ACCIONS, $DOBLE]);
check('amb un boicot no hi ha #dobleORes', actionsMenuRows(false, true, 'kosPots'), [$ACCIONS, ['/kosPots', '/kosPots escut']]);

echo "\n--- una jornada sense votació ---\n";
check('només les accions de competició', actionsMenuRows(false, true, ''), [$ACCIONS]);

echo "\n--- les dues coses obertes alhora (no hauria de passar) ---\n";
check(
    'es mostren les tres files',
    actionsMenuRows(true, true, 'kosPais'),
    [$SUBSTITUCIONS, $ACCIONS, ['/kosPais', '/kosPais escut']]
);

echo "\n--- les accions surten amb el nom exacte dels comandaments ---\n";
// Si un botó no coincidís amb el comandament del router, el jugador el polsaria
// i no passaria res.
$files = actionsMenuRows(false, true, 'kosPais');
check('dues files: les accions i la votació', count($files), 2);
check('les tres accions de competició', $files[0], ['/malDia', '/socElMillor', '/guanyarOMorir']);
check('i els dos botons del boicot', $files[1], ['/kosPais', '/kosPais escut']);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
