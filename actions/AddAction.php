<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/PotTeamsMenu.php');
require_once(__DIR__ . '/../config/pots.php');
require_once(__DIR__ . '/../models/player.php');
require_once(__DIR__ . '/../models/team.php');

use TelegramBot\Api\BotApi;

/**
 * Afegir un equip: /add <equip>.
 *
 * La fa servir el botó de /equips, que ja porta el nom de l'equip; escrita a
 * mà també funciona. Comprova el pot i el país i, si tot va bé, mostra el pot
 * següent.
 *
 * La lògica i els missatges són els que hi havia dins de basic.php. Només se
 * n'ha tret la construcció del teclat, que ara és inline.
 */
class AddAction extends CommandAction
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
        return ['/add'];
    }

    public function run(): void
    {
        $player = $this->playersRepo->getPlayerByChatId($this->chatId);

        if (is_array($player) && count($player) === 0) {
            $playerId = (int) $this->playersRepo->createPlayer($this->chatId);
        } else {
            $playerId = (int) $player[0]['id'];
        }

        if (!isset($this->args[1])) {
            $this->telegram->sendMessage($this->chatId, "ERROR, tria un equip dels botons.");

            return;
        }

        $newTeam = $this->teamsRepo->getTeamByName($this->args[1]);

        if (!is_array($newTeam) || count($newTeam) !== 1) {
            $this->telegram->sendMessage($this->chatId, "ERROR, team not found");

            return;
        }

        $newTeamPot     = $newTeam[0]['pot'];
        $newTeamCountry = $newTeam[0]['country'];

        $alreadyAddedTeams     = $this->teamsRepo->getTeamsByPlayerId($playerId);
        $alreadyAddedPots      = array_map(function ($team) { return $team['pot']; }, $alreadyAddedTeams);
        $alreadyAddedCountries = array_map(function ($team) { return $team['country']; }, $alreadyAddedTeams);

        if (in_array($newTeamPot, $alreadyAddedPots)) {
            $this->telegram->sendMessage(
                $this->chatId,
                "ERROR, ja tens un equip del pot " . potMenuLabel(potLabel($newTeamPot))
            );

            return;
        }

        if (in_array($newTeamCountry, $alreadyAddedCountries)) {
            $this->telegram->sendMessage($this->chatId, "ERROR, $newTeamCountry repeated");

            return;
        }

        $this->teamsRepo->addPlayerTeam($playerId, $newTeam[0]['id']);
        $this->telegram->sendMessage($this->chatId, $this->args[1] . " team added");

        ['pot' => $nextPot, 'rows' => $rows] = PotTeamsMenu::next($this->teamsRepo, $playerId);

        if ($nextPot === null) {
            $this->telegram->sendMessage($this->chatId, "Ja has elegit tots els teus equips!");

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
