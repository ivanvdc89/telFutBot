<?php
// Prova funcional de KosPotsAction (/kosPots) amb dobles, sense BD ni Telegram.
// S'executa des de l'arrel del projecte: php tests/kosPotsActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/action.php");
require_once("actions/KosPotsAction.php");

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

class FakeTeam extends Team
{
    public array $teamsByPlayer = [];
    public array $teamsByName   = [];
    public array $teamsById     = [];

    public function getTeamsByPlayerId($playerId)
    {
        return $this->teamsByPlayer[$playerId] ?? [];
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
            'id'        => 54,
            'player_id' => $playerId,
            'match_day' => $matchDay,
            'type'      => $type,
            'data'      => $data,
        ];

        return 54;
    }
}

// ---------- fixtures ----------

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

function ownedTeams(): array
{
    return [
        ['id' => 101, 'name' => 'OwnedPot1', 'country' => 'Spain',   'competition' => 'CHL', 'pot' => 1],
        ['id' => 105, 'name' => 'OwnedPot5', 'country' => 'France',  'competition' => 'EUL', 'pot' => 5],
        ['id' => 109, 'name' => 'OwnedPot9', 'country' => 'Germany', 'competition' => 'COL', 'pot' => 9],
    ];
}

function buildWorld(
    ?array $actionData = ['max' => 3, 'pots' => [], 'shields' => []],
    bool $activated = true,
    string $activeKos = 'kosPots',
    int $gap = 29
): array {
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $team   = new FakeTeam();
    $action = new FakeAction();

    // 1 vot el líder i 1 més per cada 10 punts de diferència: 29 -> 3 vots.
    $player->pointsByPlayer = [PLAYER_ID => 100 - $gap, 8 => 100];
    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    $owned = ownedTeams();
    $team->teamsByPlayer[PLAYER_ID] = $owned;

    foreach ($owned as $t) {
        $team->teamsById[$t['id']]     = $t;
        $team->teamsByName[$t['name']] = $t;
    }

    if ($actionData !== null) {
        $action->rows = [[
            'id'        => 53,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'kosPots',
            'data'      => json_encode($actionData),
        ]];
    }

    return [$bot, $player, $team, $action, $activated, $activeKos];
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

function runCommand(array $world, string $text): array
{
    [$bot, $player, $team, $action, $activated, $activeKos] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new KosPotsAction($bot, $player, $team, $action, CHAT_ID, MATCH_DAY, $args, $activated, $activeKos);
    $handler->run();

    return [$bot->sent, $action];
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(null, false), '/kosPots');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- el boicot actiu és un altre ---\n";
[$sent, $action] = runCommand(buildWorld(null, true, 'kosPais'), '/kosPots');
check('contesta que no', $sent[0]['text'], 'No disponible');
check('i no crea cap fila', $action->added, []);

echo "\n--- activada i sense fila: el bot la crea ---\n";
[$sent, $action] = runCommand(buildWorld(null), '/kosPots');
check('ha creat la fila amb el màxim i les llistes buides', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'kosPots',
    'data'     => ['max' => 3, 'pots' => [], 'shields' => []],
]]);
check('i mostra el menú', $sent[0]['text'], 'Pots disponibles:');

echo "\n--- /kosPots: els pots i els escuts ---\n";
[$sent] = runCommand(buildWorld(), '/kosPots');
check('text', $sent[0]['text'], 'Pots disponibles:');
check('quatre files de botons', count($sent[0]['keyboard']), 4);
check('els pots de quatre en quatre', $sent[0]['keyboard'][0], [
    '/kosPots vot CHL_1',
    '/kosPots vot CHL_2',
    '/kosPots vot CHL_3',
    '/kosPots vot CHL_4',
]);
check('l\'última fila és la dels escuts', $sent[0]['keyboard'][3], [
    '/kosPots escut CHL',
    '/kosPots escut EUL',
    '/kosPots escut COL',
    '/kosPots',
]);

echo "\n--- /kosPots vot CHL_1 ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPots vot CHL_1');
check('dos missatges', count($sent), 2);
check('primer: vot guardat', $sent[0]['text'], 'Vot guardat');
check('segon: la llista amb l\'etiqueta del pot', $sent[1]['text'], "Vots màxims: 3\nPots votats:\n- CHL 1\n\nEscuts:\n\nEt queden 2 vots de KO pendents d'usar.\nEt queden 3 escuts pendents de posar.");
check('s\'ha desat el pot global', $action->updates, [[
    'id'   => 53,
    'data' => ['max' => 3, 'pots' => [1], 'shields' => []],
]]);

echo "\n--- es pot votar el mateix pot dos cops ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'pots' => [1], 'shields' => []]), '/kosPots vot CHL_1');
check('el segon vot també es desa', $action->updates, [[
    'id'   => 53,
    'data' => ['max' => 3, 'pots' => [1, 1], 'shields' => []],
]]);

echo "\n--- el pot 5 es mostra amb la seua competició ---\n";
[$sent] = runCommand(buildWorld(['max' => 3, 'pots' => [5], 'shields' => []]), '/kosPots vot CHL_2');
check('la llista diu EUL 1', str_contains($sent[1]['text'] ?? '', '- EUL 1'), true);

echo "\n--- /kosPots vot d'un pot que no existeix ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPots vot 13');
check('text', $sent[0]['text'], 'ERROR, pot invàlid');
check('no s\'ha desat res', $action->updates, []);

[$sent, $action] = runCommand(buildWorld(), '/kosPots vot');
check('sense número també falla', $sent[0]['text'], 'ERROR, pot invàlid');
check('i no desa res', $action->updates, []);

echo "\n--- /kosPots vot amb tots els vots fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 1, 'pots' => [1], 'shields' => []], true, 'kosPots', 9), '/kosPots vot CHL_2');
check('avisa que només queden escuts', $sent[0]['text'], 'Ja has fet el màxim de vots de pots, ara pots posar escuts si vols:');
check('i ofereix els escuts', $sent[0]['keyboard'][0], ['/kosPots escut CHL', '/kosPots escut EUL', '/kosPots escut COL', '/kosPots']);

echo "\n--- /kosPots escut CHL ---\n";
[$sent] = runCommand(buildWorld(), '/kosPots escut CHL');
check('text', $sent[0]['text'], 'Els teus equips per a posar escut:');
check('teclat', $sent[0]['keyboard'], [['/kosPots escut OwnedPot1']]);

echo "\n--- /kosPots escut OwnedPot1 ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPots escut OwnedPot1');
check('dos missatges', count($sent), 2);
check('primer: escut guardat', $sent[0]['text'], 'Escut guardat');
check('segon: la llista amb l\'escut', $sent[1]['text'], "Vots màxims: 3\nPots votats:\n\nEscuts:\n- OwnedPot1\n\nEt queden 3 vots de KO pendents d'usar.\nEt queden 2 escuts pendents de posar.");
check('s\'ha desat l\'escut', $action->updates, [['id' => 53, 'data' => ['max' => 3, 'pots' => [], 'shields' => [101]]]]);

echo "\n--- /kosPots borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'pots' => [1], 'shields' => [101]]), '/kosPots borrar');
check('text', $sent[0]['text'], 'Vots i escuts esborrats');
check('teclat', $sent[0]['keyboard'], [['/kosPots', '/inici']]);
check('esborra vots i escuts i conserva el max', $action->updates, [
    ['id' => 53, 'data' => ['max' => 3, 'pots' => [], 'shields' => []]],
]);

echo "\n--- /kosPots amb tots els vots i escuts fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'pots' => [1, 5], 'shields' => [101, 105]], true, 'kosPots', 19), '/kosPots');
check('dos missatges', count($sent), 2);
check('llista els pots', $sent[0]['text'], "Vots màxims: 2\nPots votats:\n- CHL 1\n- EUL 1\n\nEscuts:\n- OwnedPot1\n- OwnedPot5\n");
check('avisa que ja està', $sent[1]['text'], 'Ja tens tots els vots fets');
check('botó per esborrar', $sent[1]['keyboard'], [['/kosPots borrar', '/inici']]);

echo "\n--- jugador sense fitxa ---\n";
$world = buildWorld();
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/kosPots');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Toca "Equips" per començar.');

echo "\n--- el router discrimina bé ---\n";
check('agafa /kosPots', KosPotsAction::handlesCommand('/kosPots'), true);
check('no agafa /kos', KosPotsAction::handlesCommand('/kos'), false);
check('no agafa /kosPais', KosPotsAction::handlesCommand('/kosPais'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
