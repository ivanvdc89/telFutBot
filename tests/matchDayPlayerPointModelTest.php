<?php

/**
 * L'última jornada calculada d'un jugador.
 *
 * El cas que va fer caure calculateMatchDayPoints.php: a la jornada 1 el
 * jugador encara no té cap fila, i el model tornava null, que no cap en un
 * retorn declarat array. El que s'espera és un acumulat a zero, amb els quatre
 * totals que els scripts de càlcul sumen sempre, i sense 'match_day', perquè
 * la comprovació "ja havia jugat abans?" dels scripts seguixca dient que no.
 *
 * La prova va contra una PDO de debò (SQLite en memòria), perquè el que es
 * comprova és el que fa el model i no el que diu el seu codi.
 *
 *   php tests/matchDayPlayerPointModelTest.php
 */

require_once(__DIR__ . '/../config/connection.php');
require_once(__DIR__ . '/../models/matchDayPlayerPoint.php');

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

/**
 * El model demana l'encoding amb "SET NAMES 'utf8'", que és cosa de MySQL i
 * SQLite no entén. Com que la crida és parent::set_names(), no es pot
 * interceptar des d'un fill del model: s'ha de fer des de la mateixa PDO.
 */
class SqliteTestPdo extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'SET NAMES') === 0) {
            return parent::query('SELECT 1');
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

$pdo = new SqliteTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE match_day_player_points (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    match_day INTEGER NOT NULL,
    chl_total INTEGER,
    eul_total INTEGER,
    col_total INTEGER,
    total INTEGER
)');

$pdo->exec("INSERT INTO match_day_player_points
    (player_id, match_day, chl_total, eul_total, col_total, total)
    VALUES (1, 1, 7, 3, 0, 10), (1, 2, 12, 9, 0, 21), (2, 1, 4, 0, 6, 10)");

$repo = new MatchDayPlayerPoint();

// El model demana la connexió amb parent::connect(), aixina que no es pot
// interceptar des d'un fill: la connexió compartida de Connection és privada i
// estàtica, i s'hi posa la de la prova per reflexió. Aixina el model passa pel
// seu camí de debò (connect + set_names + select).
$shared = new ReflectionProperty(Connection::class, 'shared');
$shared->setAccessible(true);
$shared->setValue(null, $pdo);

// El PHP que va fer caure l'script: "Undefined array key 0" i el TypeError del
// retorn. Si en tornara a haver cap, la prova ha de fallar.
$phpProblems = [];
set_error_handler(function (int $errno, string $message, string $file, int $line) use (&$phpProblems): bool {
    if (in_array($errno, [E_WARNING, E_NOTICE, E_DEPRECATED], true)) {
        $phpProblems[] = $message;

        return true;
    }

    return false;
});

echo "--- un jugador que encara no ha jugat cap jornada ---\n";
// Amb el bug original açò petava: el model tornava null i el tipus de retorn és
// array. Es captura perquè la prova puga dir què falla en compte de morir-se.
$noMatchDays = null;
try {
    $noMatchDays = $repo->getLastMatchDayByPlayer(99);
} catch (TypeError $e) {
    $noMatchDays = null;
}

check('torna un array', is_array($noMatchDays), true);
check(
    'sense match_day: encara no ha jugat',
    is_array($noMatchDays) && array_key_exists('match_day', $noMatchDays),
    false
);
check('el total arranca de zero', $noMatchDays['total'] ?? null, 0);
check('i els de cada competició també', [
    $noMatchDays['chl_total'] ?? null,
    $noMatchDays['eul_total'] ?? null,
    $noMatchDays['col_total'] ?? null,
], [0, 0, 0]);
check(
    'la suma de la jornada 1 no arrossega res',
    ($noMatchDays['total'] ?? null) + 13,
    13
);
check(
    'el guard de players.points hi entra (jornada 1 sí que actualitza la general)',
    is_array($noMatchDays) && (!isset($noMatchDays['match_day']) || 1 >= (int) $noMatchDays['match_day']),
    true
);

echo "\n--- un jugador que ja ha jugat ---\n";
$played = $repo->getLastMatchDayByPlayer(1);

check('torna la jornada més alta', $played['match_day'] ?? null, 2);
check('amb els seus acumulats', [$played['chl_total'] ?? null, $played['total'] ?? null], [12, 21]);
check(
    'i el guard esta vegada no deixa tocar la general si es reprocessa la 1',
    !isset($played['match_day']) || 1 >= (int) $played['match_day'],
    false
);

echo "\n--- els acumulats no es barregen entre jugadors ---\n";
$other = $repo->getLastMatchDayByPlayer(2);

check('la jornada del jugador 2', $other['match_day'] ?? null, 1);
check('els seus acumulats', [$other['chl_total'] ?? null, $other['col_total'] ?? null], [4, 6]);

echo "\n--- emptyTotals() és el que els scripts esperen ---\n";
check(
    'les mateixes claus que llegeixen calculateMatchDayPoints i els de final de fase',
    method_exists(MatchDayPlayerPoint::class, 'emptyTotals')
        ? array_keys(MatchDayPlayerPoint::emptyTotals())
        : null,
    ['chl_total', 'eul_total', 'col_total', 'total']
);

echo "\n--- el model no ha emés cap avís de PHP ---\n";
check('ni Undefined array key ni TypeError', $phpProblems, []);

restore_error_handler();

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
