<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../config/actions.php');

use TelegramBot\Api\Types\ReplyKeyboardRemove;

/**
 * El menú d'inici: /inici i /start.
 *
 * Abans este menú era un teclat de resposta amb els comandaments escrits. Ara
 * és un teclat inline, però els jugadors que vénen de la versió vella poden
 * tindre encara el teclat de resposta a la pantalla. Per això el primer
 * missatge porta ReplyKeyboardRemove(): lleva el teclat vell i, aprofitant que
 * ja tenim el missatge enviat, li canvia el teclat pel menú nou. Així no cal
 * enviar dos missatges.
 */
class StartAction extends CommandAction
{
    protected static function commands(): array
    {
        return ['/inici', '/start'];
    }

    public function run(): void
    {
        $keyboard = InlineMenu::fromCommands(startMenuRows());

        try {
            $message = $this->telegram->sendMessage(
                $this->chatId,
                "Que vols fer?",
                false,
                null,
                null,
                new ReplyKeyboardRemove()
            );

            $this->telegram->editMessageReplyMarkup(
                $this->chatId,
                $message->getMessageId(),
                $keyboard
            );
        } catch (Throwable $e) {
            // Si el servidor no pot editar el missatge, val més enviar el menú
            // en un altre que deixar el jugador sense botons.
            $this->telegram->sendMessage(
                $this->chatId,
                "Que vols fer?",
                false,
                null,
                null,
                $keyboard
            );
        }
    }
}
