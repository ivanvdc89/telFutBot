<?php

/**
 * El menú /accions ha de sortir dels interruptors, no d'una llista fixa.
 *
 * Cobreix els quatre estats de la temporada, perquè tancar els canvis ha de
 * treure /substitució del menú i obrir les accions ha de fer-hi aparèixer les
 * tres. Un menú que ofereix coses que ja no es poden fer és pitjor que no tenir
 * menú.
 *
 * Sense base de dades: actionsMenuRows() només mira dos booleans.
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

echo "--- abans del termini: només canvis ---\n";
check('els canvis hi són i les accions no', actionsMenuRows(true, false), [$SUBSTITUCIONS]);

echo "\n--- el termini tancat i les accions encara no obertes ---\n";
// És l'estona d'aplicar i publicar els canvis: no hi ha res a oferir.
check('el menú queda buit', actionsMenuRows(false, false), []);

echo "\n--- després d'obrir les accions ---\n";
check('les tres accions hi són i els canvis no', actionsMenuRows(false, true), [$ACCIONS]);

echo "\n--- les dues coses obertes alhora (no hauria de passar) ---\n";
check('es mostren les dues files', actionsMenuRows(true, true), [$SUBSTITUCIONS, $ACCIONS]);

echo "\n--- les accions surten amb el nom exacte dels comandaments ---\n";
// Si un botó no coincidís amb el comandament del router, el jugador el polsaria
// i no passaria res.
$files = actionsMenuRows(false, true);
check('les tres són a la mateixa fila', count($files), 1);
check('i són les tres accions', $files[0], ['/malDia', '/socElMillor', '/guanyarOMorir']);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
