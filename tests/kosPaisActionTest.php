<?php
// Prova funcional de KosPaisAction (/kosPais) amb dobles, sense BD ni Telegram.
// S'executa des de l'arrel del projecte: php tests/kosPaisActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/action.php");
require_once("actions/KosPaisAction.php");

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

    /** @var array<int, string> */
    public array $countries = ['Spain', 'Germany', 'France', 'Italy', 'Bosnia and Herzegovina'];

    /**
     * Els països de la temporada, com els torna la consulta de veritat: per la
     * suma de punts dels seus equips i, si empaten, pel nom.
     */
    public function getCountries(): array
    {
        return $this->countries;
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
            'id'        => 44,
            'player_id' => $playerId,
            'match_day' => $matchDay,
            'type'      => $type,
            'data'      => $data,
        ];

        return 44;
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
    ?array $actionData = ['max' => 3, 'paisos' => [], 'shields' => []],
    bool $activated = true,
    string $activeKos = 'kosPais',
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
            'id'        => 43,
            'player_id' => PLAYER_ID,
            'match_day' => MATCH_DAY,
            'type'      => 'kosPais',
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

    $handler = new KosPaisAction($bot, $player, $team, $action, CHAT_ID, MATCH_DAY, $args, $activated, $activeKos);
    $handler->run();

    return [$bot->sent, $action];
}

/**
 * Un món amb tants països com calga (per a la paginació).
 *
 * @param array<int, string> $countries
 */
function worldWithCountries(array $countries): array
{
    $world = buildWorld();
    $world[2]->countries = $countries;

    return $world;
}

// ---------- escenaris ----------

echo "--- l'acció no està activada ---\n";
[$sent] = runCommand(buildWorld(null, false), '/kosPais');
check('un sol missatge', count($sent), 1);
check('text', $sent[0]['text'], 'No disponible');

echo "\n--- el boicot actiu és un altre ---\n";
[$sent, $action] = runCommand(buildWorld(null, true, 'kosPots'), '/kosPais');
check('contesta que no', $sent[0]['text'], 'No disponible');
check('i no crea cap fila', $action->added, []);

echo "\n--- activada i sense fila: el bot la crea ---\n";
[$sent, $action] = runCommand(buildWorld(null), '/kosPais');
check('ha creat la fila amb el màxim i les llistes buides', $action->added, [[
    'playerId' => PLAYER_ID,
    'matchDay' => MATCH_DAY,
    'type'     => 'kosPais',
    'data'     => ['max' => 3, 'paisos' => [], 'shields' => []],
]]);
check('i mostra el menú', $sent[0]['text'], 'Països disponibles:');

echo "\n--- /kosPais: els països i els escuts ---\n";
[$sent] = runCommand(buildWorld(), '/kosPais');
check('text', $sent[0]['text'], 'Països disponibles:');
check('tres files de botons', count($sent[0]['keyboard']), 3);
check('els països de tres en tres, els de més punts primer', $sent[0]['keyboard'][0], [
    '/kosPais vot Spain',
    '/kosPais vot Germany',
    '/kosPais vot France',
]);
check('els que queden', $sent[0]['keyboard'][1], [
    '/kosPais vot Italy',
    '/kosPais vot Bosnia and Herzegovina',
]);
check('i els escuts', $sent[0]['keyboard'][2], [
    '/kosPais escut CHL',
    '/kosPais escut EUL',
    '/kosPais escut COL',
    '/kosPais',
]);

echo "\n--- /kosPais vot Spain ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPais vot Spain');
check('dos missatges', count($sent), 2);
check('primer: vot guardat', $sent[0]['text'], 'Vot guardat');
check('segon: la llista i el que falta', $sent[1]['text'], "Vots màxims: 3\nPaïsos votats:\n- Spain\n\nEscuts:\n\nEt queden 2 vots de KO pendents d'usar.\nEt queden 3 escuts pendents de posar.");
check('s\'ha desat el vot', $action->updates, [['id' => 43, 'data' => ['max' => 3, 'paisos' => ['Spain'], 'shields' => []]]]);

echo "\n--- un país amb espais al nom ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPais vot Bosnia and Herzegovina');
check('es desa el nom sencer', $action->updates, [[
    'id'   => 43,
    'data' => ['max' => 3, 'paisos' => ['Bosnia and Herzegovina'], 'shields' => []],
]]);

echo "\n--- es pot votar dos cops el mateix país ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'paisos' => ['Spain'], 'shields' => []]), '/kosPais vot Spain');
check('el segon vot també es desa', $action->updates, [[
    'id'   => 43,
    'data' => ['max' => 3, 'paisos' => ['Spain', 'Spain'], 'shields' => []],
]]);

echo "\n--- /kosPais vot d'un país que no existeix ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPais vot Narnia');
check('text', $sent[0]['text'], 'ERROR, el país no existeix');
check('no s\'ha desat res', $action->updates, []);

echo "\n--- /kosPais vot sense argument ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPais vot');
check('text', $sent[0]['text'], 'ERROR, el país no existeix');
check('no s\'ha desat res', $action->updates, []);

echo "\n--- /kosPais vot amb tots els vots fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 1, 'paisos' => ['Spain'], 'shields' => []], true, 'kosPais', 9), '/kosPais vot France');
check('avisa que només queden escuts', $sent[0]['text'], 'Ja has fet el màxim de vots de països, ara pots posar escuts si vols:');
check('i ofereix els escuts', $sent[0]['keyboard'][0], ['/kosPais escut CHL', '/kosPais escut EUL', '/kosPais escut COL', '/kosPais']);

echo "\n--- /kosPais escut CHL ---\n";
[$sent] = runCommand(buildWorld(), '/kosPais escut CHL');
check('text', $sent[0]['text'], 'Els teus equips per a posar escut:');
check('teclat', $sent[0]['keyboard'], [['/kosPais escut OwnedPot1']]);

echo "\n--- /kosPais escut OwnedPot1 ---\n";
[$sent, $action] = runCommand(buildWorld(), '/kosPais escut OwnedPot1');
check('dos missatges', count($sent), 2);
check('primer: escut guardat', $sent[0]['text'], 'Escut guardat');
check('segon: la llista amb l\'escut', $sent[1]['text'], "Vots màxims: 3\nPaïsos votats:\n\nEscuts:\n- OwnedPot1\n\nEt queden 3 vots de KO pendents d'usar.\nEt queden 2 escuts pendents de posar.");
check('s\'ha desat l\'escut', $action->updates, [['id' => 43, 'data' => ['max' => 3, 'paisos' => [], 'shields' => [101]]]]);

echo "\n--- /kosPais borrar ---\n";
[$sent, $action] = runCommand(buildWorld(['max' => 3, 'paisos' => ['Spain'], 'shields' => [101]]), '/kosPais borrar');
check('text', $sent[0]['text'], 'Vots i escuts esborrats');
check('teclat', $sent[0]['keyboard'], [['/kosPais', '/inici']]);
check('esborra vots i escuts i conserva el max', $action->updates, [
    ['id' => 43, 'data' => ['max' => 3, 'paisos' => [], 'shields' => []]],
]);

echo "\n--- /kosPais amb tots els vots i escuts fets ---\n";
[$sent] = runCommand(buildWorld(['max' => 2, 'paisos' => ['Spain', 'Italy'], 'shields' => [101, 105]], true, 'kosPais', 19), '/kosPais');
check('dos missatges', count($sent), 2);
check('llista els països', $sent[0]['text'], "Vots màxims: 2\nPaïsos votats:\n- Spain\n- Italy\n\nEscuts:\n- OwnedPot1\n- OwnedPot5\n");
check('avisa que ja està', $sent[1]['text'], 'Ja tens tots els vots fets');
check('botó per esborrar', $sent[1]['keyboard'], [['/kosPais borrar', '/inici']]);

echo "\n--- jugador sense fitxa ---\n";
$world = buildWorld();
[$bot, $player] = $world;
$player->players = [];
[$sent] = runCommand($world, '/kosPais');
check('text', $sent[0]['text'], 'Encara no has triat cap equip. Toca "Equips" per començar.');

echo "\n--- paginació: 30 països, 3 pàgines de 12 ---\n";
$manyCountries = [];
for ($i = 1; $i <= 30; $i++) {
    $manyCountries[] = sprintf('Pais%02d', $i);
}

[$sent] = runCommand(worldWithCountries($manyCountries), '/kosPais');
check('títol amb la pàgina', $sent[0]['text'], 'Països disponibles (pàgina 1 de 3):');
check('quatre files de països i la de navegació i la d\'escuts', count($sent[0]['keyboard']), 6);
check('la primera fila', $sent[0]['keyboard'][0], ['/kosPais vot Pais01', '/kosPais vot Pais02', '/kosPais vot Pais03']);
check('la quarta fila acaba la pàgina', $sent[0]['keyboard'][3], ['/kosPais vot Pais10', '/kosPais vot Pais11', '/kosPais vot Pais12']);
check('sense Anterior a la primera pàgina', $sent[0]['keyboard'][4], ['/kosPais paisos 2']);
check('i els escuts a l\'última', $sent[0]['keyboard'][5], [
    '/kosPais escut CHL',
    '/kosPais escut EUL',
    '/kosPais escut COL',
    '/kosPais',
]);

[$sent] = runCommand(worldWithCountries($manyCountries), '/kosPais paisos 2');
check('la segona pàgina', $sent[0]['text'], 'Països disponibles (pàgina 2 de 3):');
check('comença on tocava', $sent[0]['keyboard'][0], ['/kosPais vot Pais13', '/kosPais vot Pais14', '/kosPais vot Pais15']);
check('amb Anterior i Següent', $sent[0]['keyboard'][4], ['/kosPais paisos 1', '/kosPais paisos 3']);

[$sent] = runCommand(worldWithCountries($manyCountries), '/kosPais paisos 3');
check('la tercera pàgina', $sent[0]['text'], 'Països disponibles (pàgina 3 de 3):');
check('té sis països', count($sent[0]['keyboard']), 4);
check('sense Següent a l\'última pàgina', $sent[0]['keyboard'][2], ['/kosPais paisos 2']);

echo "\n--- una pàgina fora de rang es queda dins ---\n";
[$sent] = runCommand(worldWithCountries($manyCountries), '/kosPais paisos 99');
check('la 99 és l\'última', $sent[0]['text'], 'Països disponibles (pàgina 3 de 3):');

[$sent] = runCommand(worldWithCountries($manyCountries), '/kosPais paisos 0');
check('la 0 és la primera', $sent[0]['text'], 'Països disponibles (pàgina 1 de 3):');

[$sent] = runCommand(worldWithCountries($manyCountries), '/kosPais paisos');
check('sense número, la primera', $sent[0]['text'], 'Països disponibles (pàgina 1 de 3):');

echo "\n--- i es pot votar un país de qualsevol pàgina ---\n";
[$sent, $action] = runCommand(worldWithCountries($manyCountries), '/kosPais vot Pais25');
check('el vot es desa', $action->updates, [[
    'id'   => 43,
    'data' => ['max' => 3, 'paisos' => ['Pais25'], 'shields' => []],
]]);

echo "\n--- amb pocs països no hi ha navegació ---\n";
[$sent] = runCommand(buildWorld(), '/kosPais');
check('títol sense pàgina', $sent[0]['text'], 'Països disponibles:');
check('tres files', count($sent[0]['keyboard']), 3);

echo "\n--- el router discrimina bé ---\n";
check('agafa /kosPais', KosPaisAction::handlesCommand('/kosPais'), true);
check('no agafa /kos', KosPaisAction::handlesCommand('/kos'), false);
check('no agafa /kosPots', KosPaisAction::handlesCommand('/kosPots'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
