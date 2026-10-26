<?php

/**
 * Els botons dels menús: què es veu i què viatja dins del botó.
 *
 * Fins ara els menús eren teclats de resposta amb comandaments visibles: el
 * jugador havia de vore "/dobleORes pot CHL_1" i entendre que allò era un botó.
 * Ara cada botó és un botó inline, amb un text humà, que porta dins el
 * comandament que s'hauria escrit. El router el torna a convertir en el mateix
 * comandament de sempre, així que les accions no canvien de comportament.
 *
 * Telegram limita callback_data a 64 bytes. Els comandaments amb nom d'equip hi
 * caben de sobres (el nom més llarg de la temporada fa 17 caràcters), però els
 * noms de jugador poden fer 100 i per això /data player no hi cap: fa servir
 * CALLBACK_PLAYER_PREFIX amb l'id i el router resol el nom.
 *
 * Tot açò és sense Telegram i sense base de dades, perquè es puga provar solt.
 * Una etiqueta o un prefix mal escrit es nota abans de desplegar res.
 */

require_once(__DIR__ . '/pots.php');

/**
 * Prefix del callback_data que porta un comandament sencer.
 *
 *     c:/add RealMadrid
 */
const CALLBACK_COMMAND_PREFIX = 'c:';

/**
 * Prefix del callback_data que porta l'id d'un jugador.
 *
 *     dp:7
 */
const CALLBACK_PLAYER_PREFIX = 'dp:';

/**
 * Etiquetes dels botons que no tenen arguments.
 *
 * Les competicions es queden amb CHL, EUL i COL: són les sigles que ja fan
 * servir els textos del joc (pots, substitucions, anuncis) i el jugador les
 * reconeix abans que un nom llarg.
 *
 * Les claus són el comandament exacte. Si un comandament no hi és, es prova
 * pels prefixos i, al final, es treu la barra.
 */
const MENU_LABELS = [
    '/inici'              => 'Inici',
    '/start'              => 'Inici',
    '/equips'             => 'Equips',
    '/teams'              => 'Equips',
    '/accions'            => 'Accions',
    '/actions'            => 'Accions',
    '/data'               => 'Dades',
    '/normes'             => 'Normes',
    '/rules'              => 'Normes',
    '/configuració'       => 'Configuració',
    '/settings'           => 'Configuració',
    '/nom'                => 'Canviar el nom',
    '/name'               => 'Canviar el nom',
    '/data pots'          => 'Pots',
    '/data players'       => 'Jugadors',
    '/data player all'    => 'Tots els jugadors',
    '/classificacio'      => 'Classificació general',
    '/classificacio competicions' => 'Competicions',
    '/normes bàsiques'    => 'Normes bàsiques',
    '/normes accions'     => 'Normes de les accions',
    '/substitució'        => 'Fer un canvi',
    '/substitució remove' => 'Eliminar els canvis',
    '/malDia'             => 'Mal dia',
    '/socElMillor'        => 'Soc el millor',
    '/guanyarOMorir'      => 'Guanyar o morir',
    '/dobleORes'          => 'Doble o res',
    '/dobleORes borrar'   => 'Esborrar els vots',
    '/kos'                => 'KO amb escuts',
    '/kos borrar'         => 'Esborrar vots i escuts',
    '/segurQuePasse Borrar' => 'Borrar l\'aposta',
    '/millorFinal borrar' => 'Borrar el vot',
];

/**
 * Prefixos de comandaments amb arguments on el text del botó és la resta.
 *
 * L'ordre importa: els casos amb un format propi (pots, escuts, activar) es
 * resolen abans d'arribar ací.
 */
const MENU_LABEL_PREFIXES = [
    '/add ',
    '/in ',
    '/passe ',
    '/dobleORes vot ',
    '/data player ',
];

/**
 * El text humà d'un botó a partir del comandament que llança.
 *
 *     menuLabel('/dobleORes pot CHL_1')  => 'CHL 1'
 *     menuLabel('/malDia Activar EUL')   => 'Activar EUL'
 *     menuLabel('/out CHL_Pot_2')        => 'CHL 2 (buit)'
 *
 * @param string $command Comandament tal com l'escriuria el jugador.
 */
function menuLabel(string $command): string
{
    if (isset(MENU_LABELS[$command])) {
        return MENU_LABELS[$command];
    }

    // /classificacio CHL, EUL o COL: el botó és només la competició.
    if (str_starts_with($command, '/classificacio ')) {
        return substr($command, strlen('/classificacio '));
    }

    foreach (['/malDia ', '/socElMillor ', '/guanyarOMorir '] as $prefix) {
        if (str_starts_with($command, $prefix)) {
            $rest = substr($command, strlen($prefix));

            // "Activar CHL" o "Desactivar COL".
            [$verb, $competition] = array_pad(explode(' ', $rest, 2), 2, '');

            return trim($verb . ' ' . $competition);
        }
    }

    if (str_starts_with($command, '/millorFinal ')) {
        return 'Final ' . substr($command, strlen('/millorFinal '));
    }

    if (str_starts_with($command, '/kos escut ')) {
        return 'Escut ' . substr($command, strlen('/kos escut '));
    }

    // Els tres menús que trien un pot.
    foreach (['/data pot ', '/dobleORes pot ', '/kos ko_pot '] as $prefix) {
        if (str_starts_with($command, $prefix)) {
            return potMenuLabel(substr($command, strlen($prefix)));
        }
    }

    if (str_starts_with($command, '/kos ko_vot ')) {
        return 'KO: ' . substr($command, strlen('/kos ko_vot '));
    }

    // /out pot ser un equip o un forat buit (CHL_Pot_1).
    if (str_starts_with($command, '/out ')) {
        $target = substr($command, strlen('/out '));

        return str_contains($target, '_Pot_') ? emptyPotMenuLabel($target) : $target;
    }

    foreach (MENU_LABEL_PREFIXES as $prefix) {
        if (str_starts_with($command, $prefix)) {
            return substr($command, strlen($prefix));
        }
    }

    // Un comandament sense etiqueta pròpia: almenys que no es veja la barra.
    return ltrim($command, '/');
}

/**
 * Etiqueta d'un pot: CHL_1 => "CHL 1".
 *
 * També accepta el número global antic (5 => "EUL 1"), perquè els teclats vells
 * que queden als xats continuen enviant eixe format.
 */
function potMenuLabel(string $pot): string
{
    $globalPot = potFromLabel($pot);

    if ($globalPot === null) {
        return $pot;
    }

    $label = potLabel($globalPot);

    if (!str_contains($label, '_')) {
        return $label;
    }

    [$competition, $number] = explode('_', $label, 2);

    return $competition . ' ' . $number;
}

/**
 * Etiqueta d'un forat buit: CHL_Pot_1 => "CHL 1 (buit)".
 */
function emptyPotMenuLabel(string $slot): string
{
    if (preg_match('/^(CHL|EUL|COL)_Pot_(\d)$/', $slot, $parts) === 1) {
        return $parts[1] . ' ' . $parts[2] . ' (buit)';
    }

    return $slot . ' (buit)';
}

/**
 * El callback_data d'un comandament.
 *
 * @throws InvalidArgumentException Si passa dels 64 bytes que admet Telegram.
 *         Passa només amb noms de jugador llargs: /data player els desa per id.
 */
function commandCallbackData(string $command): string
{
    $data = CALLBACK_COMMAND_PREFIX . $command;

    if (strlen($data) > 64) {
        throw new InvalidArgumentException(
            'El callback_data de "' . $command . '" passa de 64 bytes (' . strlen($data) . ').'
        );
    }

    return $data;
}

/**
 * El callback_data d'un jugador, o de "all" per a tots.
 */
function playerCallbackData(int|string $playerId): string
{
    return CALLBACK_PLAYER_PREFIX . $playerId;
}

/**
 * El comandament que hi ha dins d'un callback_data, o null si no n'hi ha cap.
 */
function callbackCommand(string $data): ?string
{
    if (!str_starts_with($data, CALLBACK_COMMAND_PREFIX)) {
        return null;
    }

    $command = substr($data, strlen(CALLBACK_COMMAND_PREFIX));

    return $command === '' ? null : $command;
}

/**
 * L'id del jugador (o "all") que hi ha dins d'un callback_data, o null.
 *
 * @return int|string|null
 */
function callbackPlayerTarget(string $data): int|string|null
{
    if (!str_starts_with($data, CALLBACK_PLAYER_PREFIX)) {
        return null;
    }

    $target = substr($data, strlen(CALLBACK_PLAYER_PREFIX));

    if ($target === 'all') {
        return 'all';
    }

    if ($target === '' || !ctype_digit($target)) {
        return null;
    }

    return (int) $target;
}
