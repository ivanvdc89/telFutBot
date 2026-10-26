<?php
// Prova funcional de SureToBeQualifiedAction amb dobles (sense BD ni Telegram).
// S'executa des de l'arrel del projecte: php tests/sureToBeQualifiedActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/action.php");
require_once("actions/SureToBeQualifiedAction.php");

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
    $team   = new FakeTeam();
    $action = new FakeAction();

    // Per defecte el jugador va a 29 punts del líder, que són 3 punts jugats.
    $player->pointsByPlayer = $standings ?? standingsAtGap(29);
    $player->players[CHAT_ID] = ['id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => 'Tester'];

    $owned = [
        ['id' => 101, 'name' => 'OwnedPot1', 'country' => 'Spain',  'competition' => 'CHL', 'pot' => 1],
        ['id' => 105, 'name' => 'OwnedPot5', 'country' => 'France', 'competition' => 'EUL', 'pot' => 5],
    ];

    $team->teamsByPlayer[PLAYER_ID] = $owned;

    foreach ($owned as $t) {
        $team->teamsById[$t['id']]     = $t;
        $team->teamsByName[$t['name']] = $t;
    }

    if ($actionData !== null) {
        $action->rows = [[
            'id'        => 33,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'sureToBeQualified',
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

/** @return array [missatges enviats, repo d'accions] */
function runCommand(array $world, string $text, bool $activated = true): array
{
    [$bot, $player, $team, $action] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new SureToBeQualifiedAction($bot, $player, $team, $action, CHAT_ID, MATCH_DAY, $args, $activated);
    $handler->run();

    return [$bot->sent, $action];
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(), '/segurQuePasse', false);
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- jugador sense fitxa ---\n";
$world = buildWorld();
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/segurQuePasse');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Toca "Equips" per començar.');
check('teclat', $sent[0]['keyboard'], [['/equips', '/inici']]);

echo "\n--- /segurQuePasse sense argument: els teus equips ---\n";
[$sent, $action] = runCommand(buildWorld(), '/segurQuePasse');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], '#segurQuePasse activar amb quin equip:');
check('teclat amb els dos equips', $sent[0]['keyboard'], [['/passe OwnedPot1', '/passe OwnedPot5']]);
check('no ha creat cap fila', $action->added, []);

echo "\n--- /segurQuePasse OwnedPot1 sense fila prèvia ---\n";
[$sent, $action] = runCommand(buildWorld(), '/segurQuePasse OwnedPot1');
check('text', $sent[0]['text'], 'Has activat el #segurQuePasse amb el OwnedPot1' . "\n");
check('ha creat la fila amb equip i punts', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'sureToBeQualified',
    'data'     => ['team' => 101, 'points' => 3],
]]);

echo "\n  els punts que s'aposten surten de la classificació general\n";
[$sent, $action] = runCommand(buildWorld(null, true, standingsAtGap(9)), '/segurQuePasse OwnedPot5');
check('a 9 punts del líder -> 1 punt', $action->added[0]['data'], ['team' => 105, 'points' => 1]);

[$sent, $action] = runCommand(buildWorld(null, true, standingsAtGap(30)), '/segurQuePasse OwnedPot5');
check('a 30 punts -> 4 punts (màxim)', $action->added[0]['data'], ['team' => 105, 'points' => 4]);

echo "\n--- /passe és el mateix comandament ---\n";
[$sent, $action] = runCommand(buildWorld(), '/passe OwnedPot5');
check('text', $sent[0]['text'], 'Has activat el #segurQuePasse amb el OwnedPot5' . "\n");
check('equip desat', $action->added[0]['data'], ['team' => 105, 'points' => 3]);

echo "\n--- amb una aposta feta es mostra i s'ofereix treure-la ---\n";
[$sent] = runCommand(buildWorld(['team' => 105, 'points' => 3]), '/segurQuePasse');
check('text', $sent[0]['text'], "Actualment tens activat el #segurQuePasse amb el OwnedPot5\n");
check('teclat per treure-la', $sent[0]['keyboard'], [['/segurQuePasse Borrar']]);

echo "\n  les files antigues ([teamId]) també es llegeixen\n";
[$sent] = runCommand(buildWorld([101]), '/segurQuePasse');
check('text', $sent[0]['text'], "Actualment tens activat el #segurQuePasse amb el OwnedPot1\n");

echo "\n--- amb una aposta feta, un altre equip no la canvia ---\n";
// Per canviar d'equip primer s'ha de treure l'aposta: és el que feia el codi
// original, i el bot ho diu mostrant la que hi ha.
[$sent, $action] = runCommand(buildWorld(['team' => 101, 'points' => 3]), '/segurQuePasse OwnedPot5');
check('mostra l\'aposta que hi ha', $sent[0]['text'], "Actualment tens activat el #segurQuePasse amb el OwnedPot1\n");
check('i no escriu res', $action->updates, []);
check('ni crea cap fila', $action->added, []);

echo "\n  amb la fila buida (aposta treta) es torna a omplir la mateixa\n";
[$sent, $action] = runCommand(buildWorld([]), '/segurQuePasse OwnedPot1');
check('actualitza la fila', $action->updates, [
    ['id' => 33, 'data' => ['team' => 101, 'points' => 3]],
]);
check('no ha creat cap fila', $action->added, []);

echo "\n--- /segurQuePasse Borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['team' => 101, 'points' => 3]), '/segurQuePasse Borrar');
check('buida la fila', $action->updates, [['id' => 33, 'data' => []]]);
check('i mostra els equips per triar', $sent[0]['text'], '#segurQuePasse activar amb quin equip:');
check('teclat', $sent[0]['keyboard'], [['/passe OwnedPot1', '/passe OwnedPot5']]);

echo "\n--- /segurQuePasse d'un equip que no existeix ---\n";
[$sent, $action] = runCommand(buildWorld(), '/segurQuePasse NoExisteix');
check('text', $sent[0]['text'], "ERROR, l'equip no existeix");
check('no s\'ha desat res', $action->added, []);

echo "\n--- fila buida sense argument: mostra el menú ---\n";
[$sent, $action] = runCommand(buildWorld([]), '/segurQuePasse');
check('text', $sent[0]['text'], '#segurQuePasse activar amb quin equip:');
check('no escriu res', $action->updates, []);

echo "\n--- el router discrimina bé ---\n";
check('agafa /segurQuePasse', SureToBeQualifiedAction::handlesCommand('/segurQuePasse'), true);
check('agafa /passe', SureToBeQualifiedAction::handlesCommand('/passe'), true);
check('no agafa /kos', SureToBeQualifiedAction::handlesCommand('/kos'), false);
check('no agafa /millorFinal', SureToBeQualifiedAction::handlesCommand('/millorFinal'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
