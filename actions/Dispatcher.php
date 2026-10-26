<?php

require_once(__DIR__ . '/AddAction.php');
require_once(__DIR__ . '/ActionsMenuAction.php');
require_once(__DIR__ . '/BadDayAction.php');
require_once(__DIR__ . '/BestFinalAction.php');
require_once(__DIR__ . '/DataAction.php');
require_once(__DIR__ . '/DoubleOrNothingAction.php');
require_once(__DIR__ . '/IAmTheBestAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/KosAndShieldsAction.php');
require_once(__DIR__ . '/RulesAction.php');
require_once(__DIR__ . '/SettingsAction.php');
require_once(__DIR__ . '/StartAction.php');
require_once(__DIR__ . '/SubstitutionAction.php');
require_once(__DIR__ . '/SureToBeQualifiedAction.php');
require_once(__DIR__ . '/TeamsAction.php');
require_once(__DIR__ . '/WinOrDieAction.php');

use TelegramBot\Api\BotApi;

/**
 * El router del bot, tret de basic.php.
 *
 * basic.php era el webhook i, alhora, el router i quatre comandaments. Ací
 * només hi ha el router: rep els arguments d'un missatge (o els que ha
 * reconstruït CallbackRouter a partir d'un botó) i llança l'acció que toca.
 *
 * Les accions no criden exit(): tornen, i el webhook acaba quan acaba
 * dispatch().
 */
final class Dispatcher
{
    private BotApi $telegram;
    private Player $playersRepo;
    private Team $teamsRepo;
    private Substitution $substitutionsRepo;
    private Action $actionsRepo;
    private int $matchDay;
    private int $lastMatchDayWithChanges;
    private bool $substitutionsOpen;
    private bool $actionsOpen;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Team $teamsRepo,
        Substitution $substitutionsRepo,
        Action $actionsRepo,
        int $matchDay,
        int $lastMatchDayWithChanges,
        bool $substitutionsOpen,
        bool $actionsOpen
    ) {
        $this->telegram                = $telegram;
        $this->playersRepo             = $playersRepo;
        $this->teamsRepo               = $teamsRepo;
        $this->substitutionsRepo       = $substitutionsRepo;
        $this->actionsRepo             = $actionsRepo;
        $this->matchDay                = $matchDay;
        $this->lastMatchDayWithChanges = $lastMatchDayWithChanges;
        $this->substitutionsOpen       = $substitutionsOpen;
        $this->actionsOpen             = $actionsOpen;
    }

    /**
     * Executa el comandament d'uns arguments ja separats.
     *
     * @param array<int, string> $args El primer element és el comandament.
     */
    public function dispatch(array $args, int $chatId): void
    {
        $command = $args[0] ?? '';

        if (SubstitutionAction::handlesCommand($command)) {
            (new SubstitutionAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $this->substitutionsRepo,
                $chatId,
                $this->matchDay,
                $this->lastMatchDayWithChanges,
                $this->substitutionsOpen,
                $args
            ))->run();

            return;
        }

        if (DoubleOrNothingAction::handlesCommand($command)) {
            (new DoubleOrNothingAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (KosAndShieldsAction::handlesCommand($command)) {
            (new KosAndShieldsAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (SureToBeQualifiedAction::handlesCommand($command)) {
            (new SureToBeQualifiedAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (BestFinalAction::handlesCommand($command)) {
            (new BestFinalAction(
                $this->telegram,
                $this->playersRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (BadDayAction::handlesCommand($command)) {
            (new BadDayAction(
                $this->telegram,
                $this->playersRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (IAmTheBestAction::handlesCommand($command)) {
            (new IAmTheBestAction(
                $this->telegram,
                $this->playersRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (WinOrDieAction::handlesCommand($command)) {
            (new WinOrDieAction(
                $this->telegram,
                $this->playersRepo,
                $this->actionsRepo,
                $chatId,
                $this->matchDay,
                $args,
                $this->actionsOpen
            ))->run();

            return;
        }

        if (SettingsAction::handlesCommand($command)) {
            (new SettingsAction(
                $this->telegram,
                $this->playersRepo,
                $chatId,
                $this->matchDay,
                $args
            ))->run();

            return;
        }

        if (DataAction::handlesCommand($command)) {
            (new DataAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $chatId,
                $args
            ))->run();

            return;
        }

        if (RulesAction::handlesCommand($command)) {
            (new RulesAction($this->telegram, $chatId, $args))->run();

            return;
        }

        if (StartAction::handlesCommand($command)) {
            (new StartAction($this->telegram, $chatId, $args))->run();

            return;
        }

        if (TeamsAction::handlesCommand($command)) {
            (new TeamsAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $chatId,
                $args
            ))->run();

            return;
        }

        if (AddAction::handlesCommand($command)) {
            (new AddAction(
                $this->telegram,
                $this->playersRepo,
                $this->teamsRepo,
                $chatId,
                $args
            ))->run();

            return;
        }

        if (ActionsMenuAction::handlesCommand($command)) {
            (new ActionsMenuAction(
                $this->telegram,
                $chatId,
                $args,
                $this->substitutionsOpen,
                $this->actionsOpen
            ))->run();

            return;
        }

        // Text que no és cap comandament. Abans deia "Comença utilitzant els
        // botons -> /inici"; ara el botó d'inici viatja dins del missatge.
        $this->telegram->sendMessage(
            $chatId,
            "No t'he entés. Tria una opció:",
            false,
            null,
            null,
            InlineMenu::fromCommands([['/inici']])
        );
    }
}
