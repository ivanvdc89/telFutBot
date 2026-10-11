<?php

/**
 * Els resultats d'una jornada no s'actualitzen mai.
 *
 * Aquesta prova és estàtica pel que fa a addTeamResult: sense base de dades no
 * es pot executar l'INSERT, així que es comprova el que hi ha escrit al model.
 * És una salvaguarda contra la temptació de tornar a posar-hi un
 * "ON DUPLICATE KEY UPDATE" perquè "així es pot repetir l'script": si els punts
 * d'una jornada ja calculada es poguessin canviar per darrere, la classificació
 * deixaria de quadrar i ningú no se n'adonaria.
 *
 * La detecció del duplicat (errno 1062) sí que es prova de debò, amb una
 * PDOException.
 *
 *   php tests/teamResultModelTest.php
 */

require_once(__DIR__ . '/../config/connection.php');
require_once(__DIR__ . '/../models/teamResult.php');

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

// php_strip_whitespace treu els comentaris: el que es mira és el codi, no el que
// diu la documentació.
$model = php_strip_whitespace(__DIR__ . '/../models/teamResult.php');
$app   = file_get_contents(__DIR__ . '/../getResults.php');

echo "--- el model no pot actualitzar res ---\n";
check('hi ha un INSERT', str_contains($model, 'INSERT INTO team_results'), true);
check(
    "no hi ha cap ON DUPLICATE KEY UPDATE",
    stripos($model, 'ON DUPLICATE KEY UPDATE') === false,
    true
);
check(
    "no hi ha cap UPDATE team_results",
    stripos($model, 'UPDATE team_results') === false,
    true
);
check('quan l\'INSERT falla, torna false', str_contains($model, 'return false;'), true);

echo "\n--- getResults avisa abans d'escriure ---\n";
check('comprova què hi ha desat a la jornada', str_contains($app, 'getResultsByMatchDay'), true);
check('i s\'atura sense escriure', str_contains($app, "no s'actualitzen"), true);

echo "\n--- un xoc de clau única es reconeix ---\n";
$duplicate = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '49-1-EUL' for key 'u_team_matchday'");
$duplicate->errorInfo = ['23000', 1062, "Duplicate entry '49-1-EUL' for key 'u_team_matchday'"];

$other = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
$other->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

$isDuplicate = new ReflectionMethod(TeamResult::class, 'isDuplicateKey');
$isDuplicate->setAccessible(true);

check('errno 1062 és duplicat', $isDuplicate->invoke(null, $duplicate), true);
check('un altre error no', $isDuplicate->invoke(null, $other), false);

echo "\n--- i un duplicat que no porti errno es reconeix pel text ---\n";
$byText = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7-2-COL' for key 'u_team_matchday'");
check('pel missatge', $isDuplicate->invoke(null, $byText), true);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
