<?php

/**
 * Carrega a team_results els resultats d'una jornada llegits d'una font pública.
 *
 * ABANS: els partits estaven enganxats a mà dins d'aquest fitxer. Cada jornada
 * calia buscar els resultats, retallar-los i enganxar-los, i després executar
 * l'script. Ara es descarreguen de kassiesa.net i s'hi passen sols.
 *
 * COM S'USA
 *
 *   php getResults.php                 # jornada en curs (config/matchDay.php)
 *   php getResults.php 3               # una jornada concreta
 *   php getResults.php 3 --dry-run     # ensenya què faria, sense escriure res
 *   php getResults.php --refresh       # torna a descarregar la pàgina
 *   php getResults.php 10 --competition=CHL,EUL
 *   php getResults.php --source=files/cache/kassiesa-2027.html
 *
 * ELS RESULTATS NO S'ACTUALITZEN
 *
 * team_results té una clau única (equip, jornada, competició). Si una jornada ja
 * té resultats desats, l'script ho detecta abans d'escriure res, imprimeix quins
 * equips ja hi són i s'atura: no actualitza cap punt. Per tornar a carregar una
 * jornada cal esborrar primer les seves files (l'error mateix indica l'SQL).
 *
 * ELS EQUIPS EXCLOSOS
 *
 * Hi ha estats els equips dels quals no participen al joc (ara mateix, Israel:
 * vegeu RESULTS_EXCLUDED_COUNTRIES a config/results.php). D'un partit on
 * apareix un equip exclòs només s'hi desa el rival, perquè els seus punts només
 * depenen del resultat. Això no és cap error i no atura res.
 *
 * SI UN EQUIP NO ES RESOL
 *
 * Un equip que no és exclòs i que no es reconeix sí que és un problema. L'script
 * imprimeix el nom de la font, el resultat i els candidats, i NO desa cap partit
 * (a menys que es passi --force). Normalment n'hi ha prou d'afegir una línia a
 * config/teamAliases.php i tornar-lo a executar.
 */

include __DIR__ . '/vendor/autoload.php';

require_once(__DIR__ . '/config/connection.php');
require_once(__DIR__ . '/config/matchDay.php');
require_once(__DIR__ . '/config/results.php');
require_once(__DIR__ . '/models/team.php');
require_once(__DIR__ . '/models/teamResult.php');
require_once(__DIR__ . '/sources/ResultsSource.php');
require_once(__DIR__ . '/sources/KassiesaResultsSource.php');
require_once(__DIR__ . '/sources/TeamNameResolver.php');
require_once(__DIR__ . '/sources/ResultsPlanner.php');

// Els resultats s'escriuen a la base de dades de producció: només des de la CLI.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

function usage(): void
{
    fwrite(STDOUT, <<<TEXT
Carrega els resultats d'una jornada des de kassiesa.net.

Ús:
  php getResults.php [jornada] [opcions]

Opcions:
  --dry-run                 No escriu res; ensenya què faria.
  --force                   Desa els partits que es resolen encara que n'hi hagi de no resolts.
  --refresh                 Ignora la còpia local i torna a descarregar la pàgina.
  --competition=CHL,EUL,COL Processa només aquestes competicions (per defecte, les tres).
  --source=FITXER           Llegeix els resultats d'un fitxer HTML local en comptes de la xarxa.
  --help                    Aquesta ajuda.

Sense jornada, s'usa CURRENT_MATCH_DAY de config/matchDay.php.

TEXT);
}

// ---------------------------------------------------------------- arguments

$options    = [
    'dry-run'     => false,
    'force'       => false,
    'refresh'     => false,
    'competition' => null,
    'source'      => null,
];
$positional = [];

foreach (array_slice($GLOBALS['argv'], 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        $positional[] = $argument;
        continue;
    }

    $name  = substr($argument, 2);
    $value = null;
    if (str_contains($name, '=')) {
        [$name, $value] = explode('=', $name, 2);
    }

    switch ($name) {
        case 'dry-run':
            $options['dry-run'] = true;
            break;
        case 'force':
            $options['force'] = true;
            break;
        case 'refresh':
            $options['refresh'] = true;
            break;
        case 'competition':
            $options['competition'] = $value;
            break;
        case 'source':
            $options['source'] = $value;
            break;
        case 'help':
            usage();
            exit(0);
        default:
            fwrite(STDERR, "Opció desconeguda: --$name\n\n");
            usage();
            exit(2);
    }
}

// currentMatchDay() ja ignora les opcions i llegeix el primer número.
$matchDay = currentMatchDay();

$competitions = array_keys(RESULTS_COMPETITION_LABELS);
if ($options['competition'] !== null) {
    $competitions = array_values(array_filter(array_map(
        static fn(string $code): string => strtoupper(trim($code)),
        explode(',', $options['competition'])
    )));

    foreach ($competitions as $competition) {
        if (!isset(RESULTS_COMPETITION_LABELS[$competition])) {
            fwrite(STDERR, "Competició desconeguda: $competition (esperat CHL, EUL o COL).\n");
            exit(2);
        }
    }
}

if ($options['source'] !== null && !is_file($options['source'])) {
    fwrite(STDERR, "No existeix el fitxer de resultats: {$options['source']}\n");
    exit(2);
}

// ------------------------------------------------------------------- dades

try {
    if ($options['source'] !== null) {
        $source = KassiesaResultsSource::fromFile($options['source']);
        $origin = $options['source'];
    } else {
        $source = KassiesaResultsSource::fromUrl(resultsSourceUrl(), resultsCachePath(), $options['refresh']);
        $origin = resultsSourceUrl();
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$teamsRepo = new Team();
$resolver  = new TeamNameResolver($teamsRepo->getAllTeams(), require __DIR__ . '/config/teamAliases.php');
$planner   = new ResultsPlanner($source, $resolver, array_keys(RESULTS_EXCLUDED_COUNTRIES));
$resultsRepo = new TeamResult();

$plan = $planner->plan($competitions, $matchDay);

$planned        = $plan['rows'];
$problems       = $plan['problems'];
$withoutMatches = $plan['empty'];

echo "Jornada $matchDay · font: $origin\n\n";

// ------------------------------------------------------------------ informe

$excludedCount = 0;

foreach ($plan['matches'] as $match) {
    $parts = [];

    foreach (['home', 'away'] as $side) {
        $plannedSide = $match[$side];

        if ($plannedSide['excluded']) {
            $excludedCount++;
            $parts[] = $plannedSide['source'] . ' EXCLÒS';
        } elseif ($plannedSide['teamId'] === null) {
            $parts[] = $plannedSide['source'] . ' NO RESOLT';
        } else {
            $parts[] = sprintf('%s %d', $plannedSide['teamName'], $plannedSide['points']);
        }
    }

    printf("%-3s %-44s %s\n", $match['competition'], $match['label'], implode(' · ', $parts));
}

foreach ($plan['simulated'] as $competition) {
    $teams = count(array_filter($planned, static fn(array $row): bool => $row['competition'] === $competition));

    printf(
        "%-3s jornada simulada: aquesta competició no juga la jornada %d; %d equips hi tenen 0.\n",
        $competition,
        $matchDay,
        $teams
    );
}

foreach ($withoutMatches as $competition) {
    echo sprintf("%-3s cap partit publicat encara per a aquesta jornada.\n", $competition);
}

if ($excludedCount > 0) {
    echo "\nEquips exclosos (no participen al joc): " . implode(', ', resultsExcludedCountryNames()) . ".\n";
    echo "Dels partits on apareixen només s'ha desat el rival.\n";
}

if ($problems !== []) {
    echo "\nEquips no resolts:\n";
    foreach ($problems as $problem) {
        $result = $problem['result'];

        echo "  [{$problem['competition']}] \"{$problem['name']}\"";
        echo $result['ambiguous'] ? ' (ambigu) ' : ' (sense candidat prou bo) ';

        $candidates = [];
        foreach ($result['candidates'] as $candidate) {
            $candidates[] = sprintf('%s %.0f', $candidate['name'], $candidate['score']);
        }
        echo $candidates === [] ? "-> cap equip a la BD\n" : '-> ' . implode(', ', $candidates) . "\n";
    }

    echo "\nAfegiu els que calgui a config/teamAliases.php (clau: nom de la font normalitzat,\n";
    echo "sense accents ni espais; valor: el nom exacte de la taula teams) i torneu a executar.\n";
}

if ($planned === []) {
    echo "\nNo hi ha res per desar.\n";
    exit($problems === [] ? 0 : 1);
}

// ------------------------------------------------ resultats ja desats (jornada)

// Un resultat desat no es pot canviar: si aquesta jornada ja té files, no
// s'escriu res. Es mira per (equip, competició), que és la clau única de la
// taula, i es fa abans d'obrir la transacció per no deixar-hi res a mitges.
$existing = [];
foreach ($resultsRepo->getResultsByMatchDay($matchDay) as $row) {
    $existing[$row['team_id'] . '|' . $row['competition']] = $row;
}

$alreadyLoaded = [];
foreach ($planned as $row) {
    if (isset($existing[$row['teamId'] . '|' . $row['competition']])) {
        $alreadyLoaded[] = $row;
    }
}

if ($alreadyLoaded !== []) {
    echo "\nAquesta jornada ja té resultats desats:\n";
    foreach ($alreadyLoaded as $row) {
        $old = $existing[$row['teamId'] . '|' . $row['competition']];
        printf(
            "  %-3s %-18s ja té %d pts (ara en tindria %d)\n",
            $row['competition'],
            $row['teamName'],
            $old['points'],
            $row['points']
        );
    }

    echo "\nNo s'ha desat res: els resultats d'una jornada no s'actualitzen.\n";
    echo "Si de debò s'ha de tornar a carregar, esborra primer les files d'aquesta jornada\n";
    echo "i torna a executar:\n";
    echo "  DELETE FROM team_results WHERE match_day = $matchDay;\n";
    echo "(si només vols una competició, afegeix-hi AND competition = 'CHL', 'EUL' o 'COL')\n";
    exit(1);
}

if ($options['dry-run']) {
    echo "\n--dry-run: no s'ha escrit res (" . count($planned) . " equips).\n";
    exit($problems === [] ? 0 : 1);
}

if ($problems !== [] && !$options['force']) {
    echo "\nNo s'ha escrit res: hi ha " . count($problems) . " equip(s) no resolts.\n";
    echo "Torneu a executar amb --force si voleu desar només els partits que sí que es resolen.\n";
    exit(1);
}

// ------------------------------------------------------------------- escriptura

$written = 0;
$resultsRepo->beginTransaction();

try {
    foreach ($planned as $row) {
        if ($resultsRepo->addTeamResult($row['teamId'], $row['points'], $matchDay, $row['competition']) === false) {
            throw new RuntimeException(
                "no s'ha pogut desar {$row['teamName']} ({$row['competition']}, jornada $matchDay). "
                . "Si ja tenia resultat, el registre d'errors del servidor ho diu."
            );
        }
        $written++;
    }

    $resultsRepo->commit();
} catch (Throwable $e) {
    $resultsRepo->rollBack();
    fwrite(STDERR, "No s'ha desat res: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDesats $written equips a team_results (jornada $matchDay).\n";

exit($problems === [] ? 0 : 1);
