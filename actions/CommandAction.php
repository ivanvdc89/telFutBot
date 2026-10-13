<?php

use TelegramBot\Api\BotApi;

/**
 * Base de totes les accions del bot.
 *
 * Una acció és un comandament (o uns quants) del xat privat: declara quins
 * atén a commands() i fa la seua feina a run(). Res més. Qui decideix acabar la
 * petició és el router de basic.php, i per això cap acció no crida exit().
 *
 *     class MevaAccio extends CommandAction
 *     {
 *         protected static function commands(): array
 *         {
 *             return ['/meva'];
 *         }
 *
 *         public function run(): void
 *         {
 *             ...
 *         }
 *     }
 *
 * Dues famílies d'accions:
 *   - CommandAction, aquí: les que no depenen de cap jugador (/data, /normes).
 *   - PlayerAction, que hi afegeix el jugador darrere del xat i la jornada.
 *
 * Una instància viu una sola petició del webhook i rep les dependències pel
 * constructor.
 */
abstract class CommandAction
{
    protected BotApi $telegram;
    protected int $chatId;
    protected array $args;

    public function __construct(BotApi $telegram, int $chatId, array $args)
    {
        $this->telegram = $telegram;
        $this->chatId   = $chatId;
        $this->args     = $args;
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
     * Punt d'entrada de l'acció.
     */
    abstract public function run(): void;

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
}
