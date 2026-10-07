<?php
/**
 * Única font de veritat de la jornada en curs.
 *
 * PER PASSAR DE JORNADA, EDITA NOMÉS EL VALOR DE CURRENT_MATCH_DAY D'AQUEST FITXER.
 *
 * La fan servir els 5 scripts vius, que sempre han d'anar amb la mateixa jornada:
 *   - basic.php                    (el bot, recull accions i substitucions)
 *   - publishActions.php           (publica les accions al grup)
 *   - calculateMatchDayPoints.php  (calcula els punts de la jornada)
 *   - publishResults.php           (publica els resultats al grup)
 *   - publishSubstitutions.php     (aplica les substitucions pendents)
 *
 * NO la fan servir els scripts de final de fase, perquè la seva jornada és una
 * fita fixa i no la jornada en curs:
 *   - calculateEndLeaguePhasePoints.php  (final de la fase de lliga)
 *   - calculateEndGamePoints.php         (final del joc)
 *
 * Per reprocessar una jornada antiga sense tocar aquest fitxer, els scripts de
 * CLI accepten el número de jornada com a primer argument:
 *   php publishActions.php 15
 * (El bot no ho pot sobreescriure mai: per web sempre s'usa la constant.)
 */

define('CURRENT_MATCH_DAY', 2);

function currentMatchDay(): int
{
    if (PHP_SAPI !== 'cli') {
        return CURRENT_MATCH_DAY;
    }

    $argument = $GLOBALS['argv'][1] ?? null;

    if ($argument === null) {
        return CURRENT_MATCH_DAY;
    }

    if (!is_numeric($argument) || (int) $argument <= 0) {
        fwrite(STDERR, "ERROR: la jornada ha de ser un número positiu (rebut: '$argument').\n");
        exit(1);
    }

    return (int) $argument;
}
