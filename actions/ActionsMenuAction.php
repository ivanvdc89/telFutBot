<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../config/actions.php');

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardRemove;

/**
 * El menú d'accions: /accions i /actions.
 *
 * Les files surten dels interruptors de la temporada (actionsMenuRows), no d'una
 * llista escrita a mà: tancar una fase la trau del menú tota sola. El que canvia
 * respecte de basic.php és que els botons són inline i tenen text humà.
 */
class ActionsMenuAction extends CommandAction
{
    private bool $substitutionsOpen;
    private bool $actionsOpen;
    private string $kosActive;

    public function __construct(
        BotApi $telegram,
        int $chatId,
        array $args,
        bool $substitutionsOpen,
        bool $actionsOpen,
        string $kosActive
    ) {
        parent::__construct($telegram, $chatId, $args);

        $this->substitutionsOpen = $substitutionsOpen;
        $this->actionsOpen       = $actionsOpen;
        $this->kosActive         = $kosActive;
    }

    protected static function commands(): array
    {
        return ['/accions', '/actions'];
    }

    public function run(): void
    {
        $rows = actionsMenuRows($this->substitutionsOpen, $this->actionsOpen, $this->kosActive);

        if (count($rows) === 0) {
            // Passa a l'estona d'aplicar i publicar els canvis. Cal llevar el
            // teclat vell, que si no es queda oferint coses que ja no es poden
            // fer.
            $this->telegram->sendMessage(
                $this->chatId,
                "Ara mateix no hi ha cap acció disponible.",
                false,
                null,
                null,
                new ReplyKeyboardRemove()
            );

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "Accions disponibles:",
            false,
            null,
            null,
            InlineMenu::fromCommands($rows)
        );
    }
}
