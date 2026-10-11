<?php

/**
 * La jornada que demana la línia de comandes.
 *
 * Els scripts de la jornada accepten el número com a primer argument, i
 * getResults.php a més accepta opcions (--dry-run, --competition=...). Si una
 * opció es prengués per la jornada, l'script calcularia la jornada equivocada:
 * val més comprovar-ho que confiar que l'ordre dels arguments és el bo.
 *
 *   php tests/matchDayArgumentTest.php
 */

require_once(__DIR__ . '/../config/matchDay.php');

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

echo "--- sense arguments: la jornada en curs ---\n";
check('cap argument', matchDayFromArguments([]), CURRENT_MATCH_DAY);

echo "\n--- el número és el primer argument ---\n";
check('un número', matchDayFromArguments(['15']), 15);
check('encara que hi hagi opcions al darrere', matchDayFromArguments(['15', '--dry-run']), 15);
check("encara que l'opció vagi davant", matchDayFromArguments(['--dry-run', '15']), 15);
check('amb una opció amb valor', matchDayFromArguments(['--competition=CHL,EUL', '3']), 3);
check('amb diverses opcions', matchDayFromArguments(['--refresh', '--dry-run', '7', '--force']), 7);

echo "\n--- sense número, la jornada en curs ---\n";
check('només opcions', matchDayFromArguments(['--dry-run', '--refresh']), CURRENT_MATCH_DAY);
check('una opció amb valor', matchDayFromArguments(['--source=fitxer.html']), CURRENT_MATCH_DAY);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
