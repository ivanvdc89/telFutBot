<?php

/**
 * Prova dels botons inline: etiquetes, callback_data i el pont cap al router.
 *
 * El que es comprova ací és el que Telegram no explica quan falla: que cap
 * etiqueta arribe amb un comandament visible, que cap callback_data passe de
 * 64 bytes i que cada botó torne al comandament que li toca.
 *
 * Sense base de dades ni Telegram.
 *
 *   php tests/menusTest.php
 */

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/pots.php");
require_once("config/menus.php");
require_once("config/actions.php");
require_once("models/player.php");
require_once("actions/InlineMenu.php");
require_once("actions/CallbackRouter.php");

class FakePlayerById extends Player
{
    public array $playersById = [];

    public function getPlayerById($playerId)
    {
        return isset($this->playersById[$playerId])
            ? [$this->playersById[$playerId]]
            : [];
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

// ---------- etiquetes ----------

echo "--- els botons es lligen com a coses, no com a comandaments ---\n";
check('inici', menuLabel('/inici'), 'Inici');
check('accions', menuLabel('/accions'), 'Accions');
check('doble o res', menuLabel('/dobleORes'), 'Doble o res');
check('el pot porta la competició', menuLabel('/dobleORes pot CHL_1'), 'Champions 1');
check('el pot global antic també', menuLabel('/data pot 5'), 'Europa 1');
check('activar', menuLabel('/malDia Activar CHL'), 'Activar Champions');
check('desactivar', menuLabel('/malDia Desactivar COL'), 'Desactivar Conference');
check('un KO porta el nom de l\'equip', menuLabel('/kos ko_vot RealMadrid'), 'KO: RealMadrid');
check('un escut de competició', menuLabel('/kos escut EUL'), 'Escut Europa');
check('un escut d\'equip', menuLabel('/kos escut OwnedPot1'), 'Escut: OwnedPot1');
check('un forat buit', menuLabel('/out CHL_Pot_2'), 'Champions 2 (buit)');
check('un equip que ix', menuLabel('/out RealMadrid'), 'RealMadrid');
check('un equip que entra', menuLabel('/in EquipA'), 'EquipA');
check('un equip que s\'afig', menuLabel('/add ParisSaintGermain'), 'ParisSaintGermain');
check('un nom de jugador', menuLabel('/data player Ivan Arrachina'), 'Ivan Arrachina');
check('una final', menuLabel('/millorFinal CHL'), 'Final Champions');
check('eliminar els canvis', menuLabel('/substitució remove'), 'Eliminar els canvis');
check('traure l\'aposta', menuLabel('/segurQuePasse Borrar'), 'Esborrar l\'aposta');
check('un comandament sense etiqueta perd la barra', menuLabel('/foo'), 'foo');

echo "\n--- cap etiqueta no comença per / ---\n";
$commands = [
    '/inici', '/equips', '/accions', '/data', '/normes', '/configuració', '/nom',
    '/data pots', '/data players', '/normes bàsiques', '/normes accions',
    '/substitució', '/substitució remove', '/malDia', '/socElMillor',
    '/guanyarOMorir', '/dobleORes', '/dobleORes borrar', '/kos', '/kos borrar',
    '/malDia Activar CHL', '/dobleORes pot CHL_1', '/kos ko_pot COL_4',
    '/kos ko_vot RealMadrid', '/kos escut CHL', '/kos escut OwnedPot1',
    '/out CHL_Pot_1', '/out RealMadrid', '/in EquipA', '/add EquipA',
    '/passe OwnedPot1', '/millorFinal CHL', '/millorFinal borrar',
    '/segurQuePasse Borrar', '/data pot CHL_1',
];

$withSlash = array_values(array_filter(
    array_map('menuLabel', $commands),
    function ($label) { return str_starts_with($label, '/'); }
));

check('cap', $withSlash, []);

// ---------- callback_data ----------

echo "\n--- callback_data ---\n";
check('un comandament sencer', commandCallbackData('/inici'), 'c:/inici');
check('les dades del jugador', playerCallbackData(7), 'dp:7');
check('tots els jugadors', playerCallbackData('all'), 'dp:all');
check('el comandament més llarg de la temporada cap', strlen(commandCallbackData('/dobleORes vot ParisSaintGermain')) <= 64, true);
check('el nom més llarg de jugador no cap', strlen('/data player ' . str_repeat('a', 100)) > 51, true);

$threw = false;
try {
    commandCallbackData('/data player ' . str_repeat('a', 100));
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('i el guarda el que no cap', $threw, true);

check('es reconeix el comandament', callbackCommand('c:/add RealMadrid'), '/add RealMadrid');
check('un altre prefix no', callbackCommand('dp:7'), null);
check('el jugador per id', callbackPlayerTarget('dp:7'), 7);
check('el jugador "all"', callbackPlayerTarget('dp:all'), 'all');
check('un id que no és número', callbackPlayerTarget('dp:abc'), null);
check('un prefix desconegut', callbackPlayerTarget('x:1'), null);

// ---------- el teclat ----------

echo "\n--- el teclat inline ---\n";
$keyboard = InlineMenu::fromCommands([['/inici', '/equips'], ['/normes']]);
$decoded  = json_decode($keyboard->toJson(), true)['inline_keyboard'];

check('tres botons en dues files', count($decoded), 2);
check('el primer text', $decoded[0][0]['text'], 'Inici');
check('el primer callback', $decoded[0][0]['callback_data'], 'c:/inici');
check('el segon text', $decoded[0][1]['text'], 'Equips');
check('el tercer text', $decoded[1][0]['text'], 'Normes');

$custom = InlineMenu::make([[InlineMenu::button('Tots', 'dp:all')]]);
$decodedCustom = json_decode($custom->toJson(), true)['inline_keyboard'];
check('un botó a mà', $decodedCustom[0][0], ['text' => 'Tots', 'callback_data' => 'dp:all']);
check('sense botons no hi ha teclat', InlineMenu::optional([]), null);

$threw = false;
try {
    InlineMenu::button('', 'dp:all');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('un botó sense text no val', $threw, true);

$long = InlineMenu::button(str_repeat('a', 100), 'dp:1');
check('un text massa llarg es retalla', mb_strlen($long['text']), 60);
check('i acaba amb punts suspensius', substr($long['text'], -3), '…');

// ---------- el menú d'inici ----------

echo "\n--- el menú d'inici ---\n";
check('dues files', count(startMenuRows()), 2);
check('la primera', startMenuRows()[0], ['/accions', '/data', '/equips']);
check('la segona', startMenuRows()[1], ['/normes', '/configuració']);

// ---------- el pont cap al router ----------

echo "\n--- del botó al comandament ---\n";
$player = new FakePlayerById();
$player->playersById = [
    7 => ['id' => 7, 'chat_id' => 555, 'name' => 'Ivan Arrachina'],
    8 => ['id' => 8, 'chat_id' => 556, 'name' => ''],
];

check('un equip', CallbackRouter::toArgs('c:/add RealMadrid', $player), ['/add', 'RealMadrid']);
check('un pot', CallbackRouter::toArgs('c:/data pot CHL_1', $player), ['/data', 'pot', 'CHL_1']);
check('una norma amb espai', CallbackRouter::toArgs('c:/normes bàsiques', $player), ['/normes', 'bàsiques']);
check('tots els jugadors', CallbackRouter::toArgs('dp:all', $player), ['/data', 'player', 'all']);
check('un jugador pel nom', CallbackRouter::toArgs('dp:7', $player), ['/data', 'player', 'Ivan Arrachina']);
check('un jugador sense nom no fa res', CallbackRouter::toArgs('dp:8', $player), null);
check('un prefix desconegut no fa res', CallbackRouter::toArgs('x:1', $player), null);
check('un comandament buit no fa res', CallbackRouter::toArgs('c:', $player), null);

echo "\n";
if ($failures === 0) {
    echo "TOTES LES COMPROVACIONS PASSEN\n";
    exit(0);
}

echo "$failures COMPROVACIONS FALLIDES\n";
exit(1);
