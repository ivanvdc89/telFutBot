<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/../config/pots.php');
require_once(__DIR__ . '/../config/settings.php');

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Consultes de dades: /data.
 *
 *   /data                  menú
 *   /data pots             quants jugadors té cada equip, pot a pot
 *   /data pot <1-12>       el detall d'un pot
 *   /data players          els noms dels participants, com a botons
 *   /data player all       la plantilla de tothom
 *   /data player <nom>     la plantilla d'un jugador
 *
 * És només de lectura i no demana cap jugador (no estén de PlayerAction): /data
 * el pot fer servir qualsevol, també qui encara no ha triat equips.
 *
 * Els noms dels jugadors poden tenir espais ("Ivan Arrachina") i els botons de
 * /data players porten el nom sencer; per això /data player agafa tots els mots
 * que venen després del subcomandament, no només el primer.
 */
class DataAction extends CommandAction
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
        return ['/data'];
    }

    public function run(): void
    {
        $subCommand = $this->subCommand();

        if ($subCommand === '') {
            $this->showMenu();

            return;
        }

        if ($subCommand === 'pots') {
            $this->showPots();

            return;
        }

        if ($subCommand === 'pot') {
            $this->showPot($this->argument(2));

            return;
        }

        if ($subCommand === 'players') {
            $this->showPlayers();

            return;
        }

        if ($subCommand === 'player') {
            $this->showPlayer();

            return;
        }

        $this->telegram->sendMessage($this->chatId, "ERROR, data command not found");
    }

    /**
     * /data: què es pot consultar.
     */
    private function showMenu(): void
    {
        $keyboard = new ReplyKeyboardMarkup([['/data pots', '/data players']], true, true);

        $this->telegram->sendMessage(
            $this->chatId,
            "Data disponible:",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * /data pots: un botó per a cada pot global.
     */
    private function showPots(): void
    {
        $keyboard = new ReplyKeyboardMarkup(
            [
                ['/data pot 1', '/data pot 2', '/data pot 3', '/data pot 4'],
                ['/data pot 5', '/data pot 6', '/data pot 7', '/data pot 8'],
                ['/data pot 9', '/data pot 10', '/data pot 11', '/data pot 12'],
            ],
            true,
            true
        );

        $this->telegram->sendMessage(
            $this->chatId,
            "Pots disponibles:",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * /data pot <1-12>: quants jugadors té cada equip del pot i qui són.
     *
     * @param string $potArgument Número de pot tal com ha arribat.
     */
    private function showPot(string $potArgument): void
    {
        if (!is_numeric($potArgument) || (int) $potArgument < 1 || (int) $potArgument > 12) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot number not found");

            return;
        }

        $teams   = $this->teamsRepo->getCountPlayerTeamsByPot($potArgument);
        $message = 'Equipos del pot ' . $potArgument . ":\n";

        foreach ($teams as $team) {
            $message .= $team['total'] . " " . $team['name'] . "\n";

            foreach ($this->teamsRepo->getPlayersByTeam($team['id']) as $player) {
                $message .= "   - " . $player['name'] . "\n";
            }

            $message .= "\n";
        }

        $this->telegram->sendMessage($this->chatId, $message);
    }

    /**
     * /data players: els participants, amb botons per vore'n la plantilla.
     */
    private function showPlayers(): void
    {
        $players = $this->playersRepo->getAllPlayers();
        $rows    = [];
        $row     = [];
        $row[]   = '/data player all';

        foreach ($players as $player) {
            $row[] = '/data player ' . $player['name'];

            if (count($row) == 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) != 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $this->telegram->sendMessage(
            $this->chatId,
            "Jugadors participants:",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * /data player <nom>|all: la plantilla d'un jugador o de tots.
     */
    private function showPlayer(): void
    {
        $playerName = normalizePlayerName(implode(' ', array_slice($this->args, 2)));

        if ($playerName === '') {
            $this->telegram->sendMessage($this->chatId, "ERROR, player not found");

            return;
        }

        if ($playerName === 'all') {
            $message = 'Tots els jugadors:';

            foreach ($this->playersRepo->getAllPlayers() as $player) {
                $message .= "\n\n" . $player['name'] . ":\n"
                    . $this->teamsText($this->teamsRepo->getTeamsByPlayerId($player['id']));
            }

            $this->telegram->sendMessage($this->chatId, $message);

            return;
        }

        $player = $this->playersRepo->getPlayerByName($playerName);

        if (!is_array($player) || count($player) == 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, player not found");

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            $player[0]['name'] . ":\n" . $this->teamsText($this->teamsRepo->getTeamsByPlayerId($player[0]['id']))
        );
    }

    /**
     * Una línia per equip: nom, competició i pot dins de la competició.
     *
     * @param array $teams Equips del jugador, tal com surten del model.
     */
    private function teamsText(array $teams): string
    {
        $text = '';

        foreach ($teams as $team) {
            $text .= $team['name'] . " (" . $team['competition']
                . " Pot " . POT_NUMBERS[$team['pot']] . ")\n";
        }

        return $text;
    }
}
