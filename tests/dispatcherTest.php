<?php

/**
 * Prova del router (Dispatcher) i de les accions que van eixir de basic.php:
 * /inici, /accions, /equips i /add.
 *
 * No toca base de dades ni Telegram: els repositoris i el bot són dobles. El
 * que es comprova és que cada comandament arribe a l'acció que li toca i que
 * els botons que ixen siguen els mateixos comandaments que després executarà
 * el router dels callbacks.
 *
 *   php tests/dispatcherTest.php
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/matchDay.php");
require_once("config/actions.php");
require_once("config/pots.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");
require_once("models/action.php");
require_once("models/matchDayPlayerPoint.php");
require_once("actions/Dispatcher.php");

use TelegramBot\Api\BotApi;

class FakeBot extends BotApi
{
    public array $sent   = [];
    public array $edited = [];

    private int $nextMessageId = 100;

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
        $json = $replyMarkup !== null ? json_decode($replyMarkup->toJson(), true) : null;

        $this->sent[] = [
            'text'     => $text,
            'keyboard' => self::commandsOf($json),
            'remove'   => isset($json['remove_keyboard']),
        ];

        $messageId = $this->nextMessageId++;

        return new class($messageId) {
            public function __construct(private int $id)
            {
            }

            public function getMessageId(): int
            {
                return $this->id;
            }
        };
    }

    public function editMessageReplyMarkup(
        $chatId,
        $messageId,
        $replyMarkup = null,
        $inlineMessageId = null
    ) {
        $json = $replyMarkup !== null ? json_decode($replyMarkup->toJson(), true) : null;

        $this->edited[] = [
            'message_id' => $messageId,
            'keyboard'   => self::commandsOf($json),
        ];

        return true;
    }

    /**
     * La vista de les proves: cada botó val el comandament que executaria.
     */
    private static function commandsOf(?array $json): ?array
    {
        if ($json === null) {
            return null;
        }

        if (isset($json['inline_keyboard'])) {
            return array_map(function ($row) {
                return array_map(function ($button) {
                    $data = $button['callback_data'];

                    return str_starts_with($data, 'c:') ? substr($data, 2) : $data;
                }, $row);
            }, $json['inline_keyboard']);
        }

        return $json['keyboard'] ?? null;
    }
}

class FakePlayer extends Player
{
    public array $players       = [];
    public array $playersById   = [];
    public array $created       = [];
    public int $nextPlayerId    = 7;

    public function getPlayerByChatId($chatId)
    {
        return isset($this->players[$chatId]) ? [$this->players[$chatId]] : [];
    }

    public function getPlayerById($playerId)
    {
        return isset($this->playersById[$playerId]) ? [$this->playersById[$playerId]] : [];
    }

    public function createPlayer($chatId)
    {
        $playerId = $this->nextPlayerId++;

        $this->created[]                = ['chat_id' => $chatId, 'player_id' => $playerId];
        $this->players[$chatId]         = ['id' => $playerId, 'chat_id' => $chatId, 'name' => null];
        $this->playersById[$playerId]   = $this->players[$chatId];

        return $playerId;
    }

    public function getAllPlayers()
    {
        return array_values($this->players);
    }
}

class FakeTeam extends Team
{
    public array $teamsByPot    = [];
    public array $teamsByName   = [];
    public array $teamsById     = [];
    public array $teamsByPlayer = [];
    public array $added         = [];

    public function getTeamsByPot($pot)
    {
        return $this->teamsByPot[$pot] ?? [];
    }

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

    public function addPlayerTeam($playerId, $teamId)
    {
        $this->added[] = ['player_id' => $playerId, 'team_id' => $teamId];

        $this->teamsByPlayer[$playerId][] = $this->teamsById[$teamId];

        return true;
    }
}

class FakeSubstitution extends Substitution
{
}

class FakeAction extends Action
{
}

class FakeMatchDayPlayerPoint extends MatchDayPlayerPoint
{
}

const CHAT_ID = 555;

function team(int $id, string $name, string $country, int $pot): array
{
    return [
        'id'          => $id,
        'name'        => $name,
        'country'     => $country,
        'competition' => 'CHL',
        'pot'         => $pot,
    ];
}

/** @return array [bot, player, team, Dispatcher] */
function buildWorld(bool $substitutionsOpen = true, bool $actionsOpen = false): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $team   = new FakeTeam();

    $pot1 = [
        team(101, 'EquipA', 'Spain', 1),
        team(102, 'EquipB', 'Italy', 1),
    ];
    $pot2 = [
        team(201, 'EquipC', 'France', 2),
    ];
    $pot3 = [
        team(301, 'EquipD', 'Germany', 3),
    ];

    foreach (array_merge($pot1, $pot2, $pot3) as $one) {
        $team->teamsByPot[$one['pot']][] = $one;
        $team->teamsByName[$one['name']] = $one;
        $team->teamsById[$one['id']]     = $one;
    }

    $dispatcher = new Dispatcher(
        $bot,
        $player,
        $team,
        new FakeSubstitution(),
        new FakeAction(),
        new FakeMatchDayPlayerPoint(),
        2,
        15,
        $substitutionsOpen,
        $actionsOpen
    );

    return [$bot, $player, $team, $dispatcher];
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

// ---------- /inici ----------

echo "--- /inici ---\n";
[$bot, , , $dispatcher] = buildWorld();
$dispatcher->dispatch(['/inici'], CHAT_ID);

check('un missatge', count($bot->sent), 1);
check('el text', $bot->sent[0]['text'], 'Que vols fer?');
check('lleva el teclat vell', $bot->sent[0]['remove'], true);
check('el menú va al mateix missatge', $bot->edited[0]['keyboard'], startMenuRows());
check('i no se n\'envia cap altre', $bot->sent[0]['keyboard'], null);

echo "\n--- /start és el mateix ---\n";
[$bot, , , $dispatcher] = buildWorld();
$dispatcher->dispatch(['/start'], CHAT_ID);
check('mateix menú', $bot->edited[0]['keyboard'], startMenuRows());

// ---------- /accions ----------

echo "\n--- /accions amb els canvis oberts ---\n";
[$bot, , , $dispatcher] = buildWorld(true, false);
$dispatcher->dispatch(['/accions'], CHAT_ID);
check('text', $bot->sent[0]['text'], 'Accions disponibles:');
check('només els canvis', $bot->sent[0]['keyboard'], [['/substitució']]);

echo "\n--- /accions amb les accions obertes ---\n";
[$bot, , , $dispatcher] = buildWorld(false, true);
$dispatcher->dispatch(['/accions'], CHAT_ID);
check(
    'les tres accions i el doble o res',
    $bot->sent[0]['keyboard'],
    [['/malDia', '/socElMillor', '/guanyarOMorir'], ['/dobleORes']]
);

echo "\n--- /accions sense res obert ---\n";
[$bot, , , $dispatcher] = buildWorld(false, false);
$dispatcher->dispatch(['/accions'], CHAT_ID);
check('avisa', $bot->sent[0]['text'], 'Ara mateix no hi ha cap acció disponible.');
check('i lleva el teclat vell', $bot->sent[0]['remove'], true);

// ---------- /equips ----------

echo "\n--- /equips d'un jugador nou ---\n";
[$bot, $player, , $dispatcher] = buildWorld();
$dispatcher->dispatch(['/equips'], CHAT_ID);

check('crea el jugador', $player->created, [['chat_id' => CHAT_ID, 'player_id' => 7]]);
check('el pot 1', $bot->sent[0]['text'], 'CHL Pot 1 equips:');
check('els equips del pot', $bot->sent[0]['keyboard'], [['/add EquipA', '/add EquipB']]);

// ---------- /add ----------

echo "\n--- /add d'un equip del pot següent ---\n";
[$bot, $player, $team, $dispatcher] = buildWorld();
$player->players[CHAT_ID] = ['id' => 7, 'chat_id' => CHAT_ID, 'name' => 'Tester'];
$player->playersById[7]   = $player->players[CHAT_ID];
$team->teamsByPlayer[7]   = [$team->teamsById[101]];

$dispatcher->dispatch(['/add', 'EquipC'], CHAT_ID);

check('el desa', $team->added, [['player_id' => 7, 'team_id' => 201]]);
check('confirma', $bot->sent[0]['text'], 'EquipC team added');
check('i passa al pot 3', $bot->sent[1]['text'], 'CHL Pot 3 equips:');
check('amb l\'equip que queda', $bot->sent[1]['keyboard'], [['/add EquipD']]);

// ---------- /data i el text desconegut ----------

echo "\n--- /data ---\n";
[$bot, , , $dispatcher] = buildWorld();
$dispatcher->dispatch(['/data'], CHAT_ID);
check('el menú de dades', $bot->sent[0]['text'], 'Data disponible:');
check('amb els botons', $bot->sent[0]['keyboard'], [
    ['/data pots', '/data players'],
    ['/classificacio', '/classificacio competicions'],
]);

echo "\n--- un text que no és cap comandament ---\n";
[$bot, , , $dispatcher] = buildWorld();
$dispatcher->dispatch(['Hola'], CHAT_ID);
check('avisa', $bot->sent[0]['text'], "No t'he entés. Tria una opció:");
check('amb el botó d\'inici', $bot->sent[0]['keyboard'], [['/inici']]);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
