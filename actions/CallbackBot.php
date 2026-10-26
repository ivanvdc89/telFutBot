<?php

require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\ReplyKeyboardRemove;

/**
 * El BotApi d'una petició que ve d'un botó inline.
 *
 * Quan el jugador polsa un botó, l'acció envia un o més missatges nous. Dos
 * detalls fan falta perquè el xat no es quede a mitges:
 *
 *   1. El missatge del menú vell s'ha de quedar sense botons, o es podria
 *      tornar a polsar un vot quan ja no toca.
 *   2. Si l'últim missatge que ha enviat l'acció no porta botons (un error, una
 *      confirmació, una dada), el jugador es quedaria sense cap camí. finish()
 *      li n'afegix un parell, "Accions" i "Inici".
 *
 * Es fa heredant BotApi perquè les accions continuen rebent un BotApi i no cal
 * canviar-ne cap constructor ni cap doble de proves.
 */
class CallbackBot extends BotApi
{
    private int $chatId;
    private int $sourceMessageId;

    private ?int $lastMessageId = null;

    /** Si l'últim missatge enviat ja duia teclat propi. */
    private bool $lastHadKeyboard = false;

    public function __construct(string $token, int $chatId, int $sourceMessageId)
    {
        parent::__construct($token);

        $this->chatId          = $chatId;
        $this->sourceMessageId = $sourceMessageId;
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
        $message = parent::sendMessage(
            $chatId,
            $text,
            $parseMode,
            $disablePreview,
            $replyToMessageId,
            $replyMarkup,
            $disableNotification,
            $messageThreadId,
            $protectContent,
            $allowSendingWithoutReply
        );

        $this->recordMessage($message, $replyMarkup);

        return $message;
    }

    /**
     * Les fotos de /normes també compten: si l'última cosa que veu el jugador és
     * una foto, el menú s'hi afig a eixa foto i no cal un missatge de més.
     */
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
        $message = parent::sendPhoto(
            $chatId,
            $photo,
            $caption,
            $replyToMessageId,
            $replyMarkup,
            $disableNotification,
            $parseMode,
            $messageThreadId,
            $protectContent,
            $allowSendingWithoutReply
        );

        $this->recordMessage($message, $replyMarkup);

        return $message;
    }

    /**
     * Guarda l'últim missatge per a poder-hi posar un camí al final.
     */
    private function recordMessage(Message $message, $replyMarkup): void
    {
        $this->lastMessageId = $message->getMessageId();

        // ReplyKeyboardRemove no és un menú: només lleva el teclat vell. Si el
        // missatge no rep botons després, finish() n'hi ha de posar.
        $this->lastHadKeyboard = $replyMarkup !== null
            && !($replyMarkup instanceof ReplyKeyboardRemove);
    }

    /**
     * Un botó pot canviar el teclat del missatge que acaba d'enviar (el menú
     * d'inici ho fa): aleshores eixe teclat sí que compta.
     */
    public function editMessageReplyMarkup(
        $chatId,
        $messageId,
        $replyMarkup = null,
        $inlineMessageId = null
    ) {
        $result = parent::editMessageReplyMarkup($chatId, $messageId, $replyMarkup, $inlineMessageId);

        if ($messageId === $this->lastMessageId) {
            $this->lastHadKeyboard = $replyMarkup !== null;
        }

        return $result;
    }

    /**
     * Lleva els botons del missatge que s'ha polsat.
     */
    public function removeSourceKeyboard(): void
    {
        try {
            parent::editMessageReplyMarkup($this->chatId, $this->sourceMessageId, null);
        } catch (Exception $e) {
            // El missatge pot ser massa vell per a editar-lo. No és cap error
            // del joc i no ha d'impedir l'acció.
        }
    }

    /**
     * Tanca la petició: si l'últim missatge no oferia cap camí, n'hi afig.
     */
    public function finish(): void
    {
        if ($this->lastMessageId === null) {
            $this->sendMessage(
                $this->chatId,
                "Tria una opció:",
                false,
                null,
                null,
                self::defaultKeyboard()
            );

            return;
        }

        if ($this->lastHadKeyboard) {
            return;
        }

        try {
            parent::editMessageReplyMarkup(
                $this->chatId,
                $this->lastMessageId,
                self::defaultKeyboard()
            );
        } catch (Exception $e) {
            // Si no es pot editar, el missatge es queda com estava: és pitjor
            // fallar ací que no haver fet l'acció.
        }
    }

    private static function defaultKeyboard(): InlineKeyboardMarkup
    {
        return InlineMenu::fromCommands([['/accions', '/inici']]);
    }
}
