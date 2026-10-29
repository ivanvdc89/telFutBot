<?php
/**
 * Única font de veritat de la jornada en curs.
 *
 * PER PASSAR DE JORNADA, EDITA NOMÉS EL VALOR DE CURRENT_MATCH_DAY D'ESTE FITXER.
 *
 * La fan servir estos scripts, que sempre han d'anar amb la mateixa jornada:
 *   - basic.php                    (el bot, recull accions i substitucions)
 *   - getResults.php               (carrega els resultats de la jornada)
 *   - applySubstitutions.php       (aplica les substitucions a la plantilla)
 *   - calculateMatchDayPoints.php  (calcula els punts de la jornada)
 *   - publishSubstitutions.php     (anuncia al grup els canvis aplicats)
 *   - publishActions.php           (publica les accions al grup)
 *   - publishResults.php           (publica els resultats al grup)
 *
 * L'ordre dels passos de la jornada és:
 *   getResults -> applySubstitutions -> calculateMatchDayPoints -> publishSubstitutions
 *                                                              -> publishResults
 * Cada pas es pot tornar a executar pel seu compte, amb dos excepcions:
 * getResults no sobreescriu una jornada ja carregada (els resultats no
 * s'actualitzen; si ja hi són, avisa i s'atura), i calculateMatchDayPoints no
 * torna a sumar una jornada ja calculada (si ja té punts, s'atura; per refer-la
 * cal demanar-ho amb --recalculate, que esborra les files d'eixa jornada).
 * Aplicar substitucions és idempotent i anunciar no consumeix res, aixina que si
 * un enviament a Telegram falla només cal repetir aquell pas.
 *
 * NO la fan servir els scripts de final de fase, perquè la seua jornada és una
 * fita fixa i no la jornada en curs:
 *   - calculateEndLeaguePhasePoints.php  (final de la fase de lliga)
 *   - calculateEndGamePoints.php         (final del joc)
 *
 * Per reprocessar una jornada antiga sense tocar este fitxer, els scripts de
 * CLI accepten el número de jornada com a primer argument:
 *   php publishActions.php 15
 * (El bot no ho pot sobreescriure mai: per web sempre s'usa la constant.)
 */

define('CURRENT_MATCH_DAY', 4);

/**
 * Darrera jornada en què es poden fer canvis d'equips.
 *
 * Les normes diuen que no es poden fer canvis a partir de les semifinals, aixina
 * que este valor ha de ser l'última jornada que es juga abans d'elles. El bot
 * bloqueja /out i /in a partir de la jornada següent a esta.
 *
 * Surt de la numeració que ja fan servir els scripts de final de fase:
 * calculateEndLeaguePhasePoints.php tanca la fase de lliga a la 9 i
 * calculateEndGamePoints.php tanca el joc a la 19. Si cada eliminatòria té anada
 * i tornada, queden play-in (10-11), octaus (12-13), quarts (14-15), semifinals
 * (16-17) i final (18-19). Per tant l'última jornada amb mercat obert és la 15.
 *
 * COMPROVA-HO: si la numeració de les eliminatòries canvia, este número és el
 * primer que s'ha d'ajustar.
 */
define('LAST_MATCH_DAY_WITH_CHANGES', 15);

/**
 * Els dos interruptors de la temporada. Els mou l'administrador a mà, i són el
 * que obre i tanca cada fase del joc.
 *
 * SUBSTITUTIONS_OPEN: es poden fer canvis d'equips. Es tanca al termini de cada
 *   jornada, ABANS d'aplicar i publicar els canvis pendents.
 * ACTIONS_OPEN: es poden activar les accions de competició (#malDia,
 *   #socElMillor, #guanyarOMorir). S'obre DESPRÉS d'aplicar i publicar els
 *   canvis, perquè ningú no triï accions sobre una plantilla que encara ha de
 *   canviar.
 *
 * El menú /accions i els handlers surten d'estos dos valors, aixina que tancar
 * una cosa la treu de la llista tot sol: no hi ha cap llista per editar.
 *
 * ORDRE DEL TERMINI D'UNA JORNADA:
 *   1. SUBSTITUTIONS_OPEN = false
 *   2. php applySubstitutions.php        <- encara amb la jornada en curs
 *   3. php publishSubstitutions.php
 *   4. ACTIONS_OPEN = true
 * Canviar CURRENT_MATCH_DAY abans del pas 2 faria que el pas 2 no trobés les
 * substitucions pendents d'esta jornada, i es perdrien.
 */
define('SUBSTITUTIONS_OPEN', true);
define('ACTIONS_OPEN', false);

/**
 * Quina votació està en joc esta jornada: 'doubleOrNothing' (#dobleORes),
 * 'kosAndShields' (#kosAmbEscuts), 'kosPais', 'kosPots' o '' (cap).
 *
 * Els valors són els de la columna `type` de la taula actions, com el que
 * desen les accions.
 *
 * Les quatre votacions (#dobleORes i els tres boicots de KO) són accions
 * distintes, amb el seu comandament i el seu botó, però a cada jornada només
 * n'hi ha UNA d'activa: mai dues alhora. Eixa és la que ix al menú /accions,
 * l'única que respon i l'única que es publica i es compta. Amb la cadena buida
 * no hi ha cap votació (per exemple, abans de començar la temporada).
 *
 * El mou l'administrador a mà, com ACTIONS_OPEN. El valor es valida en carregar
 * este fitxer: una errata atura el bot i els scripts abans que deixen una
 * jornada sense votació.
 */
define('VOTACIO_ACTIVA', 'kosPais');

function currentMatchDay(): int
{
    if (PHP_SAPI !== 'cli') {
        return CURRENT_MATCH_DAY;
    }

    return matchDayFromArguments(array_slice($GLOBALS['argv'], 1));
}

/**
 * La jornada que demana la línia de comandes.
 *
 * Agafa el primer argument que no sigui una opció (--dry-run, --force...). Aixina
 * getResults.php pot acceptar banderes sense que es confonguin amb la jornada, i
 * la resta d'scripts segueixen llegint el primer número.
 *
 * Si no hi ha cap número, torna la jornada en curs. Si n'hi ha un que no val,
 * atura l'script: val més no fer res que calcular la jornada equivocada.
 *
 * @param array<int, string> $arguments Arguments després del nom de l'script.
 */
function matchDayFromArguments(array $arguments): int
{
    foreach ($arguments as $candidate) {
        if (str_starts_with($candidate, '-')) {
            continue;
        }

        if (!is_numeric($candidate) || (int) $candidate <= 0) {
            fwrite(STDERR, "ERROR: la jornada ha de ser un número positiu (rebut: '$candidate').\n");
            exit(1);
        }

        return (int) $candidate;
    }

    return CURRENT_MATCH_DAY;
}

/**
 * Valors que pot prendre VOTACIO_ACTIVA.
 */
const VOTACIONS = ['doubleOrNothing', 'kosAndShields', 'kosPais', 'kosPots', ''];

/**
 * El valor de VOTACIO_ACTIVA, comprovat.
 *
 * @throws RuntimeException Si el valor no és cap de les votacions.
 */
function votacioActiva(): string
{
    if (!in_array(VOTACIO_ACTIVA, VOTACIONS, true)) {
        throw new RuntimeException(
            "VOTACIO_ACTIVA no és cap votació: '" . VOTACIO_ACTIVA
            . "' (ha de ser 'doubleOrNothing', 'kosAndShields', 'kosPais', 'kosPots' o '')"
        );
    }

    return VOTACIO_ACTIVA;
}

// Comprovar-ho en carregar el fitxer: val més no arrancar que fer una jornada
// amb una votació que no existeix.
votacioActiva();
