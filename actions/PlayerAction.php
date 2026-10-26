<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');

use TelegramBot\Api\BotApi;

/**
 * Base de les accions del bot que necessiten saber quin jugador hi ha darrere
 * del xat.
 *
 * Totes comparteixen la mateixa execució: esbrinar quin jugador hi ha darrere
 * del xat i, si encara no ha passat per /equips, dir-l'hi. Això és run(), que
 * no es pot sobreescriure: cada acció només ha d'omplir handle() amb el que li
 * és propi i declarar els seus comandaments a commands().
 *
 *     class MevaAccio extends PlayerAction
 *     {
 *         protected static function commands(): array
 *         {
 *             return ['/meva'];
 *         }
 *
 *         protected function handle(int $playerId): void
 *         {
 *             ...
 *         }
 *     }
 *
 * El que no depèn del jugador és a CommandAction, i el que no depèn de res
 * (els pots de la temporada) a config/pots.php.
 */
abstract class PlayerAction extends CommandAction
{
    protected Player $playersRepo;
    protected int $matchDay;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        int $chatId,
        int $matchDay,
        array $args
    ) {
        parent::__construct($telegram, $chatId, $args);

        $this->playersRepo = $playersRepo;
        $this->matchDay    = $matchDay;
    }

    /**
     * Punt d'entrada de l'acció: resol el jugador i delega a handle().
     */
    final public function run(): void
    {
        $playerId = $this->getPlayerId();

        if ($playerId === null) {
            $this->sendNotRegistered();

            return;
        }

        $this->handle($playerId);
    }

    /**
     * El que fa l'acció, amb el jugador ja resolt.
     */
    abstract protected function handle(int $playerId): void;

    /**
     * Id del jugador associat al xat, o null si encara no existeix.
     */
    protected function getPlayerId(): ?int
    {
        $player = $this->playersRepo->getPlayerByChatId($this->chatId);

        if (!isset($player[0]['id'])) {
            return null;
        }

        return (int) $player[0]['id'];
    }

    /**
     * El jugador encara no ha passat per /equips: no té equip i no pot fer res.
     */
    protected function sendNotRegistered(): void
    {
        $keyboard = InlineMenu::fromCommands([['/equips', '/inici']]);

        $this->telegram->sendMessage(
            $this->chatId,
            "Encara no has triat cap equip. Toca \"Equips\" per començar.",
            false,
            null,
            null,
            $keyboard
        );
    }
}
