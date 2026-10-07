<?php
// Prova funcional de WinOrDieAction amb dobles (sense BD ni Telegram).
// La lògica compartida ja la cobreix badDayActionTest.php; aquí es comprova que
// la parametrització d'aquesta acció sigui la correcta.
// S'executa des de l'arrel del projecte: php tests/winOrDieActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/action.php");
require_once("actions/BadDayAction.php");
require_once("actions/IAmTheBestAction.php");
require_once("actions/WinOrDieAction.php");

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
    public array $rows         = [];
    public array $added        = [];
    public array $updates      = [];
    public array $queriedTypes = [];

    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type)
    {
        $this->queriedTypes[] = $type;

        return $this->rows;
    }

    public function addAction(int $playerId, int $matchDay, string $type, string $data)
    {
        $this->added[] = ['type' => $type, 'data' => json_decode($data, true)];

        return 1;
    }

    public function updateAction(int $id, string $data)
    {
        $this->updates[] = ['id' => $id, 'data' => json_decode($data, true)];
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
            'id'        => 55,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'winOrDie',
            'data'      => json_encode($actionData),
        ];
    }

    return [$bot, $player, $action, $activated];
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

    $handler = new WinOrDieAction($bot, $player, $action, CHAT_ID, MATCH_DAY, $args, $activated);
    $handler->run();

    return [$bot->sent, $action];
}

// ---------- escenaris ----------

echo "--- les tres accions de la família no es confonen ---\n";
check('WinOrDie agafa /guanyarOMorir', WinOrDieAction::handlesCommand('/guanyarOMorir'), true);
check('WinOrDie no agafa /malDia', WinOrDieAction::handlesCommand('/malDia'), false);
check('WinOrDie no agafa /socElMillor', WinOrDieAction::handlesCommand('/socElMillor'), false);
check('BadDay no agafa /guanyarOMorir', BadDayAction::handlesCommand('/guanyarOMorir'), false);
check('IAmTheBest no agafa /guanyarOMorir', IAmTheBestAction::handlesCommand('/guanyarOMorir'), false);

echo "\n--- /guanyarOMorir sense fila i activada ---\n";
[$sent, $action] = runCommand(buildWorld(null, true), '/guanyarOMorir');
check('consulta el tipus winOrDie', $action->queriedTypes, ['winOrDie']);
check('text amb l\'etiqueta pròpia', $sent[0]['text'], '#guanyarOMorir activar o desactivar:');
check('botons amb el comandament propi', $sent[0]['keyboard'], [['/guanyarOMorir Activar CHL', '/guanyarOMorir Activar EUL']]);
check('no crea res sense demanar activar', $action->added, []);

echo "\n--- /guanyarOMorir Activar EUL ---\n";
[$sent, $action] = runCommand(buildWorld(null, true), '/guanyarOMorir Activar EUL');
check('crea la fila amb el tipus winOrDie', $action->added, [['type' => 'winOrDie', 'data' => ['EUL']]]);
check('el botó es gira', $sent[0]['keyboard'], [['/guanyarOMorir Activar CHL', '/guanyarOMorir Desactivar EUL']]);

echo "\n--- /guanyarOMorir amb fila: estat ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL'], true), '/guanyarOMorir');
check('text', $sent[0]['text'], "Actualment tens el #guanyarOMorir:\n- Champions League: activat\n- Europa League: desactivat\n\nActivar o desactivar:");
check('botons girats', $sent[0]['keyboard'], [['/guanyarOMorir Desactivar CHL', '/guanyarOMorir Activar EUL']]);
check('desa el mateix estat', $action->updates, [['id' => 55, 'data' => ['CHL']]]);

echo "\n--- /guanyarOMorir Desactivar CHL ---\n";
[$sent, $action] = runCommand(buildWorld(['CHL', 'EUL'], true), '/guanyarOMorir Desactivar CHL');
check('desa la resta', $action->updates, [['id' => 55, 'data' => [1 => 'EUL']]]);
check('text', $sent[0]['text'], "Actualment tens el #guanyarOMorir:\n- Champions League: desactivat\n- Europa League: activat\n\nActivar o desactivar:");

echo "\n--- /guanyarOMorir amb l'acció apagada ---\n";
[$sent, $action] = runCommand(buildWorld(null, false), '/guanyarOMorir');
check('sense fila: no disponible', $sent[0]['text'], 'No disponible');
check('no crea cap fila', $action->added, []);

[$sent, $action] = runCommand(buildWorld(['CHL'], false), '/guanyarOMorir');
check('amb fila: mostra l\'estat', $sent[0]['text'], "Actualment tens el #guanyarOMorir:\n- Champions League: activat\n- Europa League: desactivat\n");
check('sense teclat', $sent[0]['keyboard'], null);
check('no modifica res', $action->updates, []);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
