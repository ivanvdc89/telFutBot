<?php

require_once(__DIR__ . '/ActionRowAction.php');
require_once(__DIR__ . '/../config/pots.php');

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció #kosAmbEscuts (tipus kosAndShields).
 *
 * És la votació de boicot: els equips més votats no sumen punts, i cada escut
 * protegeix un dels equips propis del boicot.
 *
 *     /kos                    menú de pots i d'escuts
 *     /kos ko_pot CHL_1       equips d'aquell pot per votar-hi en contra
 *     /kos ko_vot EQUIP       registra un vot de KO
 *     /kos escut CHL          els teus equips d'aquella competició per escudar
 *     /kos escut EQUIP        posa un escut a l'equip
 *     /kos borrar             esborra tots els vots i escuts
 *
 * Els vots i els escuts es desen a la fila d'accions del jugador com un JSON
 * {max, kos:[teamId, ...], shields:[teamId, ...]}. La fila la crea el bot la
 * primera vegada que el jugador obre l'acció, i abans l'havia de sembrar algú a
 * mà a la base de dades. 'max' és el nombre de vots que li toquen —els escuts
 * tenen el mateix límit— i surt de la classificació general (players.points):
 * 1 vot per al líder i 1 més per cada 10 punts de diferència, fins a 4.
 */
class KosAndShieldsAction extends ActionRowAction
{
    /**
     * Comandament que gestiona esta acció.
     */
    private const COMMAND = '/kos';

    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'kosAndShields';

    /**
     * Competicions amb què es pot demanar la llista d'equips per escudar.
     */
    private const COMPETITIONS = ['CHL', 'EUL', 'COL'];

    private Team $teamsRepo;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Team $teamsRepo,
        Action $actionsRepo,
        int $chatId,
        int $matchDay,
        array $args,
        bool $actionsActivated
    ) {
        parent::__construct($telegram, $playersRepo, $actionsRepo, $chatId, $matchDay, $args, $actionsActivated);

        $this->teamsRepo = $teamsRepo;
    }

    protected static function commands(): array
    {
        return [self::COMMAND];
    }

    protected function actionType(): string
    {
        return self::ACTION_TYPE;
    }

    protected function handle(int $playerId): void
    {
        if (!$this->actionsActivated) {
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
            // normalizeRow() les llistes de kos i escuts.
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

        if ($this->hasMaxKos($data, $maxVotes) && $this->hasMaxShields($data, $maxVotes)) {
            $this->sendVotesComplete($data, $maxVotes);

            return;
        }

        if ($subCommand === 'ko_pot') {
            $this->sendPotTeams($data, $maxVotes);

            return;
        }

        if ($subCommand === 'ko_vot') {
            $this->registerKosVote($actionId, $data, $maxVotes);

            return;
        }

        if ($subCommand === 'escut') {
            $this->chooseShield($playerId, $actionId, $data, $maxVotes);

            return;
        }

        // Sense argument, o amb un subcomandament que no existeix: abans un
        // subcomandament desconegut queia fins al missatge global "Comença
        // utilitzant els botons -> /inici", que no hi té res a vore.
        $this->sendMenu();
    }

    /**
     * Contingut inicial de la fila: el màxim de vots i cap vot ni escut.
     */
    private function newRowData(int $playerId): array
    {
        return [
            'max'     => $this->playersRepo->getAllowedVotes($playerId),
            'kos'     => [],
            'shields' => [],
        ];
    }

    /**
     * La fila sempre ha de portar les llistes de kos i d'escuts.
     */
    protected function normalizeRow(array $data): array
    {
        $data['kos']     = $data['kos'] ?? [];
        $data['shields'] = $data['shields'] ?? [];

        return $data;
    }

    /**
     * Esborra tots els vots de KO i tots els escuts, sense tocar el màxim.
     */
    private function deleteVotes(int $actionId, array $data): void
    {
        $data['kos']     = [];
        $data['shields'] = [];

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
            new ReplyKeyboardMarkup([['/kos borrar', '/inici']], true, true)
        );
    }

    /**
     * Llista els equips votats i els escuts, amb el màxim de vots.
     */
    private function votesListText(array $data, int $maxVotes): string
    {
        $message = "Vots màxims: " . $maxVotes . "\n" . "Equips votats:\n";

        foreach ($data['kos'] as $teamId) {
            $message .= "- " . $this->teamsRepo->getTeamName($teamId) . "\n";
        }

        $message .= "\nEscuts:\n";

        foreach ($data['shields'] as $teamId) {
            $message .= "- " . $this->teamsRepo->getTeamName($teamId) . "\n";
        }

        return $message;
    }

    /**
     * Menú inicial: pots per votar i competicions per escudar.
     */
    private function sendMenu(): void
    {
        $rows   = $this->potRows('/kos ko_pot ');
        $rows[] = ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos'];

        $this->telegram->sendMessage(
            $this->chatId,
            "Pots disponibles:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup($rows, true, true)
        );
    }

    /**
     * Files de botons dels pots, amb l'etiqueta de cada competició (CHL_1...).
     */
    private function potRows(string $commandPrefix): array
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

    /**
     * Equips d'un pot per votar-hi en contra.
     */
    private function sendPotTeams(array $data, int $maxVotes): void
    {
        if ($this->hasMaxKos($data, $maxVotes)) {
            $this->sendKosComplete();

            return;
        }

        $globalPot = potFromLabel($this->argument(2));

        if ($globalPot === null) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot invàlid");

            return;
        }

        $rows = $this->teamRows($this->teamsRepo->getTeamsByPot($globalPot), '/kos ko_vot ');

        if (count($rows) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, no hi ha equips en el pot " . potLabel($globalPot));

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "Equips del pot " . potLabel($globalPot) . ":",
            false,
            null,
            null,
            new ReplyKeyboardMarkup($rows, true, true)
        );
    }

    /**
     * Registra un vot de KO i mostra com queden els vots.
     */
    private function registerKosVote(int $actionId, array $data, int $maxVotes): void
    {
        if ($this->hasMaxKos($data, $maxVotes)) {
            $this->sendKosComplete();

            return;
        }

        $team = $this->teamsRepo->getTeamByName($this->argument(2));

        if (count($team) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $data['kos'][] = $team[0]['id'];

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $this->telegram->sendMessage($this->chatId, "Vot guardat", false, null, null, $this->simpleKeyboard());
        $this->telegram->sendMessage($this->chatId, $this->votesListText($data, $maxVotes));
    }

    /**
     * Escuts: la competició demana la llista d'equips propis, i un equip hi
     * posa l'escut.
     */
    private function chooseShield(int $playerId, int $actionId, array $data, int $maxVotes): void
    {
        if ($this->hasMaxShields($data, $maxVotes)) {
            $this->sendShieldsComplete();

            return;
        }

        $target = $this->argument(2);

        if (in_array($target, self::COMPETITIONS, true)) {
            $this->sendShieldableTeams($playerId, $data, $target);

            return;
        }

        if ($target === '') {
            $this->sendMenu();

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

        $rows = $this->teamRows($teams, '/kos escut ');

        $this->telegram->sendMessage(
            $this->chatId,
            "Els teus equips per a posar escut:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup($rows, true, true)
        );
    }

    /**
     * Ja ha votat tots els kos que li toquen: només li queden els escuts.
     */
    private function sendKosComplete(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Ja has fet el màxim de vots de kos, ara pots posar escuts si vols:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup([['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos']], true, true)
        );
    }

    /**
     * Ja ha posat tots els escuts que li toquen: només li queden els kos.
     */
    private function sendShieldsComplete(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Ja has posat el màxim d'escuts, ara pots votar els kos si vols:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup($this->potRows('/kos ko_pot '), true, true)
        );
    }

    /**
     * Files de botons de tres en tres, amb el comandament que correspongui.
     */
    private function teamRows(array $teams, string $commandPrefix): array
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

    private function simpleKeyboard(): ReplyKeyboardMarkup
    {
        return new ReplyKeyboardMarkup([['/kos', '/inici']], true, true);
    }

    private function hasMaxKos(array $data, int $maxVotes): bool
    {
        return count($data['kos']) >= $maxVotes;
    }

    private function hasMaxShields(array $data, int $maxVotes): bool
    {
        return count($data['shields']) >= $maxVotes;
    }

}
