<?php

require_once(__DIR__ . '/../config/menus.php');
require_once(__DIR__ . '/../models/player.php');

/**
 * El pont entre un botó inline i el comandament que executava el bot.
 *
 * Un callback_data no és text lliure del jugador: només pot vindre dels botons
 * que ha enviat el bot. Tot i així, qui faça un client propi el pot inventar,
 * així que ací només es reconeixen els dos formats i la resta es descarta. Les
 * validacions de propietat i de regles continuen sent cosa de cada acció.
 *
 *   c:/add RealMadrid   => ['/add', 'RealMadrid']
 *   dp:7                => ['/data', 'player', 'Ivan Arrachina']
 *   dp:all              => ['/data', 'player', 'all']
 */
final class CallbackRouter
{
    /**
     * @return array<int, string>|null Arguments equivalents al comandament, o
     *         null si el callback_data no és dels nostres.
     */
    public static function toArgs(string $data, Player $playersRepo): ?array
    {
        $command = callbackCommand($data);

        if ($command !== null) {
            $args = explode(' ', $command);

            return ($args[0] ?? '') === '' ? null : $args;
        }

        $target = callbackPlayerTarget($data);

        if ($target === 'all') {
            return ['/data', 'player', 'all'];
        }

        if (is_int($target)) {
            $player = $playersRepo->getPlayerById($target);
            $name   = (string) ($player[0]['name'] ?? '');

            // Un jugador sense nom no es pot consultar per /data player: el
            // botó no ha d'existir, i si arriba, no es fa res.
            if ($name === '') {
                return null;
            }

            return ['/data', 'player', $name];
        }

        return null;
    }
}
