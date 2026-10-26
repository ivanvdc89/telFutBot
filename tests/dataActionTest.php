<?php
// Prova funcional de DataAction (/data) amb dobles, sense BD ni Telegram.
// Cobreix el menú, els pots, la llista de participants i les plantilles, a més
// del pas de pots que /data comparteix amb /equips i /add.
// S'executa des de l'arrel del projecte: php tests/dataActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/pots.php");
require_once("config/settings.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("actions/CommandAction.php");
require_once("actions/DataAction.php");

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

    public function getAllPlayers()
    {
        return $this->players;
    }

    public function getPlayerByName($name)
    {
        return array_values(array_filter($this->players, function ($player) use ($name) {
            return $player['name'] === $name;
        }));
    }
}

class FakeTeam extends Team
{
    /** @var array [pot => files de getCountPlayerTeamsByPot] */
    public array $countsByPot = [];

    /** @var array [team_id => files de jugadors] */
    public array $playersByTeam = [];

    /** @var array [player_id => equips] */
    public array $teamsByPlayer = [];

    public function getCountPlayerTeamsByPot($pot)
    {
        return $this->countsByPot[$pot] ?? [];
    }

    public function getPlayersByTeam($teamId)
    {
        return $this->playersByTeam[$teamId] ?? [];
    }

    public function getTeamsByPlayerId($playerId)
    {
        return $this->teamsByPlayer[$playerId] ?? [];
    }
}

const CHAT_ID = 555;

/**
 * Tres jugadors, un d'ells amb nom compost, i un pot 3 amb dos equips.
 *
 * @return array [bot, repo de jugadors, repo d'equips]
 */
function buildWorld(): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $team   = new FakeTeam();

    $player->players = [
        ['id' => 7, 'chat_id' => 555, 'name' => 'Ivan'],
        ['id' => 8, 'chat_id' => 556, 'name' => 'Marta'],
        ['id' => 9, 'chat_id' => 557, 'name' => 'Ivan Arrachina'],
    ];

    $team->countsByPot = [
        3 => [
            ['total' => 2, 'name' => 'Barça', 'id' => 31],
            ['total' => 1, 'name' => 'Ajax', 'id' => 32],
        ],
    ];
    $team->playersByTeam = [
        31 => [['name' => 'Ivan'], ['name' => 'Marta']],
        32 => [['name' => 'Ivan Arrachina']],
    ];
    $team->teamsByPlayer = [
        7 => [
            ['id' => 31, 'name' => 'Barça', 'competition' => 'CHL', 'pot' => 1],
            ['id' => 35, 'name' => 'Roma', 'competition' => 'EUL', 'pot' => 5],
        ],
        8 => [
            ['id' => 39, 'name' => 'Betis', 'competition' => 'COL', 'pot' => 9],
        ],
        9 => [],
    ];

    return [$bot, $player, $team];
}

/** @return array [missatges enviats, repo de jugadors, repo d'equips] */
function runCommand(array $world, string $text): array
{
    [$bot, $player, $team] = $world;
    $bot->sent = [];

    (new DataAction($bot, $player, $team, CHAT_ID, explode(' ', $text)))->run();

    return [$bot->sent, $player, $team];
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

// ---------- el router ----------

echo "--- el router reconeix /data ---\n";
check('/data', DataAction::handlesCommand('/data'), true);
check('no agafa /data2', DataAction::handlesCommand('/data2'), false);
check('no agafa /nom', DataAction::handlesCommand('/nom'), false);
check('i no agafa /normes', DataAction::handlesCommand('/normes'), false);

// ---------- la taula de pots, compartida amb /equips i /add ----------

echo "\n--- els pots de la temporada (config/pots.php) ---\n";
check('els 12 pots', POTS, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
check('el pot global 5 és el pot 1 de la competició', POT_NUMBERS[5], 1);
check('el pot global 12 és el pot 4 de la competició', POT_NUMBERS[12], 4);
check('el pot 1 és de la Champions', POT_COMPETITIONS[1], 'CHL');
check('el pot 5 és de l\'Europa League', POT_COMPETITIONS[5], 'EUL');
check('el pot 9 és de la Conference', POT_COMPETITIONS[9], 'COL');

// ---------- menú ----------

echo "\n--- /data ---\n";
[$sent] = runCommand(buildWorld(), '/data');
check('text', $sent[0]['text'], 'Data disponible:');
check('teclat', $sent[0]['keyboard'], [
    ['/data pots', '/data players'],
    ['/classificacio'],
]);

// ---------- pots ----------

echo "\n--- /data pots ---\n";
[$sent] = runCommand(buildWorld(), '/data pots');
check('text', $sent[0]['text'], 'Pots disponibles:');
check('un botó per pot, en tres files', $sent[0]['keyboard'], [
    ['/data pot CHL_1', '/data pot CHL_2', '/data pot CHL_3', '/data pot CHL_4'],
    ['/data pot EUL_1', '/data pot EUL_2', '/data pot EUL_3', '/data pot EUL_4'],
    ['/data pot COL_1', '/data pot COL_2', '/data pot COL_3', '/data pot COL_4'],
]);

echo "\n--- /data pot CHL_3 ---\n";
[$sent] = runCommand(buildWorld(), '/data pot CHL_3');
check(
    'quants jugadors té cada equip i qui són',
    $sent[0]['text'],
    "Equips del pot CHL 3:\n2 Barça\n   - Ivan\n   - Marta\n\n1 Ajax\n   - Ivan Arrachina\n\n"
);
check('amb el botó per tornar a tots els pots', $sent[0]['keyboard'], [['/data pots']]);

echo "\n--- /data pot amb números que no existeixen ---\n";
foreach (['/data pot 13' => 'fora de rang', '/data pot 0' => 'zero', '/data pot' => 'sense número', '/data pot abc' => 'no numèric'] as $text => $label) {
    [$sent] = runCommand(buildWorld(), $text);
    check($label, $sent[0]['text'], 'ERROR, pot number not found');
}

// ---------- participants ----------

echo "\n--- /data players ---\n";
[$sent] = runCommand(buildWorld(), '/data players');
check('text', $sent[0]['text'], 'Jugadors participants:');
check('botons de tres en tres, amb "all" al davant', $sent[0]['keyboard'], [
    ['dp:all', 'dp:7', 'dp:8'],
    ['dp:9'],
]);

// ---------- plantilles ----------

echo "\n--- /data player all ---\n";
[$sent] = runCommand(buildWorld(), '/data player all');
check(
    'la plantilla de tothom, amb el pot dins de la competició',
    $sent[0]['text'],
    "Tots els jugadors:\n\nIvan:\nBarça (CHL Pot 1)\nRoma (EUL Pot 1)\n\n\nMarta:\nBetis (COL Pot 1)\n\n\nIvan Arrachina:\n"
);

echo "\n--- /data player Ivan ---\n";
[$sent] = runCommand(buildWorld(), '/data player Ivan');
check('només els seus equips', $sent[0]['text'], "Ivan:\nBarça (CHL Pot 1)\nRoma (EUL Pot 1)\n");

echo "\n--- /data player amb nom compost ---\n";
[$sent] = runCommand(buildWorld(), '/data player Ivan Arrachina');
check('troba el jugador de dues paraules', $sent[0]['text'], "Ivan Arrachina:\n");

[$sent] = runCommand(buildWorld(), '/data player Ivan  Arrachina');
check('i amb espais de més', $sent[0]['text'], "Ivan Arrachina:\n");

echo "\n--- /data player que no existeix ---\n";
[$sent] = runCommand(buildWorld(), '/data player Nope');
check('avisa', $sent[0]['text'], 'ERROR, player not found');

[$sent] = runCommand(buildWorld(), '/data player');
check('i sense nom també', $sent[0]['text'], 'ERROR, player not found');

// ---------- subcomandaments ----------

echo "\n--- /data amb un subcomandament desconegut ---\n";
[$sent] = runCommand(buildWorld(), '/data foo');
check('avisa', $sent[0]['text'], 'ERROR, data command not found');

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
