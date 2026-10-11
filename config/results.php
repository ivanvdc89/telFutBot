<?php

/**
 * D'on surten els resultats de la temporada.
 *
 * Fins ara getResults.php portava els partits enganxats a mà dins del fitxer i
 * els insería a team_results. Això volia dir buscar els resultats, retallar-los
 * i enganxar-los cada jornada, amb el risc d'equivocar-se o de deixar-s'hi
 * partits. Ara els llegeix d'una font pública.
 *
 * LA FONT: kassiesa.net (les dades de Bert Kassies).
 *
 * És una pàgina HTML, no una API, però és la que millor encaixa amb aquest joc:
 *
 *   - Cobreix les tres competicions en un sol fitxer, separades per una capçalera
 *     ("CHAMPIONS LEAGUE", "EUROPA LEAGUE", "CONFERENCE LEAGUE").
 *   - Cada partit porta els dos equips, el país de cada un i els dos resultats
 *     de l'eliminatòria (anada i tornada) en dues columnes.
 *   - Les jornades de la fase de lliga són blocs separats per una fila buida,
 *     i les eliminatòries tenen una capçalera pròpia ("Round of 16",
 *     "Quarter Finals"...). Això dona un mapa 1:1 amb les jornades del joc.
 *   - Els noms dels equips són els anglesos habituals, que és el que el joc ja
 *     feia servir quan s'enganxaven a mà.
 *
 * Les alternatives (Wikipedia i les APIs de pagament) es descarten perquè el
 * joc no en treu res a canvi: vegeu docs/results.md.
 *
 * AQUEST FITXER NOMÉS TÉ CONFIGURACIÓ. Per canviar de temporada, toca
 * RESULTS_SEASON i, si la UEFA canvia el format, el mapa d'eliminatòries.
 */

/**
 * Any que dona nom al fitxer de kassiesa.net.
 *
 * .../match2027.html és la temporada 2026/2027. El fitxer s'anomena per l'any
 * en què s'acaba la temporada, no pel que comença.
 */
const RESULTS_SEASON = 2027;

/** URL base de les dades de Bert Kassies. */
const RESULTS_BASE_URL = 'https://kassiesa.net/uefa/data/method5/';

/** Etiqueta de la fase de lliga dins la pàgina de kassiesa. */
const RESULTS_LEAGUE_STAGE = 'League Stage';

/**
 * Etiqueta de kassiesa per a cada competició del joc.
 *
 * Les claus són l'ENUM de team_results; els valors, la capçalera de la taula.
 */
const RESULTS_COMPETITION_LABELS = [
    'CHL' => 'CHAMPIONS LEAGUE',
    'EUL' => 'EUROPA LEAGUE',
    'COL' => 'CONFERENCE LEAGUE',
];

/**
 * Jornada de l'anada de cada eliminatòria.
 *
 * La tornada és sempre la jornada següent. La numeració és la que ja fan servir
 * els scripts de final de fase: la fase de lliga acaba a la 9
 * (calculateEndLeaguePhasePoints.php) i el joc a la 19
 * (calculateEndGamePoints.php). Per tant:
 *
 *   play-off (10-11), vuitens (12-13), quarts (14-15), semifinals (16-17)
 *   i final (18, a partit únic).
 *
 * D'aquí surt també LAST_MATCH_DAY_WITH_CHANGES = 15 a config/matchDay.php: el
 * mercat es tanca abans de les semifinals, que comencen a la 16.
 */
const RESULTS_KNOCKOUT_ROUNDS = [
    'Knockout round play-offs' => 10,
    'Round of 16'              => 12,
    'Quarter Finals'           => 14,
    'Semi Finals'              => 16,
    'Final'                    => 18,
];

/**
 * La fase de lliga de cada competició.
 *
 * 'from' i 'to' són les jornades del joc que ocupa la lligueta, i 'win_points'
 * quants punts val una victòria en aquesta fase (l'empat sempre val 1 i la
 * derrota 0).
 *
 * LA CONFERENCE NOMÉS JUGA 6 JORNADES DE LLIGUETA
 *
 * El joc en té 8 per a tothom, perquè les tres competicions avancin juntes.
 * Per quadrar-ho, la lligueta de la Conference va de la jornada 2 a la 7: la 1
 * i la 8 són jornades simulades, en què tots els seus equips tenen 0 punts.
 * Els resultats de la seva primera ronda, doncs, es desen a la jornada 2 del
 * joc (vegeu resultsLeagueRound()).
 *
 * I com que juga dues jornades menys, la victòria hi val 4 punts en comptes de
 * 3. Amb 6 partits, guanyar-los tots dona 24 punts, exactament els mateixos que
 * guanyar els 8 de la Champions o l'Europa League: la compensació és exacta.
 *
 * Això només val per a la lligueta. A les eliminatòries la victòria torna a
 * valer 3 a totes les competicions.
 */
const RESULTS_LEAGUE_PHASE = [
    'CHL' => ['from' => 1, 'to' => 8, 'win_points' => 3],
    'EUL' => ['from' => 1, 'to' => 8, 'win_points' => 3],
    'COL' => ['from' => 2, 'to' => 7, 'win_points' => 4],
];

/** Primera i darrera jornada de la fase de lliga del joc (les tres competicions). */
const RESULTS_LEAGUE_MATCH_DAYS = ['from' => 1, 'to' => 8];

/** Cert si la jornada és de la fase de lliga del joc. */
function resultsIsLeagueMatchDay(int $matchDay): bool
{
    return $matchDay >= RESULTS_LEAGUE_MATCH_DAYS['from'] && $matchDay <= RESULTS_LEAGUE_MATCH_DAYS['to'];
}

/**
 * Ronda de la lligueta de kassiesa que toca a una jornada del joc.
 *
 * Torna null si aquella jornada no és de la lligueta d'aquella competició: o
 * perquè és d'una eliminatòria, o perquè és una de les jornades simulades.
 */
function resultsLeagueRound(string $competition, int $matchDay): ?int
{
    $phase = RESULTS_LEAGUE_PHASE[$competition] ?? null;

    if ($phase === null || $matchDay < $phase['from'] || $matchDay > $phase['to']) {
        return null;
    }

    return $matchDay - $phase['from'] + 1;
}

/**
 * Cert si és una jornada de lliga que aquella competició no juga.
 *
 * Són les jornades que el joc ha de donar per jugades amb tots els equips a 0
 * (ara mateix, la 1 i la 8 de la Conference).
 */
function resultsIsSimulatedLeagueMatchDay(string $competition, int $matchDay): bool
{
    return resultsIsLeagueMatchDay($matchDay) && resultsLeagueRound($competition, $matchDay) === null;
}

/** Punts que val una victòria en una jornada: 4 a la lligueta de la Conference, 3 a la resta. */
function resultsWinPoints(string $competition, int $matchDay): int
{
    if (resultsLeagueRound($competition, $matchDay) === null) {
        return 3;
    }

    return RESULTS_LEAGUE_PHASE[$competition]['win_points'];
}

/**
 * Estats els equips dels quals NO participen al joc.
 *
 * Els equips de l'estat d'Israel no són a la taula teams ni hi han de ser mai.
 * Quan un partit en porta un, a team_results només s'hi desa el rival: els seus
 * punts només depenen del resultat, així que es poden calcular igual i el partit
 * no ha de bloquejar la jornada.
 *
 * La clau és el codi que kassiesa posa a la columna de país del partit (tres
 * lletres) i el valor, el nom per als missatges. Amb el país n'hi ha prou:
 * qualsevol equip israelià que aparegui en el futur (Maccabi Tel-Aviv, Maccabi
 * Haifa...) queda exclòs tot sol, sense haver d'anar afegint noms.
 *
 * PER AFEGIR-NE UN, afegeix una línia aquí. Si un dia la font canvia els codis,
 * es veurà de seguida: els equips deixaran de sortir com a exclosos i tornaran a
 * aparèixer com a "no resolts".
 */
const RESULTS_EXCLUDED_COUNTRIES = [
    'Isr' => 'Israel',
];

/** Cert si el codi de país d'un partit és d'un estat exclòs. */
function resultsCountryIsExcluded(?string $countryCode): bool
{
    return isset(RESULTS_EXCLUDED_COUNTRIES[(string) $countryCode]);
}

/** Noms dels estats exclosos, per als missatges. */
function resultsExcludedCountryNames(): array
{
    return array_values(RESULTS_EXCLUDED_COUNTRIES);
}

/** URL del fitxer de resultats d'una temporada. */
function resultsSourceUrl(int $season = RESULTS_SEASON): string
{
    return RESULTS_BASE_URL . 'match' . $season . '.html';
}

/**
 * Còpia local de la pàgina, per no dependre de la xarxa a cada execució.
 *
 * Viu a files/cache/, que està al .gitignore: és una còpia temporal, no una
 * font de dades del repositori.
 */
function resultsCachePath(int $season = RESULTS_SEASON): string
{
    return __DIR__ . '/../files/cache/kassiesa-' . $season . '.html';
}
