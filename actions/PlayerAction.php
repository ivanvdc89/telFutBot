<?php

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Base de totes les accions del bot.
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
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
abstract class PlayerAction
{
    protected BotApi $telegram;
    protected Player $playersRepo;
    protected int $chatId;
    protected int $matchDay;
    protected array $args;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        int $chatId,
        int $matchDay,
        array $args
    ) {
        $this->telegram    = $telegram;
        $this->playersRepo = $playersRepo;
        $this->chatId      = $chatId;
        $this->matchDay    = $matchDay;
        $this->args        = $args;
    }

    /**
     * Comandaments que gestiona l'acció, per exemple ['/kos'].
     */
    abstract protected static function commands(): array;

    public static function handlesCommand(string $command): bool
    {
        return in_array($command, static::commands(), true);
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
     * Argument del missatge, o cadena buida si no hi és.
     */
    protected function argument(int $index): string
    {
        return (string) ($this->args[$index] ?? '');
    }

    /**
     * El comandament que ha disparat l'acció.
     */
    protected function command(): string
    {
        return $this->argument(0);
    }

    /**
     * Subcomandament: el segon mot del missatge, o cadena buida.
     */
    protected function subCommand(): string
    {
        return $this->argument(1);
    }

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
        $keyboard = new ReplyKeyboardMarkup([['/equips', '/inici']], true, true);

        $this->telegram->sendMessage(
            $this->chatId,
            "Encara no has triat cap equip. Fes /equips per començar.",
            false,
            null,
            null,
            $keyboard
        );
    }
}
