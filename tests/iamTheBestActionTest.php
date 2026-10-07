<?php
// Prova funcional d'IAmTheBestAction amb dobles (sense BD ni Telegram).
// La lògica compartida ja la cobreix badDayActionTest.php; aquí es comprova que
// la parametrització d'aquesta acció sigui la correcta.
// S'executa des de l'arrel del projecte: php tests/iamTheBestActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/action.php");
require_once("actions/BadDayAction.php");
require_once("actions/IAmTheBestAction.php");

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
    public array $rows          = [];
    public array $added         = [];
    public array $updates       = [];
    public array $queriedTypes  = [];

    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type)
    {
        $this->queriedTypes[] = $type;

        return array_values(array_filter(
            $this->rows,
            fn($r) => $r['match_day'] === $matchDay && $r['type'] === $type
        ));
    }

    /** Totes les files del tipus, de qualsevol jornada: és el límit de temporada. */
    public function getActionsByPlayerAndType(int $playerId, string $type)
    {
        return array_values(array_filter($this->rows, fn($r) => $r['type'] === $type));
    }

    /** Totes les files de la jornada, de qualsevol tipus: el conflicte entre accions. */
    public function getActionsByPlayerAndMatchDay(int $playerId, int $matchDay)
    {
        return array_values(array_filter($this->rows, fn($r) => $r['match_day'] === $matchDay));
    }

    public function addAction(int $playerId, int $matchDay, string $type, string $data)
    {
        $this->added[] = ['type' => $type, 'data' => json_decode($data, true)];

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
}

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

function buildWorld($actionData = null, bool $activated = true): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $action = new FakeAction();

    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    if ($actionData !== null) {
        $action->rows[] = [
            'id'        => 44,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'iAmTheBest',
            'data'      => json_encode($actionData),
        ];
    }

    return [$bot, $player, $action, $activated];
}

/** Fila d'una altra jornada: és el que compta per al límit de temporada. */
function seasonRow(FakeAction $action, int $matchDay, array $data, string $type = 'iAmTheBest', int $id = 1): void
{
    $action->rows[] = [
        'id'        => $id,
        'player_id' => PLAYER_ID,
        'match_day' => $matchDay,
        'type'      => $type,
        'data'      => json_encode($data),
    ];
}

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

/** @return array [missatges enviats, repo d'accions] */
function runCommand(array $world, string $text): array
{
    [$bot, $player, $action, $activated] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new IAmTheBestAction($bot, $player, $action, CHAT_ID, MATCH_DAY, $args, $activated);
    $handler->run();

    return [$bot->sent, $action];
}

// ---------- escenaris ----------

echo "--- el router no confon les accions ---\n";
check('IAmTheBest agafa /socElMillor', IAmTheBestAction::handlesCommand('/socElMillor'), true);
check('IAmTheBest no agafa /malDia', IAmTheBestAction::handlesCommand('/malDia'), false);
check('IAmTheBest no agafa /guanyarOMorir', IAmTheBestAction::handlesCommand('/guanyarOMorir'), false);
check('BadDay no agafa /socElMillor', BadDayAction::handlesCommand('/socElMillor'), false);

echo "\n--- /socElMillor sense fila i activada ---\n";
[$sent, $action] = runCommand(buildWorld(null, true), '/socElMillor');
check('consulta el tipus iAmTheBest', $action->queriedTypes, ['iAmTheBest']);
check('text amb l\'etiqueta pròpia', $sent[0]['text'], '#socElMillor activar o desactivar:');
check('botons amb el comandament propi', $sent[0]['keyboard'], [['/socElMillor Activar CHL', '/socElMillor Activar EUL', '/socElMillor Activar COL']]);

echo "\n--- /socElMillor Activar CHL ---\n";
[$sent, $action] = runCommand(buildWorld(null, true), '/socElMillor Activar CHL');
check('crea la fila amb el tipus iAmTheBest', $action->added, [['type' => 'iAmTheBest', 'data' => ['CHL']]]);
check('el botó es gira amb el comandament propi', $sent[0]['keyboard'], [['/socElMillor Desactivar CHL', '/socElMillor Activar EUL', '/socElMillor Activar COL']]);

echo "\n--- /socElMillor amb fila: estat ---\n";
[$sent, $action] = runCommand(buildWorld(['EUL'], true), '/socElMillor');
check('text', $sent[0]['text'], "Actualment tens el #socElMillor:\n- Champions League: desactivat\n- Europa League: activat\n- Conference League: desactivat\n\nActivar o desactivar:");
check('botons girats', $sent[0]['keyboard'], [['/socElMillor Activar CHL', '/socElMillor Desactivar EUL', '/socElMillor Activar COL']]);
check('desa el mateix estat', $action->updates, [['id' => 44, 'data' => ['EUL']]]);

echo "\n--- /socElMillor Desactivar EUL ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL', 'EUL'], true), '/socElMillor Desactivar EUL');
check('desa la resta', $action->updates, [['id' => 44, 'data' => ['CHL']]]);
check('text', $sent[0]['text'], "Actualment tens el #socElMillor:\n- Champions League: activat\n- Europa League: desactivat\n- Conference League: desactivat\n\nActivar o desactivar:");

echo "\n--- /socElMillor amb l'acció apagada i sense fila ---\n";
[$sent, $action] = runCommand(buildWorld(null, false), '/socElMillor');
check('text', $sent[0]['text'], 'No disponible');
check('no crea cap fila', $action->added, []);

echo "\n--- /socElMillor amb l'acció apagada i amb fila ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL'], false), '/socElMillor');
check('mostra l\'estat', $sent[0]['text'], "Actualment tens el #socElMillor:\n- Champions League: activat\n- Europa League: desactivat\n- Conference League: desactivat\n");
check('sense teclat', $sent[0]['keyboard'], null);
check('no modifica res', $action->updates, []);

echo "\n--- límit de temporada, també per al #socElMillor ---\n";
$world = buildWorld(null, true);
[, , $action] = $world;
seasonRow($action, 1, ['CHL', 'EUL', 'COL']);   // les 3 gastades
[$sent, $action] = runCommand($world, '/socElMillor Activar CHL');
check('avisa del límit amb la seva etiqueta', str_contains($sent[0]['text'], 'Ja has fet servir el #socElMillor 3 vegades'), true);
check('i no crea cap fila', $action->added, []);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
