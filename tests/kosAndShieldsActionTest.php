<?php
// Prova funcional de KosAndShieldsAction amb dobles (sense BD ni Telegram).
// S'executa des de l'arrel del projecte: php tests/kosAndShieldsActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/action.php");
require_once("actions/KosAndShieldsAction.php");

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
            'id'        => 34,
            'player_id' => $playerId,
            'match_day' => $matchDay,
            'type'      => $type,
            'data'      => $data,
        ];

        return 34;
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

function ownedTeams(): array
{
    return [
        ['id' => 101, 'name' => 'OwnedPot1', 'country' => 'Spain',   'competition' => 'CHL', 'pot' => 1],
        ['id' => 105, 'name' => 'OwnedPot5', 'country' => 'France',  'competition' => 'EUL', 'pot' => 5],
        ['id' => 109, 'name' => 'OwnedPot9', 'country' => 'Germany', 'competition' => 'COL', 'pot' => 9],
    ];
}

function buildWorld(
    ?array $actionData = ['max' => 3, 'kos' => [], 'shields' => []],
    bool $activated = true,
    ?array $standings = null
): array {
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $team   = new FakeTeam();
    $action = new FakeAction();

    // Per defecte el jugador va a 29 punts del líder, que són 3 vots: 1 + 2.
    $player->pointsByPlayer = $standings ?? standingsAtGap(29);
    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    $owned = ownedTeams();
    $team->teamsByPlayer[PLAYER_ID] = $owned;

    foreach ($owned as $t) {
        $team->teamsById[$t['id']]     = $t;
        $team->teamsByName[$t['name']] = $t;
        $team->teamsByPot[$t['pot']][] = $t;
    }

    $candidates = [
        ['id' => 10, 'name' => 'EquipA', 'country' => 'Italy',   'competition' => 'CHL', 'pot' => 3],
        ['id' => 11, 'name' => 'EquipB', 'country' => 'Spain',   'competition' => 'CHL', 'pot' => 3],
        ['id' => 12, 'name' => 'EquipC', 'country' => 'Germany', 'competition' => 'CHL', 'pot' => 3],
    ];

    foreach ($candidates as $t) {
        $team->teamsByPot[$t['pot']][] = $t;
        $team->teamsById[$t['id']]     = $t;
        $team->teamsByName[$t['name']] = $t;
    }

    if ($actionData !== null) {
        $action->rows = [[
            'id'        => 33,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'kosAndShields',
            'data'      => json_encode($actionData),
        ]];
    }

    return [$bot, $player, $team, $action];
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
function runCommand(array $world, string $text, bool $activated = true, string $activeKos = 'kosAndShields'): array
{
    [$bot, $player, $team, $action] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new KosAndShieldsAction($bot, $player, $team, $action, CHAT_ID, MATCH_DAY, $args, $activated, $activeKos);
    $handler->run();

    return [$bot->sent, $action];
}

function flatKeyboard(?array $keyboard): array
{
    return $keyboard === null ? [] : array_merge(...$keyboard);
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(), '/kos', false);
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- el boicot actiu és un altre ---\n";
[$sent, $action] = runCommand(buildWorld(null), '/kos', true, 'kosPais');
check('contesta que no', $sent[0]['text'], 'No disponible');
check('i no crea cap fila', $action->added, []);

echo "\n--- activada i sense fila: el bot la crea ---\n";
// Abans calia que algú la sembrés a mà a la base de dades; si faltava, l'acció
// responia "No disponible".
[$sent, $action] = runCommand(buildWorld(null), '/kos');
check('ha creat la fila amb el màxim i les llistes buides', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'kosAndShields',
    'data'     => ['max' => 3, 'kos' => [], 'shields' => []],
]]);
check('i mostra el menú', $sent[0]['text'], 'Pots disponibles:');
check('amb les 4 files de botons', count($sent[0]['keyboard']), 4);
check('sense dependre de cap fila prèvia', $action->updates, []);

echo "\n  i el jugador ja hi pot votar de seguida\n";
[$sent, $action] = runCommand(buildWorld(null), '/kos ko_vot EquipA');
check('text', $sent[0]['text'], 'Vot guardat');
check('el vot va a la fila nova', $action->updates, [[
    'id'   => 34,
    'data' => ['max' => 3, 'kos' => [10], 'shields' => []],
]]);

echo "\n--- jugador sense fitxa ---\n";
$world = buildWorld();
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/kos');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Toca "Equips" per començar.');
check('teclat', $sent[0]['keyboard'], [['/equips', '/inici']]);

echo "\n--- /kos: menú de pots i escuts ---\n";
[$sent, $action] = runCommand(buildWorld(['kos' => [], 'shields' => []]), '/kos');
check('un sol missatge (el max ja hi és)', count($sent), 1);
check('text', $sent[0]['text'], 'Pots disponibles:');
check('4 files de botons', count($sent[0]['keyboard']), 4);
check('primera fila', $sent[0]['keyboard'][0], ['/kos ko_pot CHL_1', '/kos ko_pot CHL_2', '/kos ko_pot CHL_3', '/kos ko_pot CHL_4']);
check('última fila', $sent[0]['keyboard'][3], ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos']);

echo "\n  el màxim de vots surt de la classificació general\n";
[$sent, $action] = runCommand(buildWorld(['kos' => [], 'shields' => []]), '/kos');
check('el bot calcula i desa el màxim', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'kos' => [], 'shields' => []]]]);

$world = buildWorld(['kos' => [], 'shields' => []], true, standingsAtGap(9));
[$sent, $action] = runCommand($world, '/kos');
check('a 9 punts del líder -> 1 vot', $action->updates[0]['data']['max'] ?? null, 1);

echo "\n--- /kos ko_pot CHL_3 ---\n";
[$sent] = runCommand(buildWorld(), '/kos ko_pot CHL_3');
check('text', $sent[0]['text'], 'Equips del pot CHL 3:');
check('teclat', $sent[0]['keyboard'], [['/kos ko_vot EquipA', '/kos ko_vot EquipB', '/kos ko_vot EquipC']]);

echo "\n--- /kos ko_pot invàlid ---\n";
[$sent] = runCommand(buildWorld(), '/kos ko_pot 13');
check('fora de rang', $sent[0]['text'], 'ERROR, pot invàlid');
[$sent] = runCommand(buildWorld(), '/kos ko_pot');
check('sense número', $sent[0]['text'], 'ERROR, pot invàlid');

echo "\n--- /kos ko_vot EquipA ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'kos' => [], 'shields' => []]), '/kos ko_vot EquipA');
check('dos missatges', count($sent), 2);
check('primer: vot guardat', $sent[0]['text'], 'Vot guardat');
check('segon: la llista i el que falta', $sent[1]['text'], "Vots màxims: 3\nEquips votats:\n- EquipA\n\nEscuts:\n\nEt queden 2 vots de KO pendents d'usar.\nEt queden 3 escuts pendents de posar.");
check('s\'ha desat el vot', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'kos' => [10], 'shields' => []]]]);

echo "\n--- /kos ko_vot d'un equip que no existeix ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kos ko_vot NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");
check('no s\'ha desat res', $action->updates, []);

echo "\n--- /kos ko_vot amb tots els kos fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 1, 'kos' => [10], 'shields' => []], true, standingsAtGap(9)), '/kos ko_vot EquipB');
check('avisa que només queden escuts', $sent[0]['text'], 'Ja has fet el màxim de vots de kos, ara pots posar escuts si vols:');
check('i ofereix els escuts', flatKeyboard($sent[0]['keyboard']), ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos']);

echo "\n--- /kos escut CHL: els equips propis de la competició ---\n";
[$sent] = runCommand(buildWorld(), '/kos escut CHL');
check('text', $sent[0]['text'], 'Els teus equips per a posar escut:');
check('teclat', $sent[0]['keyboard'], [['/kos escut OwnedPot1']]);

[$sent] = runCommand(buildWorld(['max' => 3, 'kos' => [], 'shields' => [101]]), '/kos escut CHL');
check('l\'equip que ja té escut no s\'ofereix', $sent[0]['keyboard'], null);

echo "\n--- /kos escut OwnedPot1 ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kos escut OwnedPot1');
check('dos missatges', count($sent), 2);
check('primer: escut guardat', $sent[0]['text'], 'Escut guardat');
check('segon: la llista amb l\'escut i el que falta', $sent[1]['text'], "Vots màxims: 3\nEquips votats:\n\nEscuts:\n- OwnedPot1\n\nEt queden 3 vots de KO pendents d'usar.\nEt queden 2 escuts pendents de posar.");
check('s\'ha desat l\'escut', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'kos' => [], 'shields' => [101]]]]);

echo "\n--- /kos escut d'un equip que ja té escut ---\n";
$world = buildWorld(['max' => 3, 'kos' => [], 'shields' => [101]]);
[$sent, $action] = runCommand($world, '/kos escut OwnedPot1');
check('text', $sent[0]['text'], "ERROR, l'equip ja té l'escut activat");
check('no s\'ha desat res', $action->updates, []);

echo "\n--- /kos escut d'un equip que no existeix ---\n";
[$sent] = runCommand(buildWorld(), '/kos escut NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");

echo "\n--- /kos borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'kos' => [10], 'shields' => [101]]), '/kos borrar');
check('text', $sent[0]['text'], 'Vots i escuts esborrats');
check('teclat', $sent[0]['keyboard'], [['/kos', '/inici']]);
check('esborra kos i escuts i conserva el max', $action->updates, [
    ['id' => 33, 'data' => ['max' => 3, 'kos' => [], 'shields' => []]],
]);

echo "\n--- /kos amb tots els vots i escuts fets ---\n";
$world = buildWorld(['max' => 2, 'kos' => [10, 11], 'shields' => [101, 105]], true, standingsAtGap(19));
[$sent] = runCommand($world, '/kos');
check('dos missatges', count($sent), 2);
checkContains('llista els kos', $sent[0]['text'], "- EquipA\n- EquipB\n");
checkContains('i els escuts', $sent[0]['text'], "Escuts:\n- OwnedPot1\n- OwnedPot5\n");
check('avisa que ja està', $sent[1]['text'], 'Ja tens tots els vots fets');
check('botó per esborrar', $sent[1]['keyboard'], [['/kos borrar', '/inici']]);

echo "\n  amb tot fet, \"ko_pot\" no mostra equips\n";
$world = buildWorld(['max' => 2, 'kos' => [10, 11], 'shields' => [101, 105]], true, standingsAtGap(19));
[$sent] = runCommand($world, '/kos ko_pot CHL_3');
check('text', $sent[1]['text'], 'Ja tens tots els vots fets');

echo "\n  i amb els escuts al màxim, \"escut CHL\" tampoc no mostra equips\n";
$world = buildWorld(['max' => 2, 'kos' => [], 'shields' => [101, 105]], true, standingsAtGap(19));
[$sent] = runCommand($world, '/kos escut CHL');
check('text', $sent[0]['text'], "Ja has posat el màxim d'escuts, ara pots votar els kos si vols:");

echo "\n--- subcomandament desconegut ---\n";
[$sent] = runCommand(buildWorld(), '/kos aixòNoExisteix');
check('mostra el menú en lloc de caure al missatge global', $sent[0]['text'], 'Pots disponibles:');

echo "\n--- fila sense 'kos' ni 'shields' ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3]), '/kos');
check('es completa la fila amb les dues llistes', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'kos' => [], 'shields' => []]]]);
check('i funciona igual', $sent[0]['text'], 'Pots disponibles:');

echo "\n--- el router discrimina bé ---\n";
check('agafa /kos', KosAndShieldsAction::handlesCommand('/kos'), true);
check('no agafa /segurQuePasse', KosAndShieldsAction::handlesCommand('/segurQuePasse'), false);
check('no agafa /millorFinal', KosAndShieldsAction::handlesCommand('/millorFinal'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
