<?php
// Prova funcional de BadDayAction amb dobles (sense BD ni Telegram).
// S'executa des de l'arrel del projecte: php tests/badDayActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/action.php");
require_once("actions/BadDayAction.php");

use TelegramBot\Api\BotApi;

class FakeBot extends BotApi
{
    public array $sent = [];

    public function __construct()
    {
        parent::__construct('test-token');
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

class FakeAction extends Action
{
    public array $rows    = [];
    public array $added   = [];
    public array $updates = [];

    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type)
    {
        return $this->byMatchDayAndType($matchDay, $type);
    }

    /** Totes les files del tipus, de qualsevol jornada: és el límit de temporada. */
    public function getActionsByPlayerAndType(int $playerId, string $type)
    {
        return array_values(array_filter($this->rows, fn($r) => $r['type'] === $type));
    }

    public function addAction(int $playerId, int $matchDay, string $type, string $data)
    {
        $this->added[] = ['playerId' => $playerId, 'matchDay' => $matchDay, 'type' => $type, 'data' => json_decode($data, true)];

        $id           = 100 + count($this->rows);
        $this->rows[] = ['id' => $id, 'player_id' => $playerId, 'match_day' => $matchDay, 'type' => $type, 'data' => $data];

        return $id;
    }

    public function updateAction(int $id, string $data)
    {
        $this->updates[] = ['id' => $id, 'data' => json_decode($data, true)];

        foreach ($this->rows as $i => $row) {
            if ($row['id'] === $id) {
                $this->rows[$i]['data'] = $data;
            }
        }
    }

    private function byMatchDayAndType(int $matchDay, string $type): array
    {
        return array_values(array_filter(
            $this->rows,
            fn($r) => $r['match_day'] === $matchDay && $r['type'] === $type
        ));
    }
}

// ---------- fixtures ----------

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

/**
 * Crea el món amb una sola fila d'acció (o cap, si $actionData és null).
 * Per provar files duplicades, afegiu-les després a $action->rows.
 */
function buildWorld($actionData = null, bool $activated = true): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $action = new FakeAction();

    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    if ($actionData !== null) {
        $action->rows[] = [
            'id'        => 33,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'badDay',
            'data'      => json_encode($actionData),
        ];
    }

    return [$bot, $player, $action, $activated];
}

/**
 * Afegeix una fila d'una altra jornada, que és el que compta per al límit de
 * temporada. Per defecte del mateix tipus que l'acció que s'està provant.
 */
function seasonRow(FakeAction $action, int $matchDay, array $data, string $type = 'badDay', int $id = 1): void
{
    $action->rows[] = [
        'id'        => $id,
        'player_id' => PLAYER_ID,
        'match_day' => $matchDay,
        'type'      => $type,
        'data'      => json_encode($data),
    ];
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

/** @return array [missatges enviats, repo d'accions] */
function runCommand(array $world, string $text): array
{
    [$bot, $player, $action, $activated] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new BadDayAction($bot, $player, $action, CHAT_ID, MATCH_DAY, $args, $activated);
    $handler->run();

    return [$bot->sent, $action];
}

// ---------- escenaris ----------

echo "--- jugador sense fitxa ---\n";
$world = buildWorld(null, true);
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/malDia');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Fes /equips per començar.');
check('teclat', $sent[0]['keyboard'], [['/equips', '/inici']]);

echo "\n--- sense fila i amb l'acció desactivada ---\n";
[$sent, $action] = runCommand(buildWorld(null, false), '/malDia');
check('text', $sent[0]['text'], 'No disponible');
check('no crea cap fila', $action->added, []);

[$sent, $action] = runCommand(buildWorld(null, false), '/malDia Activar CHL');
check('ni tan sols activant', $sent[0]['text'], 'No disponible');
check('no crea cap fila', $action->added, []);

echo "\n--- sense fila i activada: menú ---\n";
[$sent, $action] = runCommand(buildWorld(null, true), '/malDia');
check('text', $sent[0]['text'], '#malDia activar o desactivar:');
check('botons per activar', $sent[0]['keyboard'], [['/malDia Activar CHL', '/malDia Activar EUL', '/malDia Activar COL']]);
check('no crea res sense demanar activar', $action->added, []);

echo "\n--- sense fila i activada: /malDia Activar CHL ---\n";
[$sent, $action] = runCommand(buildWorld(null, true), '/malDia Activar CHL');
check('text', $sent[0]['text'], '#malDia activar o desactivar:');
check('crea la fila amb CHL', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'badDay',
    'data'     => ['CHL'],
]]);
check('el botó de CHL es gira', $sent[0]['keyboard'], [['/malDia Desactivar CHL', '/malDia Activar EUL', '/malDia Activar COL']]);

echo "\n--- amb fila i amb l'acció desactivada: només es mostra l'estat ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL'], false), '/malDia');
check('text', $sent[0]['text'], "Actualment tens el #malDia:\n- Champions League: activat\n- Europa League: desactivat\n- Conference League: desactivat\n");
check('sense teclat', $sent[0]['keyboard'], null);
check('no modifica res', $action->updates, []);

[$sent, $action] = runCommand(buildWorld(['CHL'], false), '/malDia Activar EUL');
check('activar tampoc no fa res amb l\'acció apagada', $action->updates, []);

echo "\n--- amb fila i activada: es mostra l'estat i els botons ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL'], true), '/malDia');
check('text', $sent[0]['text'], "Actualment tens el #malDia:\n- Champions League: activat\n- Europa League: desactivat\n- Conference League: desactivat\n\nActivar o desactivar:");
check('botons girats', $sent[0]['keyboard'], [['/malDia Desactivar CHL', '/malDia Activar EUL', '/malDia Activar COL']]);
check('desa el mateix estat', $action->updates, [['id' => 33, 'data' => ['CHL']]]);

echo "\n--- /malDia Activar EUL sobre CHL ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL'], true), '/malDia Activar EUL');
check('desa les dues', $action->updates, [['id' => 33, 'data' => ['CHL', 'EUL']]]);
check('text amb les dues activades', $sent[0]['text'], "Actualment tens el #malDia:\n- Champions League: activat\n- Europa League: activat\n- Conference League: desactivat\n\nActivar o desactivar:");
check('els dos botons girats', $sent[0]['keyboard'], [['/malDia Desactivar CHL', '/malDia Desactivar EUL', '/malDia Activar COL']]);

echo "\n--- /malDia Desactivar CHL sobre CHL+EUL ---\n";
// array_diff conserva les claus, així que el JSON desat queda {\"1\":\"EUL\"} en
// lloc de [\"EUL\"]. Funciona perquè tots els consumidors recorren la llista,
// però és un wart: un array_values ho normalitzaria.
[$sent, $action] = runCommand(buildWorld(['CHL', 'EUL'], true), '/malDia Desactivar CHL');
check('desa la resta amb les claus originals', $action->updates, [['id' => 33, 'data' => [1 => 'EUL']]]);
check('text', $sent[0]['text'], "Actualment tens el #malDia:\n- Champions League: desactivat\n- Europa League: activat\n- Conference League: desactivat\n\nActivar o desactivar:");

echo "\n--- /malDia Activar CHL repetit ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL'], true), '/malDia Activar CHL');
check('array_unique evita el duplicat', $action->updates, [['id' => 33, 'data' => ['CHL']]]);

echo "\n--- /malDia Activar COL: la tercera competició ---\n";
// Fins ara el teclat només oferia CHL i EUL, tot i que les normes i el motor de
// punts ja contemplaven la Conference.
[$sent, $action] = runCommand(buildWorld(null, true), '/malDia Activar COL');
check('crea la fila amb COL', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'badDay',
    'data'     => ['COL'],
]]);
check('els tres botons, amb el de COL girat', $sent[0]['keyboard'], [
    ['/malDia Activar CHL', '/malDia Activar EUL', '/malDia Desactivar COL'],
]);

[$sent] = runCommand(buildWorld(['COL'], true), '/malDia');
check('estat amb la Conference activada', $sent[0]['text'], "Actualment tens el #malDia:\n- Champions League: desactivat\n- Europa League: desactivat\n- Conference League: activat\n\nActivar o desactivar:");
check('el botó de COL es gira cap enrere', $sent[0]['keyboard'], [['/malDia Activar CHL', '/malDia Activar EUL', '/malDia Desactivar COL']]);

echo "\n--- /malDia Desactivar COL ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL', 'EUL', 'COL'], true), '/malDia Desactivar COL');
check('desactiva només la Conference', $action->updates, [['id' => 33, 'data' => ['CHL', 'EUL']]]);
check('text', $sent[0]['text'], "Actualment tens el #malDia:\n- Champions League: activat\n- Europa League: activat\n- Conference League: desactivat\n\nActivar o desactivar:");

echo "\n--- més d'una fila a la base de dades ---\n";
// No hauria de passar. El codi original no mirava el commutador en aquest cas.
$world = buildWorld(['CHL'], false);
[, , $action] = $world;
$action->rows[] = [
    'id'        => 34,
    'player_id' => PLAYER_ID,
    'match_day' => MATCH_DAY,
    'type'      => 'badDay',
    'data'      => json_encode(['EUL']),
];
[$sent, $action] = runCommand($world, '/malDia');
check('dues files al fixture', count($world[2]->rows), 2);
check('mostra el menú', $sent[0]['text'], '#malDia activar o desactivar:');
check('amb botons per activar', $sent[0]['keyboard'], [['/malDia Activar CHL', '/malDia Activar EUL', '/malDia Activar COL']]);
check('i sense tocar res', $action->updates, []);

echo "\n--- límit de temporada: 3 vegades per acció ---\n";

echo "  (a) amb les 3 gastades, activar-ne una altra es rebutja\n";
$world = buildWorld(null, true);
[, , $action] = $world;
seasonRow($action, 1, ['CHL', 'EUL', 'COL']);   // les 3 a la jornada 1
[$sent, $action] = runCommand($world, '/malDia Activar CHL');
check('un sol missatge', count($sent), 1);
checkContains('avisa del límit', $sent[0]['text'], 'Ja has fet servir el #malDia 3 vegades aquesta temporada');
check('no crea cap fila', $action->added, []);

echo "  (b) amb 2 gastades, la tercera sí que es permet\n";
$world = buildWorld(null, true);
[, , $action] = $world;
seasonRow($action, 1, ['CHL', 'EUL']);
[$sent, $action] = runCommand($world, '/malDia Activar COL');
check('crea la tercera', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'badDay',
    'data'     => ['COL'],
]]);

echo "  (c) el límit és per acció: les del #socElMillor no compten\n";
$world = buildWorld(null, true);
[, , $action] = $world;
seasonRow($action, 1, ['CHL', 'EUL'], 'badDay');
seasonRow($action, 1, ['CHL', 'EUL', 'COL'], 'iAmTheBest', 2);   // 3, però d'una altra acció
[$sent, $action] = runCommand($world, '/malDia Activar COL');
check('permet la tercera del badDay', count($action->added), 1);

echo "  (d) seqüència completa: rebutja, desactiva i permet\n";
$world = buildWorld(['CHL', 'EUL'], true);   // fila de la jornada en curs
[, , $action] = $world;
seasonRow($action, 1, ['COL']);              // 2 + 1 = 3 gastades
[$sent, $action] = runCommand($world, '/malDia Activar COL');
checkContains('la quarta es rebutja', $sent[0]['text'], 'Ja has fet servir el #malDia 3 vegades');
check('i no escriu absolutament res', $action->updates, []);

[, $action] = runCommand($world, '/malDia Desactivar CHL');
check('desactivar sí que es permet', $action->updates[0]['data'], [1 => 'EUL']);

[, $action] = runCommand($world, '/malDia Activar COL');
check('i ara ja hi cap', end($action->updates)['data'], [1 => 'EUL', 2 => 'COL']);

echo "  (e) amb les 3 gastades, qualsevol Activar falla, encara que ja estigui activat\n";
$world = buildWorld(['CHL'], true);
[, , $action] = $world;
seasonRow($action, 1, ['EUL', 'COL']);       // 1 + 2 = 3
[$sent, $action] = runCommand($world, '/malDia Activar CHL');
check('avisa del límit', $sent[0]['text'] !== '' && str_contains($sent[0]['text'], 'Ja has fet servir el #malDia 3 vegades'), true);
check('i tot seguit mostra l\'estat, per poder-ne desactivar alguna', count($sent), 2);
checkContains('el segon missatge és l\'estat', $sent[1]['text'], 'Actualment tens el');
check('i no escriu res', $action->updates, []);

echo "  (f) la jornada en curs compta per al límit\n";
// Les 3 activades a la jornada que s'està jugant: l'acció ja no en pot fer més.
$world = buildWorld(['CHL', 'EUL', 'COL'], true);
[, , $action] = $world;
[$sent, $action] = runCommand($world, '/malDia Activar CHL');
check('avisa del límit', $sent[0]['text'] !== '' && str_contains($sent[0]['text'], 'Ja has fet servir el #malDia 3 vegades'), true);
check('i no escriu res', $action->updates, []);

echo "\n--- el router discrimina bé ---\n";
check('agafa /malDia', BadDayAction::handlesCommand('/malDia'), true);
check('no agafa /socElMillor', BadDayAction::handlesCommand('/socElMillor'), false);
check('no agafa /rules', BadDayAction::handlesCommand('/rules'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
