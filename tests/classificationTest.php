<?php

/**
 * Prova de les classificacions consultables: /classificacio.
 *
 * Cobreix la general (players.points), la de cada competició (els totals de
 * match_day_player_points) i els empats, sense base de dades ni Telegram.
 *
 *   php tests/classificationTest.php
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/publishResults.php");
require_once("config/classification.php");
require_once("models/player.php");
require_once("models/matchDayPlayerPoint.php");
require_once("actions/ClassificationAction.php");

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

        $this->sent[] = [
            'text'       => $text,
            'parse_mode' => $parseMode,
            'keyboard'   => $keyboard,
        ];

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
}

class FakePoints extends MatchDayPlayerPoint
{
    public array $latestTotals = [];

    public function getLatestTotalsByPlayer(): array
    {
        return $this->latestTotals;
    }
}

const CHAT_ID = 555;

/** @return array [bot, jugadors, punts, acció] */
function buildWorld(array $players, array $latestTotals): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();
    $points = new FakePoints();

    $player->players       = $players;
    $points->latestTotals  = $latestTotals;

    $action = new ClassificationAction($bot, $player, $points, CHAT_ID, ['/classificacio']);

    return [$bot, $player, $points, $action];
}

/** @return array [bot, jugadors, punts, acció] */
function worldWithPlayers(): array
{
    return buildWorld(
        [
            ['id' => 1, 'name' => 'A', 'points' => 10],
            ['id' => 2, 'name' => 'B', 'points' => 10],
            ['id' => 3, 'name' => 'C', 'points' => 5],
        ],
        [
            1 => ['player_id' => 1, 'chl_total' => 7,  'eul_total' => 1, 'col_total' => 2, 'total' => 10],
            2 => ['player_id' => 2, 'chl_total' => 12, 'eul_total' => 0, 'col_total' => 0, 'total' => 12],
            3 => ['player_id' => 3, 'chl_total' => 0,  'eul_total' => 5, 'col_total' => 0, 'total' => 5],
        ]
    );
}

function run(array $world, string $text): array
{
    [$bot, $player, $points] = $world;

    $args   = explode(' ', $text);
    $action = new ClassificationAction($bot, $player, $points, CHAT_ID, $args);
    $action->run();

    return $bot->sent;
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

// ---------- la general ----------

echo "--- /classificacio ---\n";
$sent = run(worldWithPlayers(), '/classificacio');

check('un sol missatge', count($sent), 1);
check('va dins d\'un bloc de codi', $sent[0]['parse_mode'], 'HTML');
check(
    'la general, amb els empatats a la mateixa posició',
    $sent[0]['text'],
    "<pre>🏆 Classificació general\n\n1. A  10 pts\n1. B  10 pts\n3. C   5 pts</pre>"
);
check('botons per anar a competicions i a dades', $sent[0]['keyboard'], [
    ['/classificacio competicions', '/data'],
]);

// ---------- el menú de competicions ----------

echo "\n--- /classificacio competicions ---\n";
$sent = run(worldWithPlayers(), '/classificacio competicions');

check('text', $sent[0]['text'], 'Classificació per competició:');
check('les tres competicions', $sent[0]['keyboard'], [
    ['/classificacio CHL', '/classificacio EUL', '/classificacio COL'],
    ['/data'],
]);

// ---------- cada competició ----------

echo "\n--- /classificacio CHL ---\n";
$sent = run(worldWithPlayers(), '/classificacio CHL');

check(
    'ordena pels punts de la CHL',
    $sent[0]['text'],
    "<pre>🏆 Classificació CHL\n\n1. B  12 pts\n2. A   7 pts\n3. C   0 pts</pre>"
);
check('i torna a competicions o a la general', $sent[0]['keyboard'], [
    ['/classificacio competicions', '/classificacio'],
]);

echo "\n--- /classificacio EUL ---\n";
$sent = run(worldWithPlayers(), '/classificacio EUL');

check(
    'cada competició té els seus punts',
    $sent[0]['text'],
    "<pre>🏆 Classificació EUL\n\n1. C  5 pts\n2. A  1 pts\n3. B  0 pts</pre>"
);

// ---------- els cinc millors de cada competició ----------

echo "\n--- només els cinc millors de la competició ---\n";
$manyPlayers = [];
$manyTotals  = [];

for ($i = 1; $i <= 7; $i++) {
    $manyPlayers[] = ['id' => $i, 'name' => 'P' . $i, 'points' => 10 - $i];
    $manyTotals[$i] = [
        'player_id' => $i,
        'chl_total' => 20 - $i,
        'eul_total' => 0,
        'col_total' => 0,
        'total'     => 10 - $i,
    ];
}

$sent = run(buildWorld($manyPlayers, $manyTotals), '/classificacio CHL');
check('el cinqué hi és', str_contains($sent[0]['text'], '5. P5'), true);
check('el sisé no', str_contains($sent[0]['text'], 'P6'), false);
check('i només cinc files', substr_count($sent[0]['text'], ' pts'), 5);
check('i diu quants en queden', str_contains($sent[0]['text'], '… i 2 jugadors més.'), true);

$sent = run(buildWorld($manyPlayers, $manyTotals), '/classificacio');
check('la general els ensenya tots', str_contains($sent[0]['text'], 'P7'), true);

// ---------- sense dades ----------

echo "\n--- sense cap punt ---\n";
$sent = run(buildWorld(
    [['id' => 1, 'name' => 'A', 'points' => 0]],
    []
), '/classificacio CHL');
check('ho diu', str_contains($sent[0]['text'], 'Encara no hi ha cap punt.'), true);

$sent = run(buildWorld([], []), '/classificacio');
check('i també a la general', str_contains($sent[0]['text'], 'Encara no hi ha cap punt.'), true);

echo "\n--- punts negatius ---\n";
$sent = run(buildWorld(
    [['id' => 1, 'name' => 'A', 'points' => -2]],
    []
), '/classificacio');
check('es veuen', str_contains($sent[0]['text'], '-2 pts'), true);
check('i no es confonen amb "sense punts"', str_contains($sent[0]['text'], 'Encara no hi ha cap punt.'), false);

// ---------- noms que falten ----------

echo "\n--- un jugador sense nom ---\n";
$sent = run(buildWorld(
    [['id' => 9, 'name' => null, 'points' => 3]],
    []
), '/classificacio');
check('es mostra pel número', str_contains($sent[0]['text'], '#9'), true);

// ---------- el router ----------

echo "\n--- el router ---\n";
check('/classificacio', ClassificationAction::handlesCommand('/classificacio'), true);
check('/classificacions', ClassificationAction::handlesCommand('/classificacions'), true);
check('no agafa /data', ClassificationAction::handlesCommand('/data'), false);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
