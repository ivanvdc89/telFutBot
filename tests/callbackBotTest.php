<?php

/**
 * Prova de CallbackBot: el que passa després de polsar un botó.
 *
 * Es canvia call() per un doble, així que no es toca la xarxa. Es comprova que
 * el missatge del menú vell es quede sense botons, que l'últim missatge sense
 * camí reba "Accions" i "Inici", i que ReplyKeyboardRemove no compte com un
 * menú.
 *
 *   php tests/callbackBotTest.php
 */

include './vendor/autoload.php';

require_once("actions/InlineMenu.php");
require_once("actions/CallbackBot.php");

use TelegramBot\Api\Types\ReplyKeyboardRemove;

class FakeCallbackBot extends CallbackBot
{
    public array $calls = [];

    private int $nextMessageId = 10;

    public function __construct(int $chatId = 555, int $sourceMessageId = 1)
    {
        parent::__construct('test-token', $chatId, $sourceMessageId);
    }

    public function call($method, array $data = null, $timeout = 10)
    {
        $this->calls[] = ['method' => $method, 'data' => $data];

        if ($method === 'sendMessage' || $method === 'sendPhoto') {
            return [
                'message_id' => $this->nextMessageId++,
                'date'       => time(),
                'chat'       => ['id' => 555, 'type' => 'private'],
            ];
        }

        return true;
    }
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

/** @return array<int, array{method: string, data: array}> */
function callsOf(FakeCallbackBot $bot, string $method): array
{
    return array_values(array_filter(
        $bot->calls,
        function ($call) use ($method) { return $call['method'] === $method; }
    ));
}

/** La vista de les proves: cada botó val el comandament que executaria. */
function commandsOf(?string $replyMarkup): ?array
{
    if ($replyMarkup === null) {
        return null;
    }

    $json = json_decode($replyMarkup, true);

    if (!isset($json['inline_keyboard'])) {
        return null;
    }

    return array_map(function ($row) {
        return array_map(function ($button) {
            $data = $button['callback_data'];

            return str_starts_with($data, 'c:') ? substr($data, 2) : $data;
        }, $row);
    }, $json['inline_keyboard']);
}

// ---------- llevar els botons del missatge vell ----------

echo "--- llevar els botons del missatge que s'ha polsat ---\n";
$bot = new FakeCallbackBot();
$bot->removeSourceKeyboard();

$edits = callsOf($bot, 'editMessageReplyMarkup');
check('una edició', count($edits), 1);
check('al xat', $edits[0]['data']['chat_id'], 555);
check('del missatge polsat', $edits[0]['data']['message_id'], 1);
check('sense botons', $edits[0]['data']['reply_markup'], null);

// ---------- un missatge sense camí ----------

echo "\n--- un error no deixa el jugador sense botons ---\n";
$bot = new FakeCallbackBot();
$bot->sendMessage(555, "ERROR, l'equip no existeix");
$bot->finish();

$edits = callsOf($bot, 'editMessageReplyMarkup');
check('s\'edita l\'últim missatge', count($edits), 1);
check('el missatge', $edits[0]['data']['message_id'], 10);
check('amb accions i inici', commandsOf($edits[0]['data']['reply_markup']), [['/accions', '/inici']]);

// ---------- una foto ----------

echo "\n--- una foto tampoc no deixa el jugador sense botons ---\n";
$bot = new FakeCallbackBot();
$bot->sendPhoto(555, 'foto.png', 'Regles del joc');
$bot->finish();

$edits = callsOf($bot, 'editMessageReplyMarkup');
check('s\'edita la foto', count($edits), 1);
check('la foto', $edits[0]['data']['message_id'], 10);
check('amb accions i inici', commandsOf($edits[0]['data']['reply_markup']), [['/accions', '/inici']]);

// ---------- un missatge que ja porta menú ----------

echo "\n--- un menú propi no es toca ---\n";
$bot = new FakeCallbackBot();
$bot->sendMessage(
    555,
    "Accions disponibles:",
    false,
    null,
    null,
    InlineMenu::fromCommands([['/substitució']])
);
$bot->finish();

check('cap edició', count(callsOf($bot, 'editMessageReplyMarkup')), 0);

// ---------- ReplyKeyboardRemove ----------

echo "\n--- ReplyKeyboardRemove no és un menú ---\n";
$bot = new FakeCallbackBot();
$bot->sendMessage(555, "Ara mateix no hi ha cap acció disponible.", false, null, null, new ReplyKeyboardRemove());
$bot->finish();

$edits = callsOf($bot, 'editMessageReplyMarkup');
check('s\'hi afig un camí', count($edits), 1);
check('amb accions i inici', commandsOf($edits[0]['data']['reply_markup']), [['/accions', '/inici']]);

// ---------- el menú d'inici editat a posta ----------

echo "\n--- si l'acció ja ha posat el menú, no se'n posa un altre ---\n";
$bot = new FakeCallbackBot();
$message = $bot->sendMessage(555, "Que vols fer?", false, null, null, new ReplyKeyboardRemove());
$bot->editMessageReplyMarkup(
    555,
    $message->getMessageId(),
    InlineMenu::fromCommands([['/accions', '/data', '/equips'], ['/normes', '/configuració']])
);
$bot->finish();

$edits = callsOf($bot, 'editMessageReplyMarkup');
check('només l\'edició de l\'acció', count($edits), 1);
check(
    'amb el menú sencer',
    commandsOf($edits[0]['data']['reply_markup']),
    [['/accions', '/data', '/equips'], ['/normes', '/configuració']]
);

// ---------- res enviat ----------

echo "\n--- si l'acció no ha enviat res ---\n";
$bot = new FakeCallbackBot();
$bot->finish();

$sends = callsOf($bot, 'sendMessage');
check('s\'envia un missatge', count($sends), 1);
check('amb el text', $sends[0]['data']['text'], 'Tria una opció:');
check('i el teclat', commandsOf($sends[0]['data']['reply_markup']), [['/accions', '/inici']]);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
