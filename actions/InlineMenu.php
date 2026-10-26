<?php

require_once(__DIR__ . '/../config/menus.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Els teclats inline del bot.
 *
 * Les accions continuen construint files de comandaments, com feien amb els
 * teclats de resposta ("/add RealMadrid", "/in EquipA"...). Ací es convertixen
 * en botons amb un text humà i el comandament dins del callback_data, de manera
 * que el router els puga tornar a executar sense canviar cap acció.
 *
 * També hi ha make() per als pocs botons que no porten un comandament sencer:
 * els jugadors de /data players, que es desen per id.
 */
final class InlineMenu
{
    /**
     * Llargada màxima del text d'un botó.
     *
     * Telegram no documenta un límit clar per al text, però un nom de jugador
     * pot fer 100 caràcters i no volem que un nom llarg trenque la llista de
     * tots. El callback_data no es toca: el nom sencer es resol pel id.
     */
    private const MAX_LABEL_LENGTH = 60;

    /**
     * @param array<int, array<int, string>> $rows Files de comandaments.
     */
    public static function fromCommands(array $rows): InlineKeyboardMarkup
    {
        $keyboard = [];

        foreach ($rows as $row) {
            $keyboardRow = [];

            foreach ($row as $command) {
                $keyboardRow[] = self::button(menuLabel($command), commandCallbackData($command));
            }

            $keyboard[] = $keyboardRow;
        }

        return new InlineKeyboardMarkup($keyboard);
    }

    /**
     * Un teclat amb botons ja construïts amb button().
     *
     * @param array<int, array<int, array{text: string, callback_data: string}>> $rows
     */
    public static function make(array $rows): InlineKeyboardMarkup
    {
        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Com fromCommands(), però null quan no hi ha cap botó.
     *
     * Telegram no vol un teclat inline buit, i un teclat de resposta buit sí que
     * valia (llevava el teclat). Els llocs on la llista pot quedar buida fan
     * servir este mètode.
     *
     * @param array<int, array<int, string>> $rows
     */
    public static function optional(array $rows): ?InlineKeyboardMarkup
    {
        return count($rows) === 0 ? null : self::fromCommands($rows);
    }

    /**
     * Afig una fila al final d'un teclat ja construït.
     *
     * @param array<int, array{text: string, callback_data: string}> $buttons
     */
    public static function appendRow(InlineKeyboardMarkup $keyboard, array $buttons): InlineKeyboardMarkup
    {
        $rows   = $keyboard->getInlineKeyboard();
        $rows[] = $buttons;

        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Un botó inline.
     *
     * Valida les dos coses que Telegram rebutja sense dir per què: un text buit
     * i un callback_data de més de 64 bytes.
     *
     * @return array{text: string, callback_data: string}
     */
    public static function button(string $label, string $data): array
    {
        if ($label === '') {
            throw new InvalidArgumentException('Un botó no pot tindre el text buit.');
        }

        if (strlen($data) === 0 || strlen($data) > 64) {
            throw new InvalidArgumentException(
                'El callback_data "' . $data . '" no té entre 1 i 64 bytes.'
            );
        }

        return ['text' => self::fitLabel($label), 'callback_data' => $data];
    }

    /**
     * Un text de botó que no es passe de llarg.
     */
    private static function fitLabel(string $label): string
    {
        if (mb_strlen($label) <= self::MAX_LABEL_LENGTH) {
            return $label;
        }

        return mb_substr($label, 0, self::MAX_LABEL_LENGTH - 1) . '…';
    }
}
