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
    public array $added   = [];

    /** Per provar què passa quan la BD no desa el vot. */
    public bool $failUpdates = false;
    public bool $failInserts = false;

    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type)
    {
        return $this->rows;
    }

    public function updateAction(int $id, string $data)
    {
        if ($this->failUpdates) {
            return false;
        }

        $this->updates[] = ['id' => $id, 'data' => json_decode($data, true)];

        return true;
    }

    public function addAction(int $playerId, int $matchDay, string $type, string $data)
    {
        if ($this->failInserts) {
            return false;
        }

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

function buildWorld(
    ?array $actionData = ['max' => 3, 'teams' => []],
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

/**
 * Els vots que el bot calcula per a un coixí concret amb el líder.
 *
 * Surt de la fila que DoubleOrNothingAction desa quan el 'max' de la fila no
 * coincideix amb la classificació general.
 */
function allowedVotesForGap(int $gap): int
{
    [, $action] = runCommand(buildWorld(['teams' => []], true, standingsAtGap($gap)), '/dobleORes');

    return $action->updates[0]['data']['max'];
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(['max' => 3, 'teams' => []], false), '/dobleORes');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- activada i sense fila: el bot la crea ---\n";
// Abans calia que algú la sembrés a mà a la base de dades; si faltava, l'acció
// responia "No disponible".
[$sent, $action] = runCommand(buildWorld(null), '/dobleORes');
check('ha creat la fila amb el màxim i cap vot', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'doubleOrNothing',
    'data'     => ['max' => 3, 'teams' => []],
]]);
check('i mostra el menú de pots', $sent[0]['text'], 'Pots disponibles:');
check('sense dependre de cap fila prèvia', $action->updates, []);

echo "\n  i el jugador ja hi pot votar de seguida\n";
[$sent, $action] = runCommand(buildWorld(null), '/dobleORes vot EquipA');
check('text', $sent[0]['text'], 'Vot guardat');
check('el vot va a la fila nova', $action->updates, [[
    'id'   => 34,
    'data' => ['max' => 3, 'teams' => [10]],
]]);

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
check('segon: llista de vots', $sent[1]['text'], "Vots màxims: 3\nEquips votats:\n- EquipA\n");
check('s\'ha desat el vot', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => [10]]]]);

echo "\n--- /dobleORes vot d'un equip que no existeix ---\n";
[$sent, $action] = runCommand(buildWorld(), '/dobleORes vot NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");
check('no s\'ha desat res', $action->updates, []);

echo "\n--- /dobleORes borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'teams' => [10, 11]]), '/dobleORes borrar');
check('text', $sent[0]['text'], 'Vots esborrats');
check('teclat', $sent[0]['keyboard'], [['/dobleORes', '/inici']]);
check('esborra els equips i conserva el max', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => []]]]);

echo "\n--- /dobleORes amb tots els vots fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11]], true, standingsAtGap(19)), '/dobleORes');
check('dos missatges', count($sent), 2);
check('llista els vots', $sent[0]['text'], "Vots màxims: 2\nEquips votats:\n- EquipA\n- EquipB\n");
check('avisa que ja està', $sent[1]['text'], 'Ja tens tots els vots fets');
check('botó per esborrar', $sent[1]['keyboard'], [['/dobleORes borrar', '/inici']]);

echo "\n  l'ordre dels guards es conserva\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11]], true, standingsAtGap(19)), '/dobleORes pot 3');
check('amb els vots fets, "pot 3" no mostra equips', $sent[0]['text'], "Vots màxims: 2\nEquips votats:\n- EquipA\n- EquipB\n");

[$sent, $action] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11]], true, standingsAtGap(19)), '/dobleORes borrar');
check('però "borrar" sí que funciona', $sent[0]['text'], 'Vots esborrats');
check('i esborra', $action->updates[0]['data']['teams'], []);

echo "\n--- subcomandament desconegut ---\n";
[$sent] = runCommand(buildWorld(), '/dobleORes aixòNoExisteix');
check('mostra el menú en lloc de caure al missatge global', $sent[0]['text'], 'Pots disponibles:');

echo "\n--- fila sense 'max': el calcula la classificació general ---\n";
// Abans això era el bloquejador conegut: sense 'max' a la fila (el valor que
// algú havia de sembrar a mà) l'acció es donava per completa i no es podia
// votar. Ara surt de players.points.
[$sent, $action] = runCommand(buildWorld(['teams' => []]), '/dobleORes');
check('el bot calcula i desa el màxim', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => []]]]);
check('i el jugador pot votar', $sent[0]['text'], 'Pots disponibles:');

echo "\n  un 'max' desfasat es corregeix\n";
[$sent, $action] = runCommand(buildWorld(['max' => 1, 'teams' => []]), '/dobleORes');
check('passa de 1 a 3 vots', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => []]]]);

echo "\n  una fila amb data buit o JSON trencat es reconstrueix\n";
foreach ([['data' => null, 'label' => 'data NULL'], ['data' => 'això no és JSON', 'label' => 'JSON trencat']] as $case) {
    $world = buildWorld(null);
    $world[3]->rows = [[
        'id'        => 33,
        'player_id' => PLAYER_ID,
        'match_day' => MATCH_DAY,
        'type'      => 'doubleOrNothing',
        'data'      => $case['data'],
    ]];

    [$sent, $action] = runCommand($world, '/dobleORes');
    check($case['label'] . ': es desa la fila sencera', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => []]]]);
    check($case['label'] . ': es pot votar', $sent[0]['text'], 'Pots disponibles:');
}

echo "\n  una fila amb el max correcte però sense 'teams' es completa\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3]), '/dobleORes');
check('es desa amb la llista buida', $action->updates, [['id' => 33, 'data' => ['max' => 3, 'teams' => []]]]);
check('i es pot votar', $sent[0]['text'], 'Pots disponibles:');

echo "\n  una fila amb més vots que el màxim no en deixa fer més\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'teams' => [10, 11, 12]], true, standingsAtGap(19)), '/dobleORes');
check('es tracta com a completa', $sent[1]['text'], 'Ja tens tots els vots fets');

echo "\n--- si la BD no desa el vot, no es diu que s'ha guardat ---\n";
$world = buildWorld(['max' => 3, 'teams' => []]);
$world[3]->failUpdates = true;
[$sent, $action] = runCommand($world, '/dobleORes vot EquipA');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], "ERROR, no s'ha pogut guardar. Torna-ho a provar.");
check('cap escriptura', $action->updates, []);

echo "\n--- si no es pot crear la fila, tampoc es diu que s'ha guardat ---\n";
$world = buildWorld(null);
$world[3]->failInserts = true;
[$sent, $action] = runCommand($world, '/dobleORes');
check('text', $sent[0]['text'], "ERROR, no s'ha pogut guardar. Torna-ho a provar.");
check('no ha creat cap fila', $action->added, []);

echo "\n--- el pot ha de ser un enter de l'1 al 12 ---\n";
[$sent] = runCommand(buildWorld(), '/dobleORes pot 3.5');
check('un decimal no val', $sent[0]['text'], 'ERROR, pot invàlid');
[$sent] = runCommand(buildWorld(), '/dobleORes pot 1e1');
check('una notació científica no val', $sent[0]['text'], 'ERROR, pot invàlid');
[$sent] = runCommand(buildWorld(), '/dobleORes pot 1');
check('un pot buit ho diu', $sent[0]['text'], 'ERROR, no hi ha equips en el pot 1');

echo "\n  si ja coincideix no s'escriu res\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'teams' => []]), '/dobleORes');
check('cap escriptura', $action->updates, []);

echo "\n--- regla de posició: 1 vot el líder i 1 més cada 10 punts, màxim 4 ---\n";
check('el líder -> 1', allowedVotesForGap(0), 1);
check('a 9 punts -> 1', allowedVotesForGap(9), 1);
check('a 10 punts -> 2', allowedVotesForGap(10), 2);
check('a 19 punts -> 2', allowedVotesForGap(19), 2);
check('a 29 punts -> 3', allowedVotesForGap(29), 3);
check('a 30 punts -> 4', allowedVotesForGap(30), 4);
check('a 100 punts -> 4 (màxim)', allowedVotesForGap(100), 4);

echo "\n  sense cap classificació calculada tothom té el mínim\n";
[$sent, $action] = runCommand(buildWorld(['teams' => []], true, []), '/dobleORes');
check('1 vot', $action->updates, [['id' => 33, 'data' => ['max' => 1, 'teams' => []]]]);

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
