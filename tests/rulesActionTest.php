<?php
// Prova funcional de RulesAction (/normes) amb dobles, sense Telegram.
// Cobreix el menú, les quatre fotos i les set normes d'acció. Els textos no es
// repeteixen aquí: es comprova que hi siguin tots, en ordre i sencers, que és
// el que es pot trencar en moure'ls de basic.php.
// S'executa des de l'arrel del projecte: php tests/rulesActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("actions/CommandAction.php");
require_once("actions/RulesAction.php");

use TelegramBot\Api\BotApi;

class FakeBot extends BotApi
{
    public array $sent   = [];
    public array $photos = [];

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

    public function sendPhoto(
        $chatId,
        $photo,
        $caption = null,
        $replyToMessageId = null,
        $replyMarkup = null,
        $disableNotification = false,
        $parseMode = null,
        $messageThreadId = null,
        $protectContent = null,
        $allowSendingWithoutReply = null
    ) {
        $this->photos[] = ['file' => $photo->getFilename(), 'caption' => $caption];

        return null;
    }
}

const CHAT_ID = 555;

/** @return FakeBot el bot amb tot el que ha enviat /normes */
function runCommand(string $text): FakeBot
{
    $bot = new FakeBot();

    (new RulesAction($bot, CHAT_ID, explode(' ', $text)))->run();

    return $bot;
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

echo "--- el router reconeix /normes ---\n";
check('/normes', RulesAction::handlesCommand('/normes'), true);
check('/rules', RulesAction::handlesCommand('/rules'), true);
check('no agafa /normes2', RulesAction::handlesCommand('/normes2'), false);
check('no agafa /data', RulesAction::handlesCommand('/data'), false);

// ---------- menú ----------

echo "\n--- /normes ---\n";
$bot = runCommand('/normes');
check('text', $bot->sent[0]['text'], 'Normes disponibles:');
check('teclat', $bot->sent[0]['keyboard'], [['/normes bàsiques', '/normes accions']]);
check('una sola cosa', count($bot->sent), 1);

echo "\n--- /rules és el mateix ---\n";
$bot = runCommand('/rules');
check('mateix text', $bot->sent[0]['text'], 'Normes disponibles:');

// ---------- normes bàsiques ----------

echo "\n--- /normes bàsiques ---\n";
$bot = runCommand('/normes bàsiques');
check('quatre fotos, en ordre', $bot->photos, [
    ['file' => 'files/rules.png', 'caption' => 'Regles del joc'],
    ['file' => 'files/ch.png', 'caption' => 'Equips de la Champions'],
    ['file' => 'files/el.png', 'caption' => 'Equips de la Europa League'],
    ['file' => 'files/cl.png', 'caption' => 'Equips de la Conference League'],
]);
check('cap missatge de text', $bot->sent, []);

// ---------- normes d'accions ----------

echo "\n--- /normes accions ---\n";
$bot = runCommand('/normes accions');
check('set missatges, un per norma', count($bot->sent), 7);

$labels = [];
foreach ($bot->sent as $message) {
    preg_match('/^Norma (#\w+):/', $message['text'], $matches);
    $labels[] = $matches[1] ?? '(sense etiqueta)';
}
check('les set normes, en ordre', $labels, [
    '#malDia', '#socElMillor', '#guanyarOMorir',
    '#dobleORes', '#kosAmbEscuts', '#millorFinal', '#segurQuePasse',
]);
check('tots els textos són sencers', count(array_filter($bot->sent, fn($m) => strlen($m['text']) > 200)), 7);
check('cap amb teclat', array_column($bot->sent, 'keyboard'), array_fill(0, 7, null));
check('cap foto', $bot->photos, []);

// ---------- subcomandaments ----------

echo "\n--- /normes amb un subcomandament desconegut ---\n";
$bot = runCommand('/normes foo');
check('torna al menú', $bot->sent[0]['text'], 'Normes disponibles:');
check('amb el teclat', $bot->sent[0]['keyboard'], [['/normes bàsiques', '/normes accions']]);
check('i res més', count($bot->sent), 1);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
