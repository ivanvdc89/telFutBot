<?php
// Prova funcional de SettingsAction (/configuració i /nom) amb dobles, sense BD
// ni Telegram. Cobreix el text del menú, les validacions del nom i que no es
// toqui la base de dades quan el nom no val.
// S'executa des de l'arrel del projecte: php tests/settingsActionTest.php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/settings.php");
require_once("models/player.php");
require_once("actions/SettingsAction.php");

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
    /** @var array [chat_id => fila de players] */
    public array $players = [];

    /** @var array [player_id => fila de players], per a getPlayerById/ByName */
    public array $playersById = [];

    /** @var array Files que hauria d'haver escrit updatePlayerName(). */
    public array $updatedNames = [];

    /** Per provar l'error d'escriptura sense trencar la base de dades. */
    public bool $updateFails = false;

    public function getPlayerByChatId($chatId)
    {
        return isset($this->players[$chatId]) ? [$this->players[$chatId]] : [];
    }

    public function getPlayerById($playerId)
    {
        return isset($this->playersById[$playerId]) ? [$this->playersById[$playerId]] : [];
    }

    /**
     * Mateixa col·lació que la base de dades (utf8mb4 *_ci): "Ivan" i "ivan"
     * són el mateix nom.
     */
    public function getPlayerByName($name)
    {
        $matches = [];

        foreach ($this->playersById as $player) {
            if ($player['name'] !== null
                && mb_strtolower($player['name']) === mb_strtolower($name)) {
                $matches[] = $player;
            }
        }

        return $matches;
    }

    public function updatePlayerName($playerId, $name)
    {
        if ($this->updateFails) {
            return false;
        }

        $this->updatedNames[]                     = ['player_id' => $playerId, 'name' => $name];
        $this->playersById[$playerId]['name']     = $name;

        return true;
    }
}

const CHAT_ID   = 555;
const PLAYER_ID = 7;
const MATCH_DAY = 2;

/**
 * Món amb el jugador del xat i, si es vol, un altre jugador que ja té el nom.
 *
 * @return array [bot, repo de jugadors]
 */
function buildWorld(?string $currentName = 'Ivan', bool $withOtherPlayer = true): array
{
    $bot    = new FakeBot();
    $player = new FakePlayer();

    $player->players[CHAT_ID] = [
        'id' => PLAYER_ID, 'chat_id' => CHAT_ID, 'name' => $currentName,
    ];
    $player->playersById[PLAYER_ID] = $player->players[CHAT_ID];

    if ($withOtherPlayer) {
        $player->playersById[8] = ['id' => 8, 'chat_id' => 999, 'name' => 'Marta'];
    }

    return [$bot, $player];
}

/** @return array [missatges enviats, repo de jugadors] */
function runCommand(array $world, string $text, int $chatId = CHAT_ID): array
{
    [$bot, $player] = $world;
    $bot->sent = [];
    $args      = explode(' ', $text);

    $handler = new SettingsAction($bot, $player, $chatId, MATCH_DAY, $args);
    $handler->run();

    return [$bot->sent, $player];
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

const MENU_KEYBOARD = [['/nom', '/inici']];

// ---------- el router ----------

echo "--- el router reconeix els comandaments de configuració ---\n";
check('/configuració', SettingsAction::handlesCommand('/configuració'), true);
check('/settings', SettingsAction::handlesCommand('/settings'), true);
check('/nom', SettingsAction::handlesCommand('/nom'), true);
check('/name', SettingsAction::handlesCommand('/name'), true);
check('no agafa /accions', SettingsAction::handlesCommand('/accions'), false);
check('no agafa /inici', SettingsAction::handlesCommand('/inici'), false);

// ---------- peces pures ----------

echo "\n--- normalització i validació del nom (config/settings.php) ---\n";
check('treu espais de les puntes i de dins', normalizePlayerName("  Ivan \n Arrachina  "), 'Ivan Arrachina');
check('els espais dobles queden en un', normalizePlayerName('Ivan   Arrachina'), 'Ivan Arrachina');
check('un nom buit no val', playerNameProblem(''), 'El nom no pot estar buit.');
check('un nom normal sí', playerNameProblem('Ivan Arrachina'), null);
check('100 caràcters sí', playerNameProblem(str_repeat('a', 100)), null);
check(
    '101 caràcters no',
    playerNameProblem(str_repeat('a', 101)),
    'El nom no pot passar de 100 caràcters.'
);
check('no pot ser un comandament', playerNameProblem('/inici'), 'El nom no pot començar amb /.');
check('"all" està reservat', playerNameProblem('all'), 'El nom "all" està reservat.');
check('"ALL" també', playerNameProblem('ALL'), 'El nom "ALL" està reservat.');
check('el menú de configuració', settingsMenuRows(), MENU_KEYBOARD);

// ---------- /configuració ----------

echo "\n--- /configuració amb nom ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/configuració');
check(
    'diu el nom i com canviar-lo',
    $sent[0]['text'],
    "Configuració\n\nEl teu nom: Ivan\n\nPer canviar-lo, envia:\n/nom ElTeuNom"
);
check('amb el teclat de configuració', $sent[0]['keyboard'], MENU_KEYBOARD);
check('no escriu res', $player->updatedNames, []);

echo "\n--- /configuració sense nom ---\n";
[$sent] = runCommand(buildWorld(null), '/configuració');
check(
    'avisa que no en té',
    $sent[0]['text'],
    "Configuració\n\nEncara no tens nom. El fan servir els anuncis i /data players.\n\nPer canviar-lo, envia:\n/nom ElTeuNom"
);

echo "\n--- /settings és el mateix ---\n";
[$sent] = runCommand(buildWorld('Ivan'), '/settings');
check('mateix text', $sent[0]['text'], "Configuració\n\nEl teu nom: Ivan\n\nPer canviar-lo, envia:\n/nom ElTeuNom");

// ---------- /nom ----------

echo "\n--- /nom amb un nom nou ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom Arrachina');
check('el desa', $player->updatedNames, [['player_id' => PLAYER_ID, 'name' => 'Arrachina']]);
check('confirma el nom nou', $sent[0]['text'], 'Nom guardat: Arrachina');
check('amb teclat per tornar-hi', $sent[0]['keyboard'], [['/configuració', '/inici']]);

echo "\n--- /nom amb dues paraules ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom Ivan Arrachina');
check('desa el nom sencer', $player->updatedNames, [['player_id' => PLAYER_ID, 'name' => 'Ivan Arrachina']]);
check('i el confirma sencer', $sent[0]['text'], 'Nom guardat: Ivan Arrachina');

echo "\n--- /nom sense argument ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom');
check(
    'mostra la configuració',
    $sent[0]['text'],
    "Configuració\n\nEl teu nom: Ivan\n\nPer canviar-lo, envia:\n/nom ElTeuNom"
);
check('amb el teclat', $sent[0]['keyboard'], MENU_KEYBOARD);
check('i no escriu res', $player->updatedNames, []);

echo "\n--- /nom amb el nom que ja té ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom Ivan');
check('ho diu i prou', $sent[0]['text'], 'Ja et dius Ivan.');
check('sense escriure', $player->updatedNames, []);

echo "\n--- /nom repetit (el té un altre jugador) ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom Marta');
check('error de nom ocupat', $sent[0]['text'], "ERROR, Aquest nom ja és d'un altre jugador.\n\nEnvia /nom ElTeuNom");
check('i no toca la BD', $player->updatedNames, []);

echo "\n--- /nom repetit canviant majúscules ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom MARTA');
check('també està ocupat', $sent[0]['text'], "ERROR, Aquest nom ja és d'un altre jugador.\n\nEnvia /nom ElTeuNom");
check('i no toca la BD', $player->updatedNames, []);

echo "\n--- /nom canvia el nom del mateix jugador passant de majúscules ---\n";
[$sent, $player] = runCommand(buildWorld('ivan'), '/nom Ivan');
check('sí que es pot reescriure', $player->updatedNames, [['player_id' => PLAYER_ID, 'name' => 'Ivan']]);
check('i confirma', $sent[0]['text'], 'Nom guardat: Ivan');

echo "\n--- /nom amb noms que no valen ---\n";
foreach ([
    'comandament'  => ['/nom /inici', 'ERROR, El nom no pot començar amb /.'],
    'reservat'     => ['/nom all', 'ERROR, El nom "all" està reservat.'],
    'massa llarg'  => ['/nom ' . str_repeat('a', 101), 'ERROR, El nom no pot passar de 100 caràcters.'],
] as $label => [$text, $expected]) {
    [$sent, $player] = runCommand(buildWorld('Ivan'), $text);
    check($label, $sent[0]['text'], $expected . "\n\nEnvia /nom ElTeuNom");
    check($label . ': no escriu', $player->updatedNames, []);
}

echo "\n--- /nom només amb espais ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom    ');
check('torna a mostrar la configuració', str_starts_with($sent[0]['text'], "Configuració\n"), true);
check('sense escriure', $player->updatedNames, []);

echo "\n--- /nom accepta els 100 caràcters ---\n";
$longest = str_repeat('a', 100);
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom ' . $longest);
check('desa el nom més llarg', $player->updatedNames, [['player_id' => PLAYER_ID, 'name' => $longest]]);

echo "\n--- error en desar ---\n";
$world = buildWorld('Ivan');
$world[1]->updateFails = true;
[$sent, $player] = runCommand($world, '/nom Arrachina');
check('avisa que no s\'ha pogut desar', $sent[0]['text'], "ERROR, no s'ha pogut desar el nom. Torna-ho a provar.");
check('i no dona el canvi per fet', $player->updatedNames, []);

echo "\n--- jugador que encara no és al joc ---\n";
[$sent, $player] = runCommand(buildWorld('Ivan'), '/nom Arrachina', 12345);
check(
    'se l\'envida a /equips',
    $sent[0]['text'],
    "Encara no ets al joc. Fes /equips per començar; després podràs definir el teu nom amb /nom."
);
check('amb els botons', $sent[0]['keyboard'], [['/equips', '/inici']]);
check('i no toca la BD', $player->updatedNames, []);

[$sent, $player] = runCommand(buildWorld('Ivan'), '/configuració', 12345);
check('també a /configuració', $sent[0]['keyboard'], [['/equips', '/inici']]);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}
echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
