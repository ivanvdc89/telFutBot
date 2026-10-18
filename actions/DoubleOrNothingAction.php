<?php

require_once(__DIR__ . '/ActionRowAction.php');
require_once(__DIR__ . '/../config/pots.php');

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció #dobleORes (tipus doubleOrNothing).
 *
 * Permet votar equips: l'equip més votat de cada competició no suma res i el
 * segon més votat suma doble.
 *
 *     /dobleORes              menú de pots
 *     /dobleORes pot CHL_1    equips d'aquell pot per votar (CHL_1...COL_4)
 *     /dobleORes vot EQUIP    registra el vot
 *     /dobleORes borrar       esborra tots els vots
 *
 * La fila d'accions del jugador la crea el bot la primera vegada que obre
 * l'acció (abans l'havia de sembrar algú a mà a la base de dades) i porta un
 * JSON {max, teams:[teamId, ...]}. 'max' és el nombre de vots que li toquen,
 * calculat amb la regla de posició a partir de la classificació general
 * (players.points): 1 vot per al líder i 1 més per cada 10 punts de diferència,
 * fins a 4. El número es desa quan canvia, de manera que durant la jornada
 * queda congelat amb la general que va vore el jugador.
 */
class DoubleOrNothingAction extends ActionRowAction
{
    /**
     * Comandament que gestiona esta acció.
     */
    private const COMMAND = '/dobleORes';

    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'doubleOrNothing';

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
            // normalizeRow() la llista de vots, i el jugador pot votar igual.
            $data = $this->withAllowedVotes($actionId, is_array($rowData) ? $rowData : [], $playerId);
        }

        // L'ordre importa i és el de sempre: primer esborrar, després comprovar
        // si ja té tots els vots, i només al final el menú.
        if ($this->subCommand() === 'borrar') {
            $this->deleteVotes($actionId, $data);

            return;
        }

        if ($this->votesAreComplete($data)) {
            $this->sendVotesComplete($data);

            return;
        }

        if ($this->subCommand() === 'pot') {
            $this->sendPotTeams();

            return;
        }

        if ($this->subCommand() === 'vot') {
            $this->registerVote($actionId, $data);

            return;
        }

        // Sense argument, o amb un subcomandament que no existeix. Abans un
        // subcomandament desconegut no encaixava enlloc i l'execució queia fins
        // al missatge global "Comença utilitzant els botons -> /inici", que no
        // hi té res a vore; ara torna a mostrar el menú.
        $this->sendPotMenu();
    }

    /**
     * Esborra tots els vots deixant la resta del JSON (el max) intacte.
     */
    private function deleteVotes(int $actionId, array $data): void
    {
        $data['teams'] = [];

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $keyboard = new ReplyKeyboardMarkup([['/dobleORes', '/inici']], true, true);
        $this->telegram->sendMessage($this->chatId, "Vots esborrats", false, null, null, $keyboard);
    }

    /**
     * Registra un vot i mostra com queden els vots.
     */
    private function registerVote(int $actionId, array $data): void
    {
        // Defensa extra: handle() ja ho comprova abans de cridar ací, però així
        // ni una fila alterada a mà pot acabar amb més vots dels permesos.
        if ($this->votesAreComplete($data)) {
            $this->sendVotesComplete($data);

            return;
        }

        $team = $this->teamsRepo->getTeamByName($this->argument(2));

        if (count($team) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $data['teams'][] = $team[0]['id'];

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $keyboard = new ReplyKeyboardMarkup([['/dobleORes', '/inici']], true, true);
        $this->telegram->sendMessage($this->chatId, "Vot guardat", false, null, null, $keyboard);

        $this->sendVotesList($data);
    }

    private function sendVotesComplete(array $data): void
    {
        $this->sendVotesList($data);

        $keyboard = new ReplyKeyboardMarkup([['/dobleORes borrar', '/inici']], true, true);
        $this->telegram->sendMessage($this->chatId, "Ja tens tots els vots fets", false, null, null, $keyboard);
    }

    /**
     * Llista els equips votats amb el màxim de vots i, si en queden, quants
     * vots falten per usar.
     */
    private function sendVotesList(array $data): void
    {
        $maxVotes  = $this->maxVotes($data);
        $voted     = $data['teams'] ?? [];
        $remaining = $maxVotes - count($voted);

        $message = "Vots màxims: " . $maxVotes . "\n" . "Equips votats:\n";

        foreach ($voted as $teamId) {
            $message .= "- " . $this->teamsRepo->getTeamName($teamId) . "\n";
        }

        if ($remaining > 0) {
            $message .= "\n" . ($remaining === 1
                ? "Et queda 1 vot pendent d'usar."
                : "Et queden " . $remaining . " vots pendents d'usar.");
        }

        $this->telegram->sendMessage($this->chatId, $message);
    }

    private function sendPotMenu(): void
    {
        $rows = [];
        $row  = [];

        // Les etiquetes per competició (CHL_1...COL_4) i no els pots globals
        // 1-12: per al jugador, "pot 5" no diu res i "EUL_1" sí.
        foreach (potLabels() as $label) {
            $row[] = '/dobleORes pot ' . $label;

            if (count($row) === 4) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $this->telegram->sendMessage($this->chatId, "Pots disponibles:", false, null, null, $keyboard);
    }

    private function sendPotTeams(): void
    {
        $globalPot = potFromLabel($this->argument(2));

        if ($globalPot === null) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot invàlid");

            return;
        }

        $teams = $this->teamsRepo->getTeamsByPot($globalPot);

        if (count($teams) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, no hi ha equips en el pot " . potLabel($globalPot));

            return;
        }

        $rows = [];
        $row  = [];

        foreach ($teams as $team) {
            $row[] = '/dobleORes vot ' . $team['name'];

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $this->telegram->sendMessage(
            $this->chatId,
            "Equips del pot " . potLabel($globalPot) . ":",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * Contingut inicial de la fila: el màxim de vots i cap vot encara.
     */
    private function newRowData(int $playerId): array
    {
        return [
            'max'   => $this->playersRepo->getAllowedVotes($playerId),
            'teams' => [],
        ];
    }

    /**
     * La fila sempre ha de portar la llista de vots.
     */
    protected function normalizeRow(array $data): array
    {
        $data['teams'] = $data['teams'] ?? [];

        return $data;
    }

    /**
     * Nombre de vots que li toquen al jugador, segons el JSON de la fila.
     *
     * withAllowedVotes() sempre l'hi deixa escrit abans de fer-lo servir; el 0
     * només cobreix una fila que arribi sense 'max' per una via inesperada.
     */
    private function maxVotes(array $data): int
    {
        return (int) ($data['max'] ?? 0);
    }

    private function votesAreComplete(array $data): bool
    {
        // >= i no ===: si el màxim baixa a mig joc (per un recàlcul de la
        // general), una fila amb més vots dels permesos no ha de quedar oberta
        // per a sempre més.
        return count($data['teams'] ?? []) >= $this->maxVotes($data);
    }

}
