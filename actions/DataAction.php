<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../config/pots.php');
require_once(__DIR__ . '/../config/settings.php');

use TelegramBot\Api\BotApi;

/**
 * Consultes de dades: /data.
 *
 *   /data                  menú
 *   /data pots             quants jugadors té cada equip, pot a pot
 *   /data pot CHL_1        el detall d'un pot (CHL_1...COL_4)
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
        $keyboard = InlineMenu::fromCommands([
            ['/data pots', '/data players'],
            ['/classificacio', '/classificacio competicions'],
        ]);

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
     * /data pots: un botó per a cada pot, amb l'etiqueta de la competició.
     */
    private function showPots(): void
    {
        $rows = [];
        $row  = [];

        foreach (potLabels() as $label) {
            $row[] = '/data pot ' . $label;

            if (count($row) === 4) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        $keyboard = InlineMenu::fromCommands($rows);

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
     * /data pot CHL_1: quants jugadors té cada equip del pot i qui són.
     *
     * @param string $potArgument Etiqueta del pot (CHL_1...COL_4) o número global antic.
     */
    private function showPot(string $potArgument): void
    {
        $globalPot = potFromLabel($potArgument);

        if ($globalPot === null) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot number not found");

            return;
        }

        $teams   = $this->teamsRepo->getCountPlayerTeamsByPot($globalPot);
        $message = 'Equips del pot ' . potMenuLabel(potLabel($globalPot)) . ":\n";

        foreach ($teams as $team) {
            $message .= $team['total'] . " " . $team['name'] . "\n";

            foreach ($this->teamsRepo->getPlayersByTeam($team['id']) as $player) {
                $message .= "   - " . $player['name'] . "\n";
            }

            $message .= "\n";
        }

        // El detall d'un pot és una pantalla final: sense botó, l'única eixida
        // seria /inici i tornar a entrar a Dades. El botó torna a la llista.
        $keyboard = InlineMenu::make([
            [InlineMenu::button('Tornar als pots', commandCallbackData('/data pots'))],
        ]);

        $this->telegram->sendMessage(
            $this->chatId,
            $message,
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * /data players: els participants, amb botons per vore'n la plantilla.
     */
    private function showPlayers(): void
    {
        $players = $this->playersRepo->getAllPlayers();
        $rows    = [];
        $row     = [InlineMenu::button('Tots', playerCallbackData('all'))];

        foreach ($players as $player) {
            // Un jugador sense nom no es pot consultar per /data player, i el
            // nom pot passar de 64 bytes: per això el botó porta l'id.
            $name = (string) ($player['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $row[] = InlineMenu::button($name, playerCallbackData((int) $player['id']));

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        $keyboard = InlineMenu::make($rows);

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
