<?php
/**
 * Única font de veritat de la jornada en curs.
 *
 * PER PASSAR DE JORNADA, EDITA NOMÉS EL VALOR DE CURRENT_MATCH_DAY D'AQUEST FITXER.
 *
 * La fan servir aquests scripts, que sempre han d'anar amb la mateixa jornada:
 *   - basic.php                    (el bot, recull accions i substitucions)
 *   - applySubstitutions.php       (aplica les substitucions a la plantilla)
 *   - calculateMatchDayPoints.php  (calcula els punts de la jornada)
 *   - publishSubstitutions.php     (anuncia al grup els canvis aplicats)
 *   - publishActions.php           (publica les accions al grup)
 *   - publishResults.php           (publica els resultats al grup)
 *
 * L'ordre dels passos de la jornada és:
 *   applySubstitutions -> calculateMatchDayPoints -> publishSubstitutions
 *                                                -> publishResults
 * Cada pas es pot tornar a executar pel seu compte: aplicar és idempotent i
 * anunciar no consumeix res, així que si un enviament a Telegram falla només
 * cal repetir aquell pas.
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

/**
 * Darrera jornada en què es poden fer canvis d'equips.
 *
 * Les normes diuen que no es poden fer canvis a partir de les semifinals, així
 * que aquest valor ha de ser l'última jornada que es juga abans d'elles. El bot
 * bloqueja /out i /in a partir de la jornada següent a aquesta.
 *
 * Surt de la numeració que ja fan servir els scripts de final de fase:
 * calculateEndLeaguePhasePoints.php tanca la fase de lliga a la 9 i
 * calculateEndGamePoints.php tanca el joc a la 19. Si cada eliminatòria té anada
 * i tornada, queden play-in (10-11), octaus (12-13), quarts (14-15), semifinals
 * (16-17) i final (18-19). Per tant l'última jornada amb mercat obert és la 15.
 *
 * COMPROVA-HO: si la numeració de les eliminatòries canvia, aquest número és el
 * primer que s'ha d'ajustar.
 */
define('LAST_MATCH_DAY_WITH_CHANGES', 15);

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
