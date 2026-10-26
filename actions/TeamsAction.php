<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/PotTeamsMenu.php');
require_once(__DIR__ . '/../config/pots.php');
require_once(__DIR__ . '/../models/player.php');
require_once(__DIR__ . '/../models/team.php');

use TelegramBot\Api\BotApi;

/**
 * La tria d'equips: /equips i /teams.
 *
 * Un equip per pot, dotze en total, i cap país repetit. El jugador no ha
 * d'escriure res: els equips del pot següent li surten com a botons inline.
 *
 * La primera volta crea la fila de players. Abans eixa lògica vivia dins de
 * basic.php, junt amb la de /add; ara les dos accions comparteixen
 * PotTeamsMenu.
 */
class TeamsAction extends CommandAction
{
    private Player $playersRepo;
    private Team $teamsRepo;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Team $teamsRepo,
        int $chatId,
        array $args
    ) {
        parent::__construct($telegram, $chatId, $args);

        $this->playersRepo = $playersRepo;
        $this->teamsRepo   = $teamsRepo;
    }

    protected static function commands(): array
    {
        return ['/equips', '/teams'];
    }

    public function run(): void
    {
        $player = $this->playersRepo->getPlayerByChatId($this->chatId);

        if (is_array($player) && count($player) > 0) {
            $playerId = (int) $player[0]['id'];
        } else {
            $playerId = (int) $this->playersRepo->createPlayer($this->chatId);
        }

        ['pot' => $nextPot, 'rows' => $rows] = PotTeamsMenu::next($this->teamsRepo, $playerId);

        if ($nextPot === null) {
            $message = '';

            foreach ($this->teamsRepo->getTeamsByPlayerId($playerId) as $team) {
                $message .= $team['name'] . " (" . $team['competition']
                    . " Pot " . POT_NUMBERS[$team['pot']] . ")\n";
            }

            $this->telegram->sendMessage($this->chatId, $message);

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            POT_COMPETITIONS[$nextPot] . " Pot " . POT_NUMBERS[$nextPot] . " equips:",
            false,
            null,
            null,
            InlineMenu::optional($rows)
        );
    }
}
