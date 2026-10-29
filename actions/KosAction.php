<?php

require_once(__DIR__ . '/ActionRowAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../config/pots.php');
require_once(__DIR__ . '/../config/menus.php');

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Base dels tres boicots de KO: #kosAmbEscuts (per equips), #kosPais (per
 * països) i #kosPots (per pots).
 *
 * Els tres comparteixen el mateix esquelet —una fila per jugador i jornada, un
 * màxim de vots i d'escuts que surt de la classificació general, la llista de
 * vots, els escuts i l'esborrat— i només canvien què es vota:
 *
 *     /kos       ko_pot CHL_1   ->  ko_vot EQUIP    escut CHL / escut EQUIP
 *     /kosPais   vot Spain                            escut CHL / escut EQUIP
 *     /kosPots   vot CHL_1                            escut CHL / escut EQUIP
 *
 * El que és propi de cada boicot són els ganxos: la clau del JSON on es desen
 * els vots (voteKey), com es mostra un vot (voteText), quins botons té el menú
 * (menuRows) i com es valida i desa un vot (registerVote). La resta viu ací.
 *
 * NOMÉS UN BOICOT RESPON. VOTACIO_ACTIVA (config/matchDay.php) diu quin està en joc
 * eixa jornada, i els altres dos contesten "No disponible": així el jugador no
 * pot votar en un boicot que no toca.
 *
 * La fila la crea el bot la primera vegada que el jugador obri l'acció. 'max'
 * és el nombre de vots que li toquen —els escuts tenen el mateix límit— i surt
 * de la classificació general (players.points): 1 vot per al líder i 1 més per
 * cada 10 punts de diferència, fins a 4.
 */
abstract class KosAction extends ActionRowAction
{
    /**
     * Competicions amb què es pot demanar la llista d'equips per escudar.
     */
    protected const COMPETITIONS = ['CHL', 'EUL', 'COL'];

    protected Team $teamsRepo;

    /**
     * Quin boicot està en joc esta jornada (el valor de VOTACIO_ACTIVA).
     */
    private string $activeKos;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Team $teamsRepo,
        Action $actionsRepo,
        int $chatId,
        int $matchDay,
        array $args,
        bool $actionsActivated,
        string $activeKos
    ) {
        parent::__construct($telegram, $playersRepo, $actionsRepo, $chatId, $matchDay, $args, $actionsActivated);

        $this->teamsRepo = $teamsRepo;
        $this->activeKos = $activeKos;
    }

    // ------------------------------------------------ el que canvia a cada boicot

    /**
     * Clau del JSON on es desen els vots: 'kos', 'paisos' o 'pots'.
     */
    abstract protected function voteKey(): string;

    /**
     * Capçalera de la llista de vots: 'Equips votats:', 'Països votats:'...
     */
    abstract protected function votesTitle(): string;

    /**
     * Com s'anomena el que es vota, per als avisos: 'kos', 'països' o 'pots'.
     */
    abstract protected function voteNoun(): string;

    /**
     * Títol del menú de votació.
     */
    abstract protected function menuTitle(): string;

    /**
     * Subcomandament per a votar un objectiu: 'ko_vot' o 'vot'.
     */
    abstract protected function voteCommand(): string;

    /**
     * Files de botons per a triar què es vota.
     *
     * @return array<int, array<int, string>>
     */
    abstract protected function menuRows(array $data, int $maxVotes): array;

    /**
     * Valida el vot del missatge i el desa, o explica per què no.
     */
    abstract protected function registerVote(int $actionId, array $data, int $maxVotes): void;

    /**
     * Un vot desat, en text.
     *
     * @param mixed $vote
     */
    abstract protected function voteText($vote): string;

    // ------------------------------------------------------------- l'esquelet

    protected function handle(int $playerId): void
    {
        if (!$this->actionsActivated || $this->activeKos !== $this->actionType()) {
            $this->sendNotAvailable();

            return;
        }

        $actions = $this->actionRows($playerId);

        if (count($actions) === 0) {
            // La fila la crea el bot la primera vegada que el jugador obre
            // l'acció; abans l'havia de sembrar algú a mà a la base de dades.
            $data     = $this->newRowData($playerId);
            $actionId = $this->createActionRow($playerId, $data);

            if ($actionId <= 0) {
                $this->sendSaveError();

                return;
            }
        } else {
            $actionId = (int) $actions[0]['id'];
            $rowData  = json_decode((string) ($actions[0]['data'] ?? ''), true);

            // Una fila antiga pot portar el camp data buit o un JSON trencat:
            // amb un array buit, withAllowedVotes() hi torna a posar el màxim i
            // normalizeRow() les llistes.
            $data = $this->withAllowedVotes($actionId, is_array($rowData) ? $rowData : [], $playerId);
        }

        $maxVotes   = (int) $data['max'];
        $subCommand = $this->subCommand();

        // L'ordre importa i és el de sempre: primer esborrar, després comprovar
        // si ja està tot fet, i només al final cada camí.
        if ($subCommand === 'borrar') {
            $this->deleteVotes($actionId, $data);

            return;
        }

        if ($this->hasMaxVotes($data, $maxVotes) && $this->hasMaxShields($data, $maxVotes)) {
            $this->sendVotesComplete($data, $maxVotes);

            return;
        }

        // Els passos propis del boicot (el /kos navega per pots abans de votar).
        if ($this->handleModeSubCommand($subCommand, $actionId, $data, $maxVotes)) {
            return;
        }

        if ($subCommand === $this->voteCommand()) {
            $this->registerVote($actionId, $data, $maxVotes);

            return;
        }

        if ($subCommand === 'escut') {
            $this->chooseShield($playerId, $actionId, $data, $maxVotes);

            return;
        }

        // Sense argument, o amb un subcomandament que no existeix: abans un
        // subcomandament desconegut queia fins al missatge global "Comença
        // utilitzant els botons -> /inici", que no hi té res a vore.
        $this->sendMenu($data, $maxVotes);
    }

    /**
     * Un subcomandament propi d'un boicot concret (el 'ko_pot' del /kos).
     *
     * @return bool Cert si l'ha atés i el handle() ha d'acabar.
     */
    protected function handleModeSubCommand(string $subCommand, int $actionId, array $data, int $maxVotes): bool
    {
        return false;
    }

    /**
     * Contingut inicial de la fila: el màxim, la llista de vots buida i cap
     * escut.
     */
    private function newRowData(int $playerId): array
    {
        return [
            'max'           => $this->playersRepo->getAllowedVotes($playerId),
            $this->voteKey() => [],
            'shields'       => [],
        ];
    }

    /**
     * La fila sempre ha de portar la llista de vots i la d'escuts.
     */
    protected function normalizeRow(array $data): array
    {
        $data[$this->voteKey()] = $data[$this->voteKey()] ?? [];
        $data['shields']        = $data['shields'] ?? [];

        return $data;
    }

    /**
     * Esborra tots els vots i tots els escuts, sense tocar el màxim.
     */
    private function deleteVotes(int $actionId, array $data): void
    {
        $data[$this->voteKey()] = [];
        $data['shields']        = [];

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "Vots i escuts esborrats",
            false,
            null,
            null,
            $this->simpleKeyboard()
        );
    }

    /**
     * Ja té tots els vots i tots els escuts: els llista i ofereix esborrar-los.
     */
    private function sendVotesComplete(array $data, int $maxVotes): void
    {
        $this->telegram->sendMessage($this->chatId, $this->votesListText($data, $maxVotes));

        $this->telegram->sendMessage(
            $this->chatId,
            "Ja tens tots els vots fets",
            false,
            null,
            null,
            InlineMenu::fromCommands([[$this->command() . ' borrar', '/inici']])
        );
    }

    /**
     * Llista els vots i els escuts, amb el màxim i el que queda pendent d'usar.
     */
    protected function votesListText(array $data, int $maxVotes): string
    {
        $message = "Vots màxims: " . $maxVotes . "\n" . $this->votesTitle() . "\n";

        foreach ($data[$this->voteKey()] as $vote) {
            $message .= "- " . $this->voteText($vote) . "\n";
        }

        $message .= "\nEscuts:\n";

        foreach ($data['shields'] as $teamId) {
            $message .= "- " . $this->teamsRepo->getTeamName($teamId) . "\n";
        }

        $remainingVotes   = $maxVotes - count($data[$this->voteKey()]);
        $remainingShields = $maxVotes - count($data['shields']);
        $notices          = [];

        if ($remainingVotes > 0) {
            $notices[] = $remainingVotes === 1
                ? "Et queda 1 vot de KO pendent d'usar."
                : "Et queden " . $remainingVotes . " vots de KO pendents d'usar.";
        }

        if ($remainingShields > 0) {
            $notices[] = $remainingShields === 1
                ? "Et queda 1 escut pendent de posar."
                : "Et queden " . $remainingShields . " escuts pendents de posar.";
        }

        if (count($notices) > 0) {
            $message .= "\n" . implode("\n", $notices);
        }

        return $message;
    }

    /**
     * Menú inicial: què es pot votar i competicions per escudar.
     */
    protected function sendMenu(array $data, int $maxVotes): void
    {
        $rows   = $this->menuRows($data, $maxVotes);
        $rows[] = $this->shieldRows();

        $this->telegram->sendMessage(
            $this->chatId,
            $this->menuTitle(),
            false,
            null,
            null,
            InlineMenu::fromCommands($rows)
        );
    }

    /**
     * Els botons d'escut, sempre els mateixos tres i la tornada al menú.
     *
     * @return array<int, string>
     */
    protected function shieldRows(): array
    {
        return [
            $this->command() . ' escut CHL',
            $this->command() . ' escut EUL',
            $this->command() . ' escut COL',
            $this->command(),
        ];
    }

    /**
     * El vot s'ha desat: ho diu i torna a mostrar la llista.
     */
    protected function sendVoteSaved(array $data, int $maxVotes): void
    {
        $this->telegram->sendMessage($this->chatId, "Vot guardat", false, null, null, $this->simpleKeyboard());
        $this->telegram->sendMessage($this->chatId, $this->votesListText($data, $maxVotes));
    }

    /**
     * El vot no és vàlid: es diu i no es desa res.
     */
    protected function sendVoteError(string $text): void
    {
        $this->telegram->sendMessage($this->chatId, $text);
    }

    /**
     * Escuts: la competició demana la llista d'equips propis, i un equip hi
     * posa l'escut.
     */
    private function chooseShield(int $playerId, int $actionId, array $data, int $maxVotes): void
    {
        if ($this->hasMaxShields($data, $maxVotes)) {
            $this->sendShieldsComplete($data, $maxVotes);

            return;
        }

        $target = $this->argument(2);

        if (in_array($target, self::COMPETITIONS, true)) {
            $this->sendShieldableTeams($playerId, $data, $target);

            return;
        }

        if ($target === '') {
            $this->sendMenu($data, $maxVotes);

            return;
        }

        $team = $this->teamsRepo->getTeamByName($target);

        if (count($team) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        // La comprovació és per id: amb el nom del botó no encaixaria mai.
        if (in_array($team[0]['id'], $data['shields'])) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip ja té l'escut activat");

            return;
        }

        $data['shields'][] = $team[0]['id'];

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $this->telegram->sendMessage($this->chatId, "Escut guardat", false, null, null, $this->simpleKeyboard());
        $this->telegram->sendMessage($this->chatId, $this->votesListText($data, $maxVotes));
    }

    /**
     * Els equips propis d'una competició que encara no tenen escut.
     */
    private function sendShieldableTeams(int $playerId, array $data, string $competition): void
    {
        $teams = array_filter(
            $this->teamsRepo->getTeamsByPlayerId($playerId),
            function ($team) use ($data, $competition) {
                return $team['competition'] === $competition
                    && !in_array($team['id'], $data['shields']);
            }
        );

        $rows = $this->teamRows($teams, $this->command() . ' escut ');

        // Un teclat inline buit no és el mateix que un teclat de resposta buit:
        // Telegram no el vol. Si no queda cap equip per escudar, el missatge va
        // sense botons.
        $keyboard = InlineMenu::optional($rows);

        $this->telegram->sendMessage(
            $this->chatId,
            "Els teus equips per a posar escut:",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * Ja ha votat tot el que li tocava: només li queden els escuts.
     */
    protected function sendKosComplete(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Ja has fet el màxim de vots de " . $this->voteNoun() . ", ara pots posar escuts si vols:",
            false,
            null,
            null,
            InlineMenu::fromCommands([$this->shieldRows()])
        );
    }

    /**
     * Ja ha posat tots els escuts que li toquen: només li queden els vots.
     */
    protected function sendShieldsComplete(array $data, int $maxVotes): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Ja has posat el màxim d'escuts, ara pots votar els " . $this->voteNoun() . " si vols:",
            false,
            null,
            null,
            InlineMenu::fromCommands($this->menuRows($data, $maxVotes))
        );
    }

    /**
     * Files de botons de tres en tres, amb el comandament que correspongui.
     *
     * @param array<int, array> $teams
     *
     * @return array<int, array<int, string>>
     */
    protected function teamRows(array $teams, string $commandPrefix): array
    {
        $rows = [];
        $row  = [];

        foreach ($teams as $team) {
            $row[] = $commandPrefix . $team['name'];

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Files de botons de N en N a partir d'una llista d'etiquetes (els països).
     *
     * @param array<int, string> $labels
     *
     * @return array<int, array<int, string>>
     */
    protected function labelRows(array $labels, string $commandPrefix, int $perRow = 3): array
    {
        $rows = [];
        $row  = [];

        foreach ($labels as $label) {
            $row[] = $commandPrefix . $label;

            if (count($row) === $perRow) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Files de botons dels pots, de quatre en quatre, amb l'etiqueta de cada
     * competició (CHL_1...).
     *
     * @return array<int, array<int, string>>
     */
    protected function potRows(string $commandPrefix): array
    {
        $rows = [];
        $row  = [];

        foreach (potLabels() as $label) {
            $row[] = $commandPrefix . $label;

            if (count($row) === 4) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        return $rows;
    }

    protected function simpleKeyboard(): InlineKeyboardMarkup
    {
        return InlineMenu::fromCommands([[$this->command(), '/inici']]);
    }

    protected function hasMaxVotes(array $data, int $maxVotes): bool
    {
        return count($data[$this->voteKey()]) >= $maxVotes;
    }

    protected function hasMaxShields(array $data, int $maxVotes): bool
    {
        return count($data['shields']) >= $maxVotes;
    }
}
