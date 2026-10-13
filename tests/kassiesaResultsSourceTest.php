<?php

/**
 * El lector de resultats de kassiesa.net, sense xarxa i sense base de dades.
 *
 * El que es comprova és el que fa que getResults.php sigui fiable:
 *
 *   - la fase de lliga es parteix en jornades pels blocs separats per files
 *     buides, i el bloc 1 és la jornada 1;
 *   - les eliminatòries donen l'anada i la tornada, i a la tornada els equips
 *     s'intercanvien (es juga a casa del segon);
 *   - els partits sense resultat no existeixen (una jornada futura dona llista
 *     buida, no partits 0-0);
 *   - les tandes de penals no reparteixen punts i s'ignoren;
 *   - les competicions no es barregen.
 *
 *   php tests/kassiesaResultsSourceTest.php
 */

require_once(__DIR__ . '/../sources/KassiesaResultsSource.php');

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

/** Només el que ens interessa de cada partit, per comparar llistes senceres. */
function simple(array $matches): array
{
    return array_map(
        static fn(array $m): string => sprintf('%s %d-%d %s', $m['home'], $m['home_goals'], $m['away_goals'], $m['away']),
        $matches
    );
}

// ------------------------------------------------------------------- fixture

$html = <<<'HTML'
<!DOCTYPE html>
<html><body>
<table class="t1">
<colgroup><col><col><col><col><col><col></colgroup>
<tr><th colspan="6"><div class="cupheader">
CHAMPIONS LEAGUE
</div></th></tr>
<tr><th colspan="6"><div class="roundheader">
League Stage
</div></th></tr>
<tr>
<td><b>Ararat-Armenia</b></td><td>Arm</td><td>Sparta Praha</td><td>Cze</td>
<td class="result">1-4</td><td class="result"></td>
</tr><tr>
<td>Omonia Nicosia</td><td>Cyp</td><td><b>Celta de Vigo</b></td><td>Esp</td>
<td class="result">1-0</td><td class="result"></td>
</tr><tr>
<td colspan="6"> &nbsp; </td>
</tr><tr>
<td><b>FC Barcelona</b></td><td>Esp</td><td>Benfica</td><td>Por</td>
<td class="result">3-1</td><td class="result"></td>
</tr><tr>
<td>Ajax</td><td>Ned</td><td>Napoli</td><td>Ita</td>
<td class="result"></td><td class="result"></td>
</tr><tr>
<td colspan="6"> &nbsp; </td>
</tr><tr>
<td>Ajax</td><td>Ned</td><td>Benfica</td><td>Por</td>
<td class="result"></td><td class="result"></td>
</tr><tr>
<th colspan="6"><div class="roundheader">
Knockout round play-offs
</div></th></tr>
<tr>
<td><b>AS Monaco</b></td><td>Fra</td><td>Galatasaray</td><td>Tur</td>
<td class="result">2-3</td><td class="result">2-2</td>
</tr><tr>
<td>Benfica</td><td>Por</td><td><b>Borussia Dortmund</b></td><td>Ger</td>
<td class="result">0-1</td><td class="result">1-2</td>
</tr><tr>
<td colspan="5" class="pstext">Penalty shootout: Lech Poznan - AGF Aarhus</td>
<td class="psresult">3 - 4</td>
</tr><tr>
<th colspan="6"><div class="roundheader">
Final
</div></th></tr>
<tr>
<td>Paris Saint-Germain</td><td>Fra</td><td>Arsenal</td><td>Eng</td>
<td class="result">1-1</td><td class="result"></td>
</tr>
</table>

<table class="t1">
<tr><th colspan="6"><div class="cupheader">EUROPA LEAGUE</div></th></tr>
<tr><th colspan="6"><div class="roundheader">League Stage</div></th></tr>
<tr>
<td>Lillestrøm SK</td><td>Nor</td><td>União Torreense</td><td>Por</td>
<td class="result">1-2</td><td class="result"></td>
</tr><tr>
<th colspan="6"><div class="roundheader">Knockout round play-offs</div></th></tr>
<tr>
<td>Lech Poznan</td><td>Pol</td><td>AGF Aarhus</td><td>Den</td>
<td class="result">1-4</td><td class="result">4-1</td>
</tr><tr>
<td colspan="5" class="pstext">Penalty shootout: Lech Poznan - AGF Aarhus</td>
<td class="psresult">3 - 4</td>
</tr>
</table>

<table class="t1">
<tr><th colspan="6"><div class="cupheader">CONFERENCE LEAGUE</div></th></tr>
<tr><th colspan="6"><div class="roundheader">League Stage</div></th></tr>
<tr>
<td>Crystal Palace</td><td>Eng</td><td>Lech Poznan</td><td>Pol</td>
<td class="result">4-0</td><td class="result"></td>
</tr><tr>
<td colspan="6"> &nbsp; </td>
</tr><tr>
<td>Ajax</td><td>Ned</td><td>Benfica</td><td>Por</td>
<td class="result">2-1</td><td class="result"></td>
</tr>
</table>
</body></html>
HTML;

$source = KassiesaResultsSource::fromHtml($html);

// --------------------------------------------------------------- fase de lliga

echo "--- fase de lliga: cada bloc és una jornada ---\n";
check('la jornada 1 té els dos partits del primer bloc', simple($source->getMatches('CHL', 1)), [
    'Ararat-Armenia 1-4 Sparta Praha',
    'Omonia Nicosia 1-0 Celta de Vigo',
]);
check('la jornada 2 només té el partit amb resultat', simple($source->getMatches('CHL', 2)), [
    'FC Barcelona 3-1 Benfica',
]);
check("la jornada 3 encara no s'ha jugat", $source->getMatches('CHL', 3), []);
check('la jornada 4 tampoc no existeix', $source->getMatches('CHL', 4), []);

echo "\n--- cada competició va per separat ---\n";
check("l'Europa League té el seu partit", simple($source->getMatches('EUL', 1)), [
    'Lillestrøm SK 1-2 União Torreense',
]);

echo "\n--- la lligueta de la Conference comença a la jornada 2 ---\n";
// Té 6 jornades i el joc 8: la seua primera ronda es desa a la jornada 2, i la
// 1 i la 8 les omple ResultsPlanner amb zeros.
check('la jornada 1 no té partits de Conference', $source->getMatches('COL', 1), []);
check('la primera ronda és la jornada 2', simple($source->getMatches('COL', 2)), [
    'Crystal Palace 4-0 Lech Poznan',
]);
check('la segona ronda és la jornada 3', simple($source->getMatches('COL', 3)), [
    'Ajax 2-1 Benfica',
]);
check('la jornada 8 tampoc no té partits', $source->getMatches('COL', 8), []);
check('i la setena no existeix al fixture', $source->getMatches('COL', 7), []);

echo "\n--- a la Champions i a l'Europa League, la jornada 1 sí que és la primera ronda ---\n";
check('la jornada 8 és l\'última ronda', count($source->getMatches('CHL', 8)), 0);
check('i la jornada 1 és la primera', count($source->getMatches('CHL', 1)), 2);

// ---------------------------------------------------------------- eliminatòries

echo "\n--- eliminatòries: anada i tornada ---\n";
check('la jornada 10 és l\'anada', simple($source->getMatches('CHL', 10)), [
    'AS Monaco 2-3 Galatasaray',
    'Benfica 0-1 Borussia Dortmund',
]);
check('la jornada 11 és la tornada, amb els equips girats', simple($source->getMatches('CHL', 11)), [
    'Galatasaray 2-2 AS Monaco',
    'Borussia Dortmund 1-2 Benfica',
]);

echo "\n--- la final és a partit únic ---\n";
check('la jornada 18 té la final', simple($source->getMatches('CHL', 18)), [
    'Paris Saint-Germain 1-1 Arsenal',
]);
check('i no té tornada', $source->getMatches('CHL', 19), []);

echo "\n--- la tanda de penals no és cap partit ---\n";
// L'Europa League té una tanda just després d'una eliminatòria: si es colés,
// hi hauria un partit de més a la jornada 10 i un equip inventat.
check("l'Europa League només té l'eliminatòria", simple($source->getMatches('EUL', 10)), [
    'Lech Poznan 1-4 AGF Aarhus',
]);
check('i la tornada', simple($source->getMatches('EUL', 11)), [
    'AGF Aarhus 4-1 Lech Poznan',
]);

// -------------------------------------------------------------------- resultats

echo "\n--- lectura de resultats ---\n";
check('un resultat normal', KassiesaResultsSource::parseScore('2-0'), [2, 0]);
check('amb guió llarg', KassiesaResultsSource::parseScore('2–1'), [2, 1]);
check('amb espais', KassiesaResultsSource::parseScore(' 3 - 1 '), [3, 1]);
check('buit no és cap resultat', KassiesaResultsSource::parseScore(''), null);
// El resultat d'una tanda també té forma de resultat: qui descarta la fila és
// la classe pstext/psresult, no el format del número (ho comprova el bloc de
// les eliminatòries, més amunt).
check('un resultat de tanda també es pot llegir', KassiesaResultsSource::parseScore('3 - 4'), [3, 4]);
check('text no és cap resultat', KassiesaResultsSource::parseScore('ajornat'), null);

// ------------------------------------------------------------------- estructura

echo "\n--- files que no són partits ---\n";
$all = $source->getMatches('CHL', 1);
check('el primer partit té el país del local', $all[0]['home_country'], 'Arm');
check('i el del visitant', $all[0]['away_country'], 'Cze');

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
