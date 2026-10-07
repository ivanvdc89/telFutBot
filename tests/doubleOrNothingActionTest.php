<?php
// Prova funcional de DoubleOrNothingAction amb dobles (sense BD ni Telegram).
// S'executa des de l'arrel del projecte: php tests/doubleOrNothingActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/action.php");
require_once("actions/DoubleOrNothingAction.php");

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

class FakeTeam extends Team
{
    public array $teamsByPot  = [];
    public array $teamsByName = [];
    public array $teamsById   = [];

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

    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type)
    {
        return $this->rows;
    }

    public function updateAction(int $id, string $data)
    {
        $this->updates[] = ['id' => $id, 'data' => json_decode($data, true)];
    }
}

// ---------- fixtures ----------

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

function buildWorld(?array $actionData = ['max' => 3, 'teams' => []], bool $activated = true): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $team   = new FakeTeam();
    $action = new FakeAction();

    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    $pot3 = [
        ['id' => 10, 'name' => 'EquipA', 'country' => 'Italy',   'competition' => 'CHL', 'pot' => 3],
        ['id' => 11, 'name' => 'EquipB', 'country' => 'Spain',   'competition' => 'CHL', 'pot' => 3],
        ['id' => 12, 'name' => 'EquipC', 'country' => 'Germany', 'competition' => 'CHL', 'pot' => 3],
    ];
    foreach ($pot3 as $t) {
        $team->teamsByPot[3][]          = $t;
        $team->teamsById[$t['id']]      = $t;
        $team->teamsByName[$t['name']]  = $t;
    }

    if ($actionData !== null) {
        $action->rows = [[
            'id'       => 33,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'     => 'doubleOrNothing',
            'data'     => json_encode($actionData),
        ]];
    }

    return [$bot, $player, $team, $action, $activated];
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
function runCommand(array $world, string $text): array
{
    [$bot, $player, $team, $action, $activated] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new DoubleOrNothingAction($bot, $player, $team, $action, CHAT_ID, MATCH_DAY, $args, $activated);
    $handler->run();

    return [$bot->sent, $action];
}

function flatKeyboard(?array $keyboard): array
{
    return $keyboard === null ? [] : array_merge(...$keyboard);
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(['max' => 3, 'teams' => []], false), '/dobleORes');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- activada però sense fila a la base de dades ---\n";
[$sent] = runCommand(buildWorld(null, true), '/dobleORes');
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- jugador sense fitxa ---\n";
$world = buildWorld(['max' => 3, 'teams' => []], true);
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/dobleORes');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Fes /equips per començar.');
check('teclat', $sent[0]['keyboard'], [['/equips', '/inici']]);

echo "\n--- /dobleORes sense argument: menú de pots ---\n";
[$sent] = runCommand(buildWorld(), '/dobleORes');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'Pots disponibles:');
check('3 files de 4 botons', count($sent[0]['keyboard']), 3);
check('primera fila', $sent[0]['keyboard'][0], ['/dobleORes pot 1', '/dobleORes pot 2', '/dobleORes pot 3', '/dobleORes pot 4']);
check('última fila', $sent[0]['keyboard'][2], ['/dobleORes pot 9', '/dobleORes pot 10', '/dobleORes pot 11', '/dobleORes pot 12']);

echo "\n--- /dobleORes pot 3 ---\n";
[$sent] = runCommand(buildWorld(), '/dobleORes pot 3');
check('text', $sent[0]['text'], 'Equips del pot 3:');
check('teclat', $sent[0]['keyboard'], [['/dobleORes vot EquipA', '/dobleORes vot EquipB', '/dobleORes vot EquipC']]);

echo "\n--- /dobleORes pot invàlid ---\n";
[$sent] = runCommand(buildWorld(), '/dobleORes pot 13');
check('fora de rang', $sent[0]['text'], 'ERROR, pot invàlid');
[$sent] = runCommand(buildWorld(), '/dobleORes pot');
check('sense número', $sent[0]['text'], 'ERROR, pot invàlid');

echo "\n--- /dobleORes vot EquipA ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'teams' => []]), '/dobleORes vot EquipA');
check('dos missatges', count($sent), 2);
check('primer: vot guardat', $sent[0]['text'], 'Vot guardat');
check('teclat del vot', $sent[0]['keyboard'], [['/dobleORes', '/inici']]);
check('segon: llista de vots', $sent[1]['text'], "Vots màxims: 3\nEquipts votats:\n- EquipA\n");
check('s\'ha desat el vot', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => [10]]]]);

echo "\n--- /dobleORes vot d'un equip que no existeix ---\n";
[$sent, $action] = runCommand(buildWorld(), '/dobleORes vot NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");
check('no s\'ha desat res', $action->updates, []);

echo "\n--- /dobleORes borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'teams' => [10, 11]]), '/dobleORes borrar');
check('text', $sent[0]['text'], 'Vots borrats');
check('teclat', $sent[0]['keyboard'], [['/dobleORes', '/inici']]);
check('esborra els equips i conserva el max', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => []]]]);

echo "\n--- /dobleORes amb tots els vots fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11]]), '/dobleORes');
check('dos missatges', count($sent), 2);
check('llista els vots', $sent[0]['text'], "Vots màxims: 2\nEquipts votats:\n- EquipA\n- EquipB\n");
check('avisa que ja està', $sent[1]['text'], 'Ja tens tots els vots fets');
check('botó per esborrar', $sent[1]['keyboard'], [['/dobleORes borrar', '/inici']]);

echo "\n  l'ordre dels guards es conserva\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11]]), '/dobleORes pot 3');
check('amb els vots fets, "pot 3" no mostra equips', $sent[0]['text'], "Vots màxims: 2\nEquipts votats:\n- EquipA\n- EquipB\n");

[$sent, $action] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11]]), '/dobleORes borrar');
check('però "borrar" sí que funciona', $sent[0]['text'], 'Vots borrats');
check('i esborra', $action->updates[0]['data']['teams'], []);

echo "\n--- subcomandament desconegut ---\n";
[$sent] = runCommand(buildWorld(), '/dobleORes aixòNoExisteix');
check('mostra el menú en lloc de caure al missatge global', $sent[0]['text'], 'Pots disponibles:');

echo "\n--- fila sense 'max' (el bloquejador conegut) ---\n";
[$sent] = runCommand(buildWorld(['teams' => []]), '/dobleORes');
check('es considera completa perquè max val 0', $sent[1]['text'], 'Ja tens tots els vots fets');
check('i la llista surt sense número', $sent[0]['text'], "Vots màxims: \nEquipts votats:\n");

echo "\n--- el router discrimina bé ---\n";
check('agafa /dobleORes', DoubleOrNothingAction::handlesCommand('/dobleORes'), true);
check('no agafa /kos', DoubleOrNothingAction::handlesCommand('/kos'), false);
check('no agafa /dobleORes borrar', DoubleOrNothingAction::handlesCommand('/dobleORes borrar'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
