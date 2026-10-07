<?php
// Prova funcional de SubstitutionAction amb dobles (sense BD ni Telegram).
// S'executa des de l'arrel del projecte: php tests/substitutionActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/matchDay.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");
require_once("models/matchDayPlayerPoint.php");
require_once("actions/SubstitutionAction.php");

use TelegramBot\Api\BotApi;

class FakeBot extends BotApi
{
    public array $sent = [];

    public function __construct()
    {
        parent::__construct('%TOKEN_ID');
    }

    public function sendMessage(
        $chatId,
        $text,
        $parseMode = null,
        $disablePreview = false,
        $replyToMessageId = null,
        $replyMarkup = null,
        $disableNotification = false,
        $messageThreadId = null,
        $protectContent = null,
        $allowSendingWithoutReply = null
    ) {
        $keyboard = null;
        if ($replyMarkup !== null) {
            $keyboard = json_decode($replyMarkup->toJson(), true)['keyboard'] ?? null;
        }
        $this->sent[] = ['text' => $text, 'keyboard' => $keyboard];

        return null;
    }
}

class FakePlayer extends Player
{
    public array $players = [];

    public function getPlayerByChatId($chatId)
    {
        return isset($this->players[$chatId]) ? [$this->players[$chatId]] : [];
    }
}

class FakeTeam extends Team
{
    public array $teamsByPlayer = [];
    public array $teamsByPot    = [];
    public array $teamsByName   = [];
    public array $teamsById     = [];

    public function getTeamsByPlayerId($playerId)
    {
        return $this->teamsByPlayer[$playerId] ?? [];
    }

    public function getTeamsByPot($pot)
    {
        return $this->teamsByPot[$pot] ?? [];
    }

    public function getTeamByName($name)
    {
        return isset($this->teamsByName[$name]) ? [$this->teamsByName[$name]] : [];
    }

    public function getTeamById($teamId)
    {
        return isset($this->teamsById[$teamId]) ? [$this->teamsById[$teamId]] : [];
    }
}

class FakeSubstitution extends Substitution
{
    public array $pending = [];
    public array $added   = [];
    public array $removed = [];

    public function getPendingSubstitutionsByPlayerId(int $playerId)
    {
        return $this->pending;
    }

    public function addSubstitution(int $playerId, int $matchDay, int $oldTeamId, int $newTeamId, string $competition, int $pointsCost)
    {
        $this->added[] = [
            'playerId'    => $playerId,
            'matchDay'    => $matchDay,
            'oldTeamId'   => $oldTeamId,
            'newTeamId'   => $newTeamId,
            'competition' => $competition,
            'pointsCost'  => $pointsCost,
        ];

        return 1;
    }

    public function removePendingSubstitution(int $id)
    {
        $this->removed[] = $id;

        return true;
    }
}

class FakePoints extends MatchDayPlayerPoint
{
    /** @var array [player_id => total acumulat] */
    public array $lastTotals = [];

    public function getLastTotalsByPlayer(): array
    {
        return $this->lastTotals;
    }
}

// ---------- fixtures ----------

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

/**
 * Les files d'equips d'un jugador, pot a pot.
 * El pot 1 és d'Espanya i el pot 5 de França per fer llegibles els xocs de país.
 */
function ownedTeams(array $pots): array
{
    $countries = [1 => 'Spain', 5 => 'France'];
    $teams     = [];

    foreach ($pots as $pot) {
        $teams[] = [
            'id'          => 100 + $pot,
            'name'        => 'OwnedPot' . $pot,
            'country'     => $countries[$pot] ?? ('Country' . $pot),
            'competition' => $pot <= 4 ? 'CHL' : ($pot <= 8 ? 'EUL' : 'COL'),
            'pot'         => $pot,
        ];
    }

    return $teams;
}

function pendingRow(int $id, string $competition, int $oldTeamId, int $newTeamId, int $matchDay = MATCH_DAY): array
{
    return [
        'id'          => $id,
        'match_day'   => $matchDay,
        'competition' => $competition,
        'old_team_id' => $oldTeamId,
        'new_team_id' => $newTeamId,
    ];
}

function buildWorld(array $ownedPots, ?array $standings = null): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $team   = new FakeTeam();
    $sub    = new FakeSubstitution();
    $points = new FakePoints();

    // Per defecte el jugador va enganxat al líder, així que el canvi costa 6.
    $points->lastTotals = $standings ?? [PLAYER_ID => 100, 8 => 95];

    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    $owned = ownedTeams($ownedPots);
    $team->teamsByPlayer[PLAYER_ID] = $owned;

    foreach ($owned as $t) {
        $team->teamsById[$t['id']]     = $t;
        $team->teamsByName[$t['name']] = $t;
    }

    $candidates = [
        ['id' => 1,  'name' => 'CandA',           'country' => 'Italy',    'competition' => 'CHL', 'pot' => 1],
        ['id' => 2,  'name' => 'CandB',           'country' => 'Portugal', 'competition' => 'CHL', 'pot' => 1],
        ['id' => 3,  'name' => 'CandSameCountry', 'country' => 'Spain',    'competition' => 'CHL', 'pot' => 1],
        ['id' => 4,  'name' => 'CandGermany',     'country' => 'Germany',  'competition' => 'CHL', 'pot' => 1],
        ['id' => 50, 'name' => 'CandPot5',        'country' => 'Belgium',  'competition' => 'EUL', 'pot' => 5],
        ['id' => 51, 'name' => 'CandGermany5',    'country' => 'Germany',  'competition' => 'EUL', 'pot' => 5],
        ['id' => 52, 'name' => 'CandSpain5',      'country' => 'Spain',    'competition' => 'EUL', 'pot' => 5],
    ];

    foreach ($candidates as $t) {
        $team->teamsByPot[$t['pot']][] = $t;
        $team->teamsById[$t['id']]     = $t;
        $team->teamsByName[$t['name']] = $t;
    }

    return [$bot, $player, $team, $sub, $points];
}

// ---------- harness ----------

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

function checkContains(string $label, ?string $haystack, string $needle): void
{
    global $failures;
    $ok = $haystack !== null && str_contains($haystack, $needle);
    if (!$ok) {
        $failures++;
    }
    printf("%s %s\n", $ok ? '  ok  ' : ' FAIL ', $label);
    if (!$ok) {
        printf("         no conté %s a: %s\n", json_encode($needle), json_encode($haystack, JSON_UNESCAPED_UNICODE));
    }
}

/** @return array [missatges enviats, repo de substitucions] */
function runCommand(array $world, string $text, int $lastMatchDayWithChanges = LAST_MATCH_DAY_WITH_CHANGES): array
{
    [$bot, $player, $team, $sub, $points] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $action = new SubstitutionAction($bot, $player, $team, $sub, $points, CHAT_ID, MATCH_DAY, $lastMatchDayWithChanges, $args);
    $action->run($args[0]);

    return [$bot->sent, $sub];
}

function flatKeyboard(?array $keyboard): array
{
    return $keyboard === null ? [] : array_merge(...$keyboard);
}

// ---------- escenaris ----------

echo "--- /substitució sense cap pendent (comportament d'abans) ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/substitució');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'Els teus equips:');
check('4 files de 3 botons', count($sent[0]['keyboard']), 4);
check('primera fila', $sent[0]['keyboard'][0], ['/out OwnedPot1', '/out OwnedPot2', '/out OwnedPot3']);
check('última fila', $sent[0]['keyboard'][3], ['/out OwnedPot10', '/out OwnedPot11', '/out OwnedPot12']);
check('sense botó de remove', in_array('/substitució remove', flatKeyboard($sent[0]['keyboard']), true), false);

echo "\n--- /substitució amb el pot 5 buit ---\n";
[$sent] = runCommand(buildWorld([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12]), '/substitució');
$flat = flatKeyboard($sent[0]['keyboard']);
check('hi ha el botó del pot buit', in_array('/out EUL_Pot_1', $flat, true), true);
check('el pot ple no és un botó buit', in_array('/out EUL_Pot_2', $flat, true), false);

echo "\n--- /substitució amb un canvi pendent a CHL ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent] = runCommand($world, '/substitució');
checkContains('llista el pendent', $sent[0]['text'], "Canvis pendents:\n- CHL: OwnedPot1 -> CandGermany");
checkContains('i mostra els equips', $sent[0]['text'], 'Els teus equips:');
$flat = flatKeyboard($sent[0]['keyboard']);
check('els equips de CHL no s\'ofereixen', in_array('/out OwnedPot1', $flat, true), false);
check('els equips d\'EUL sí', in_array('/out OwnedPot5', $flat, true), true);
check('botó per eliminar', in_array('/substitució remove', $flat, true), true);
check('última fila és el remove', end($sent[0]['keyboard']), ['/substitució remove', '/inici']);

echo "\n--- /substitució amb els 3 canvis fets ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [
    pendingRow(33, 'CHL', 101, 4),
    pendingRow(34, 'EUL', 105, 50),
    pendingRow(35, 'COL', 109, 3),
];
[$sent] = runCommand($world, '/substitució');
check('un sol missatge', count($sent), 1);
checkContains('diu que ja estan tots', $sent[0]['text'], 'Ja has fet un canvi a cada competició.');
check('teclat només amb el botó de tots', $sent[0]['keyboard'], [
    ['/substitució remove', '/inici'],
]);
check('no ofereix cap equip', in_array('/out OwnedPot1', flatKeyboard($sent[0]['keyboard']), true), false);

echo "\n--- /substitució amb un pot buit d'una competició bloquejada ---\n";
$world = buildWorld([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12]);
[, , , $sub] = $world;
$sub->pending = [pendingRow(34, 'EUL', 105, 50)];
[$sent] = runCommand($world, '/substitució');
check('el pot buit d\'EUL no s\'ofereix', in_array('/out EUL_Pot_1', flatKeyboard($sent[0]['keyboard']), true), false);

echo "\n--- pendents d'una altra jornada s'ignoren ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4, MATCH_DAY - 1)];
[$sent] = runCommand($world, '/substitució');
check('no apareix cap pendent', $sent[0]['text'], 'Els teus equips:');
check('sense botó de remove', in_array('/substitució remove', flatKeyboard($sent[0]['keyboard']), true), false);

echo "\n--- /substitució remove amb un de sol (equival a tots) ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent, $sub] = runCommand($world, '/substitució remove');
check('text', $sent[0]['text'], 'Substitució eliminada');
check('id eliminat', $sub->removed, [33]);

echo "\n--- /substitució remove (els 3) ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [
    pendingRow(33, 'CHL', 101, 4),
    pendingRow(34, 'EUL', 105, 50),
    pendingRow(35, 'COL', 109, 3),
];
[$sent, $sub] = runCommand($world, '/substitució remove');
check('text', $sent[0]['text'], 'Canvis eliminats (3)');
check('ids eliminats', $sub->removed, [33, 34, 35]);

echo "\n--- un argument de més s'ignora: només hi ha esborrar-ho tot ---\n";
// No existeix l'esborrat individual (vegeu removeSubstitutions): les validacions
// es fan sobre la plantilla efectiva i treure'n un de sol podria deixar la resta
// en una combinació il·legal. Qualsevol argument s'ignora i s'ho emporta tot.
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [
    pendingRow(33, 'CHL', 101, 4),
    pendingRow(34, 'EUL', 105, 50),
];
[$sent, $sub] = runCommand($world, '/substitució remove CHL');
check('elimina els dos, no només CHL', $sent[0]['text'], 'Canvis eliminats (2)');
check('ids eliminats', $sub->removed, [33, 34]);

echo "\n  la combinació il·legal que permetia l'esborrat parcial és inaccessible\n";
// El cas exacte: amb el canvi de CHL viu, Espanya queda alliberada i el pot 5
// pot ser espanyol. Sense aquell canvi (l'estat en què quedaries si el poguessis
// esborrar de sol) el bot ho rebutja.
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent] = runCommand($world, '/in CandSpain5');
check('amb el canvi de CHL viu: es permet', $sent[0]['text'], 'Substitució guardada: OwnedPot5 -> CandSpain5 (cost: 6 punts)');

[$sent] = runCommand(buildWorld(range(1, 12)), '/in CandSpain5');
check('sense el canvi de CHL: es rebutja', $sent[0]['text'], "ERROR, ja tens un equip d'aquest país");

echo "\n--- /substitució remove sense cap pendent ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/substitució remove');
check('text', $sent[0]['text'], 'No tens cap substitució pendent');

echo "\n--- /out sobre una competició ja bloquejada ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent] = runCommand($world, '/out OwnedPot2');
check('text', $sent[0]['text'], "ERROR, ja tens un canvi pendent a CHL. Elimina'l abans de fer-ne un altre.");

echo "\n--- /out d'un pot buit d'una competició bloquejada ---\n";
$world = buildWorld([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12]);
[, , , $sub] = $world;
$sub->pending = [pendingRow(34, 'EUL', 105, 50)];
[$sent] = runCommand($world, '/out EUL_Pot_1');
check('text', $sent[0]['text'], "ERROR, ja tens un canvi pendent a EUL. Elimina'l abans de fer-ne un altre.");

echo "\n--- /out sobre un equip propi (cap pendent) ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/out OwnedPot1');
check('text', $sent[0]['text'], 'Nou equip:');
$flat = flatKeyboard($sent[0]['keyboard']);
check('candidat de país nou inclòs', in_array('/in CandA', $flat, true), true);
check('candidat del mateix país inclòs (es deixa anar el seu)', in_array('/in CandSameCountry', $flat, true), true);
check('el propi equip exclòs', in_array('/in OwnedPot1', $flat, true), false);

echo "\n--- /out sobre un equip que no és teu ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/out CandA');
check('text', $sent[0]['text'], "ERROR, este equip no és teu");

echo "\n--- /out sobre un equip inexistent ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/out NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");

echo "\n--- /out sobre un pot buit vàlid ---\n";
[$sent] = runCommand(buildWorld([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12]), '/out EUL_Pot_1');
check('text', $sent[0]['text'], 'Nou equip:');
$flat = flatKeyboard($sent[0]['keyboard']);
check('ofereix els candidats del pot 5', [in_array('/in CandPot5', $flat, true), in_array('/in CandGermany5', $flat, true)], [true, true]);

echo "\n--- /out sobre un pot buit invàlid ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/out CHL_Pot_5');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");

echo "\n--- /in omplint un forat buit ---\n";
[$sent, $sub] = runCommand(buildWorld([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12]), '/in CandPot5');
check('text', $sent[0]['text'], 'Substitució guardada: Empty -> CandPot5 (cost: 6 punts)');
check('substitució guardada', $sub->added, [[
    'playerId'    => PLAYER_ID,
    'matchDay'    => MATCH_DAY,
    'oldTeamId'   => 0,
    'newTeamId'   => 50,
    'competition' => 'EUL',
    'pointsCost'  => 6,
]]);

echo "\n--- /in canviant un equip propi ---\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12)), '/in CandA');
check('text', $sent[0]['text'], 'Substitució guardada: OwnedPot1 -> CandA (cost: 6 punts)');
check('old_team_id = el del pot', $sub->added[0]['oldTeamId'], 101);

echo "\n--- /in d'un equip que ja tens ---\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12)), '/in OwnedPot5');
check('text', $sent[0]['text'], 'ERROR, ja tens aquest equip');
check('no s\'ha guardat res', $sub->added, []);

echo "\n--- /in d'un equip del mateix país que es deixa anar ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/in CandSameCountry');
check('es permet (el país queda lliure)', $sent[0]['text'], 'Substitució guardada: OwnedPot1 -> CandSameCountry (cost: 6 punts)');

echo "\n--- /in d'un equip d'un país que ja tens en un altre pot ---\n";
$world = buildWorld(range(1, 12));
[$bot, $player, $team, $sub] = $world;
$clash = ['id' => 60, 'name' => 'CandClash', 'country' => 'France', 'competition' => 'EUL', 'pot' => 6];
$team->teamsByPot[6][]          = $clash;
$team->teamsById[60]            = $clash;
$team->teamsByName['CandClash'] = $clash;
[$sent, $sub] = runCommand($world, '/in CandClash');
check('text', $sent[0]['text'], "ERROR, ja tens un equip d'aquest país");
check('no s\'ha guardat res', $sub->added, []);

// ---------------------------------------------------------------------------
// Els dos casos que demostren per què cal la plantilla efectiva.
// Jugador amb Espanya al pot 1 (CHL) i França al pot 5 (EUL).
// ---------------------------------------------------------------------------

echo "\n--- pendent CHL: Espanya -> Alemanya ---\n";

echo "  (a) no es pot agafar Alemanya al pot 5: Alemanya ja està compromesa\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent, $sub] = runCommand($world, '/in CandGermany5');
check('text', $sent[0]['text'], "ERROR, ja tens un equip d'aquest país");
check('no s\'ha guardat res', $sub->added, []);

echo "  (b) sí que es pot agafar Espanya al pot 5: el pendent l'ha alliberada\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent, $sub] = runCommand($world, '/in CandSpain5');
check('text', $sent[0]['text'], 'Substitució guardada: OwnedPot5 -> CandSpain5 (cost: 6 punts)');
check('old_team_id = pot 5', $sub->added[0]['oldTeamId'], 105);
check('competició EUL', $sub->added[0]['competition'], 'EUL');

echo "\n--- /in en una competició ja bloquejada ---\n";
$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent, $sub] = runCommand($world, '/in CandA');
check('text', $sent[0]['text'], "ERROR, ja tens un canvi pendent a CHL. Elimina'l abans de fer-ne un altre.");
check('no s\'ha guardat res', $sub->added, []);

echo "\n--- /in d'un equip inexistent ---\n";
[$sent] = runCommand(buildWorld(range(1, 12)), '/in NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");

echo "\n--- jugador que encara no ha fet /equips ---\n";
$world = buildWorld(range(1, 12));
[$bot, $player] = $world;
$player->players = [];

foreach (['/substitució', '/substitució remove', '/out OwnedPot1', '/in CandA'] as $text) {
    [$sent] = runCommand($world, $text);
    check('cap error fatal a ' . $text, count($sent), 1);
    check('text a ' . $text, $sent[0]['text'], 'Encara no has triat cap equip. Fes /equips per començar.');
    check('teclat a ' . $text, $sent[0]['keyboard'], [['/equips', '/inici']]);
}

echo "\n--- cost del canvi: 6 punts, gratis si està a més de 29 del líder ---\n";

echo "  (a) igualat amb el líder -> costa 6\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12), [PLAYER_ID => 100, 8 => 100]), '/in CandA');
check('text', $sent[0]['text'], 'Substitució guardada: OwnedPot1 -> CandA (cost: 6 punts)');
check('points_cost', $sub->added[0]['pointsCost'], 6);

echo "  (b) a 29 punts exactes -> encara costa 6 (la regla diu MÉS de 29)\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12), [PLAYER_ID => 71, 8 => 100]), '/in CandA');
check('text', $sent[0]['text'], 'Substitució guardada: OwnedPot1 -> CandA (cost: 6 punts)');
check('points_cost', $sub->added[0]['pointsCost'], 6);

echo "  (c) a 30 punts -> gratis\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12), [PLAYER_ID => 70, 8 => 100]), '/in CandA');
check('text', $sent[0]['text'], 'Substitució guardada: OwnedPot1 -> CandA (gratis)');
check('points_cost', $sub->added[0]['pointsCost'], 0);

echo "  (d) el jugador és el líder -> costa 6\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12), [PLAYER_ID => 120, 8 => 100]), '/in CandA');
check('points_cost', $sub->added[0]['pointsCost'], 6);

echo "  (e) sense cap classificació publicada -> gratis, no s'inventa el càrrec\n";
[$sent, $sub] = runCommand(buildWorld(range(1, 12), []), '/in CandA');
check('text', $sent[0]['text'], 'Substitució guardada: OwnedPot1 -> CandA (gratis)');
check('points_cost', $sub->added[0]['pointsCost'], 0);

echo "\n--- canvis tancats a partir de les semifinals ---\n";
check('la jornada del test encara és oberta amb la config actual', LAST_MATCH_DAY_WITH_CHANGES > MATCH_DAY, true);

[$sent] = runCommand(buildWorld(range(1, 12)), '/out OwnedPot1', MATCH_DAY - 1);
check('/out queda bloquejat', $sent[0]['text'], 'Els canvis estan tancats: no es poden fer canvis a partir de les semifinals.');

[$sent, $sub] = runCommand(buildWorld(range(1, 12)), '/in CandA', MATCH_DAY - 1);
check('/in queda bloquejat', $sent[0]['text'], 'Els canvis estan tancats: no es poden fer canvis a partir de les semifinals.');
check('no s\'ha guardat res', $sub->added, []);

[$sent] = runCommand(buildWorld(range(1, 12)), '/substitució', MATCH_DAY - 1);
check('/substitució avisa que està tancat', $sent[0]['text'], 'Els canvis estan tancats: no es poden fer canvis a partir de les semifinals.');
check('sense teclat si no hi ha pendents', $sent[0]['keyboard'], null);

$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent] = runCommand($world, '/substitució', MATCH_DAY - 1);
checkContains('encara llista el pendent', $sent[0]['text'], 'Canvis pendents:');
check('encara es pot eliminar', end($sent[0]['keyboard']), ['/substitució remove', '/inici']);

$world = buildWorld(range(1, 12));
[, , , $sub] = $world;
$sub->pending = [pendingRow(33, 'CHL', 101, 4)];
[$sent, $sub] = runCommand($world, '/substitució remove', MATCH_DAY - 1);
check('es pot eliminar amb els canvis tancats', $sent[0]['text'], 'Substitució eliminada');
check('id eliminat', $sub->removed, [33]);

echo "\n--- el router discrimina bé ---\n";
check('agafa /substitució', SubstitutionAction::handlesCommand('/substitució'), true);
check('agafa /out', SubstitutionAction::handlesCommand('/out'), true);
check('agafa /in', SubstitutionAction::handlesCommand('/in'), true);
check('no agafa /equips', SubstitutionAction::handlesCommand('/equips'), false);
check('no agafa /substitution', SubstitutionAction::handlesCommand('/substitution'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
