<?php
// Prova funcional de BestFinalAction amb dobles (sense BD ni Telegram).
// S'executa des de l'arrel del projecte: php tests/bestFinalActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/action.php");
require_once("actions/BestFinalAction.php");

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
            $inline = json_decode($replyMarkup->toJson(), true)['inline_keyboard'] ?? null;

            if ($inline !== null) {
                // Per a les proves, "keyboard" és el comandament que executaria
                // cada botó: així les comprovacions existents continuen dient el
                // mateix que deien amb els teclats de resposta.
                $keyboard = array_map(function ($row) {
                    return array_map(function ($button) {
                        $data = $button['callback_data'];

                        return str_starts_with($data, 'c:') ? substr($data, 2) : $data;
                    }, $row);
                }, $inline);
            }
        }
        $this->sent[] = ['text' => $text, 'keyboard' => $keyboard];

        return null;
    }
}

class FakePlayer extends Player
{
    public array $players = [];

    /** @var array [player_id => punts acumulats], com a players.points */
    public array $pointsByPlayer = [];

    public function getPlayerByChatId($chatId)
    {
        return isset($this->players[$chatId]) ? [$this->players[$chatId]] : [];
    }

    public function getPlayersPoints(): array
    {
        return $this->pointsByPlayer;
    }
}

/**
 * Repositori d'accions que es comporta com la taula: el que s'hi desa, s'hi
 * torna a llegir. BestFinalAction rellegeix la fila després d'escriure-hi.
 */
class FakeAction extends Action
{
    public array $rows    = [];
    public array $updates = [];
    public array $added   = [];

    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type)
    {
        return $this->rows;
    }

    public function updateAction(int $id, string $data)
    {
        $this->updates[] = ['id' => $id, 'data' => json_decode($data, true)];

        foreach ($this->rows as $index => $row) {
            if ((int) $row['id'] === $id) {
                $this->rows[$index]['data'] = $data;
            }
        }
    }

    public function addAction(int $playerId, int $matchDay, string $type, string $data)
    {
        $this->added[] = [
            'playerId' => $playerId,
            'matchDay' => $matchDay,
            'type'     => $type,
            'data'     => json_decode($data, true),
        ];

        $this->rows[] = [
            'id'        => 34,
            'player_id' => $playerId,
            'match_day' => $matchDay,
            'type'      => $type,
            'data'      => $data,
        ];
    }
}

// ---------- fixtures ----------

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

/**
 * Classificació general on el jugador va $gap punts del líder.
 * El segon jugador (8) fa de líder amb 100 punts.
 */
function standingsAtGap(int $gap): array
{
    return [PLAYER_ID => 100 - $gap, 8 => 100];
}

function buildWorld(
    ?array $actionData = null,
    bool $activated = true,
    ?array $standings = null
): array {
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $action = new FakeAction();

    // Per defecte el jugador va a 29 punts del líder, que són 3 vots.
    $player->pointsByPlayer = $standings ?? standingsAtGap(29);
    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    if ($actionData !== null) {
        $action->rows = [[
            'id'        => 33,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'bestFinal',
            'data'      => json_encode($actionData),
        ]];
    }

    return [$bot, $player, $action];
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

/** @return array [missatges enviats, repo d'accions] */
function runCommand(array $world, string $text, bool $activated = true): array
{
    [$bot, $player, $action] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new BestFinalAction($bot, $player, $action, CHAT_ID, MATCH_DAY, $args, $activated);
    $handler->run();

    return [$bot->sent, $action];
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(), '/millorFinal', false);
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- jugador sense fitxa ---\n";
$world = buildWorld();
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/millorFinal');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Toca "Equips" per començar.');
check('teclat', $sent[0]['keyboard'], [['/equips', '/inici']]);

echo "\n--- /millorFinal sense argument: menú de finals ---\n";
[$sent, $action] = runCommand(buildWorld(), '/millorFinal');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], '#millorFinal:');
check('teclat', $sent[0]['keyboard'], [['/millorFinal CHL', '/millorFinal EUL', '/millorFinal COL']]);
check('no ha creat cap fila', $action->added, []);

echo "\n--- /millorFinal CHL sense fila prèvia ---\n";
[$sent, $action] = runCommand(buildWorld(), '/millorFinal CHL');
check('ha creat la fila amb la competició i els vots', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'bestFinal',
    'data'     => ['competition' => 'CHL', 'points' => 3],
]]);
check('i mostra el vot amb el botó de treure\'l', $sent[0]['text'], "\nMillorFinal: CHL\n");
check('teclat', $sent[0]['keyboard'], [['/millorFinal borrar']]);

echo "\n  el pes del vot surt de la classificació general\n";
[$sent, $action] = runCommand(buildWorld(null, true, standingsAtGap(9)), '/millorFinal EUL');
check('a 9 punts del líder -> 1 vot', $action->added[0]['data'], ['competition' => 'EUL', 'points' => 1]);

[$sent, $action] = runCommand(buildWorld(null, true, standingsAtGap(30)), '/millorFinal COL');
check('a 30 punts -> 4 vots (màxim)', $action->added[0]['data'], ['competition' => 'COL', 'points' => 4]);

echo "\n--- amb un vot fet es mostra la final triada ---\n";
[$sent] = runCommand(buildWorld(['competition' => 'EUL', 'points' => 3]), '/millorFinal');
check('text', $sent[0]['text'], "\nMillorFinal: EUL\n");
check('teclat', $sent[0]['keyboard'], [['/millorFinal borrar']]);

echo "\n  les files antigues (['CHL']) també es llegeixen\n";
[$sent] = runCommand(buildWorld(['CHL']), '/millorFinal');
check('text', $sent[0]['text'], "\nMillorFinal: CHL\n");

echo "\n--- canviar de final amb una fila ja creada ---\n";
[$sent, $action] = runCommand(buildWorld(['competition' => 'CHL', 'points' => 3]), '/millorFinal COL');
check('actualitza la fila en lloc de crear-ne una', $action->updates, [
    ['id' => 33, 'data' => ['competition' => 'COL', 'points' => 3]],
]);
check('no ha creat cap fila', $action->added, []);
check('i mostra la nova tria', $sent[0]['text'], "\nMillorFinal: COL\n");

echo "\n--- /millorFinal borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['competition' => 'CHL', 'points' => 3]), '/millorFinal borrar');
check('buida la fila', $action->updates, [['id' => 33, 'data' => []]]);
check('i mostra el menú', $sent[0]['text'], '#millorFinal:');
check('teclat', $sent[0]['keyboard'], [['/millorFinal CHL', '/millorFinal EUL', '/millorFinal COL']]);

echo "\n  borrar sense cap fila no escriu res\n";
[$sent, $action] = runCommand(buildWorld(), '/millorFinal borrar');
check('cap escriptura', $action->updates, []);
check('i mostra el menú', $sent[0]['text'], '#millorFinal:');

echo "\n--- argument desconegut ---\n";
[$sent, $action] = runCommand(buildWorld(['competition' => 'CHL', 'points' => 3]), '/millorFinal Quarts');
check('es mostra la tria que hi ha', $sent[0]['text'], "\nMillorFinal: CHL\n");
check('i no escriu res', $action->updates, []);

[$sent, $action] = runCommand(buildWorld(), '/millorFinal Quarts');
check('sense fila, mostra el menú', $sent[0]['text'], '#millorFinal:');
check('i no escriu res', $action->added, []);

echo "\n--- el router discrimina bé ---\n";
check('agafa /millorFinal', BestFinalAction::handlesCommand('/millorFinal'), true);
check('no agafa /kos', BestFinalAction::handlesCommand('/kos'), false);
check('no agafa /segurQuePasse', BestFinalAction::handlesCommand('/segurQuePasse'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
