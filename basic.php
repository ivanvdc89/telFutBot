<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("config/secrets.php");
require_once("config/matchDay.php");
require_once("config/actions.php");
require_once("config/settings.php");
require_once("config/pots.php");
require_once("models/player.php");
require_once("models/team.php");
require_once("models/substitution.php");
require_once("models/action.php");
require_once("models/matchDayPlayerPoint.php");
require_once("actions/Dispatcher.php");
require_once("actions/CallbackRouter.php");
require_once("actions/CallbackBot.php");

use TelegramBot\Api\BotApi;

/**
 * El webhook del bot.
 *
 * Ací només es decidix qui executa cada petició. Els comandaments i les seues
 * respostes viuen a actions/, i el fil d'execució a Dispatcher. Els botons
 * inline arriben com a callback_query: CallbackRouter els torna a convertir en
 * els arguments del comandament de sempre.
 */

$telegram = new BotApi(secret('telegram_token'));

$update = json_decode(file_get_contents('php://input'));

$playersRepo       = new Player();
$substitutionsRepo = new Substitution();
$teamsRepo         = new Team();
$actionsRepo       = new Action();
$pointsRepo        = new MatchDayPlayerPoint();

$matchDay = currentMatchDay();

/**
 * @param BotApi $telegram
 */
function makeDispatcher(
    BotApi $telegram,
    Player $playersRepo,
    Team $teamsRepo,
    Substitution $substitutionsRepo,
    Action $actionsRepo,
    MatchDayPlayerPoint $pointsRepo,
    int $matchDay
): Dispatcher {
    return new Dispatcher(
        $telegram,
        $playersRepo,
        $teamsRepo,
        $substitutionsRepo,
        $actionsRepo,
        $pointsRepo,
        $matchDay,
        LAST_MATCH_DAY_WITH_CHANGES,
        SUBSTITUTIONS_OPEN,
        ACTIONS_OPEN,
        KOS_ACTIVE
    );
}

if (isset($update->callback_query)) {
    $callback = $update->callback_query;
    $message  = $callback->message ?? null;

    // Els botons només existixen al xat privat. Si arriba una altra cosa, no
    // es toca res.
    if (!isset($message->chat->id) || ($message->chat->type ?? '') !== 'private') {
        exit;
    }

    $chatId  = (int) $message->chat->id;
    $messageId = (int) $message->message_id;

    // L' spinner del botó s'ha d'aturar sempre, encara que després l'acció
    // falla o el callback_data no siga dels nostres.
    try {
        $telegram->answerCallbackQuery((string) ($callback->id ?? ''));
    } catch (Exception $e) {
        // Sense resposta a temps, Telegram el lleva sol.
    }

    $callbackBot = new CallbackBot(secret('telegram_token'), $chatId, $messageId);
    $callbackBot->removeSourceKeyboard();

    $args = CallbackRouter::toArgs((string) ($callback->data ?? ''), $playersRepo);

    if ($args !== null) {
        makeDispatcher(
            $callbackBot,
            $playersRepo,
            $teamsRepo,
            $substitutionsRepo,
            $actionsRepo,
            $pointsRepo,
            $matchDay
        )->dispatch($args, $chatId);
    }

    $callbackBot->finish();

    exit;
}

if (isset($update->message->text) && $update->message->chat->type === 'private') {
    $chatId = (int) $update->message->chat->id;
    $text   = (string) $update->message->text;
    $args   = explode(' ', $text);

    // El nom es demana amb ForceReply: si el missatge respon a eixa petició,
    // el que ha escrit el jugador és el nom, no un comandament.
    $repliedText = (string) ($update->message->reply_to_message->text ?? '');
    $nameArgs    = nameReplyArgs($text, $repliedText);

    if ($nameArgs !== null) {
        $args = $nameArgs;
    }

    makeDispatcher(
        $telegram,
        $playersRepo,
        $teamsRepo,
        $substitutionsRepo,
        $actionsRepo,
        $pointsRepo,
        $matchDay
    )->dispatch($args, $chatId);
}
