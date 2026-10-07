<?php

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció #dobleORes (tipus doubleOrNothing).
 *
 * Permet votar equips: l'equip més votat de cada competició no suma res i el
 * segon més votat suma doble. Els vots es desen a la fila d'accions del jugador
 * com un JSON {max, teams:[teamId, ...]}.
 *
 *     /dobleORes              menú de pots
 *     /dobleORes pot N        equips del pot N per votar
 *     /dobleORes vot EQUIP    registra el vot
 *     /dobleORes borrar       esborra tots els vots
 *
 * AVÍS: la fila de l'acció ha de portar 'max' dins del JSON, que és el nombre
 * de vots que li toquen al jugador. Cap script del projecte no l'hi escriu: el
 * sembra algú a mà a la base de dades. Si falta, val 0 i l'acció es considera
 * completa de sortida, o sigui que no deixa votar.
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
class DoubleOrNothingAction
{
    /**
     * Comandament que gestiona aquesta acció.
     */
    private const COMMAND = '/dobleORes';

    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'doubleOrNothing';

    private BotApi $telegram;
    private Player $playersRepo;
    private Team $teamsRepo;
    private Action $actionsRepo;
    private int $chatId;
    private int $matchDay;
    private array $args;
    private bool $actionsActivated;

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
        $this->telegram         = $telegram;
        $this->playersRepo      = $playersRepo;
        $this->teamsRepo        = $teamsRepo;
        $this->actionsRepo      = $actionsRepo;
        $this->chatId           = $chatId;
        $this->matchDay         = $matchDay;
        $this->args             = $args;
        $this->actionsActivated = $actionsActivated;
    }

    public static function handlesCommand(string $command): bool
    {
        return $command === self::COMMAND;
    }

    public function run(): void
    {
        $playerId = $this->getPlayerId();

        if ($playerId === null) {
            $this->sendNotRegistered();

            return;
        }

        $actions = $this->actionsRepo->getActionsByPlayerId($playerId, $this->matchDay, self::ACTION_TYPE);

        // L'acció ha d'estar activada i tenir la seva fila a la base de dades;
        // la fila no la crea el bot.
        if (!$this->actionsActivated || count($actions) === 0) {
            $this->telegram->sendMessage($this->chatId, "No disponible");

            return;
        }

        $actionId = (int) $actions[0]['id'];
        $data     = json_decode($actions[0]['data'], true);

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
        // hi té res a veure; ara torna a mostrar el menú.
        $this->sendPotMenu();
    }

    /**
     * Esborra tots els vots deixant la resta del JSON (el max) intacte.
     */
    private function deleteVotes(int $actionId, array $data): void
    {
        $data['teams'] = [];
        $this->actionsRepo->updateAction($actionId, json_encode($data));

        $keyboard = new ReplyKeyboardMarkup([['/dobleORes', '/inici']], true, true);
        $this->telegram->sendMessage($this->chatId, "Vots borrats", false, null, null, $keyboard);
    }

    /**
     * Registra un vot i mostra com queden els vots.
     */
    private function registerVote(int $actionId, array $data): void
    {
        $team = $this->teamsRepo->getTeamByName((string) ($this->args[2] ?? ''));

        if (count($team) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $data['teams'][] = $team[0]['id'];
        $this->actionsRepo->updateAction($actionId, json_encode($data));

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
     * Llista els equips votats amb el màxim de vots.
     */
    private function sendVotesList(array $data): void
    {
        $message = "Vots màxims: " . ($data['max'] ?? '') . "\n" . "Equipts votats:\n";

        foreach ($data['teams'] ?? [] as $teamId) {
            $message .= "- " . $this->teamsRepo->getTeamName($teamId) . "\n";
        }

        $this->telegram->sendMessage($this->chatId, $message);
    }

    private function sendPotMenu(): void
    {
        $keyboard = new ReplyKeyboardMarkup(
            [
                ['/dobleORes pot 1', '/dobleORes pot 2', '/dobleORes pot 3', '/dobleORes pot 4'],
                ['/dobleORes pot 5', '/dobleORes pot 6', '/dobleORes pot 7', '/dobleORes pot 8'],
                ['/dobleORes pot 9', '/dobleORes pot 10', '/dobleORes pot 11', '/dobleORes pot 12'],
            ],
            true,
            true
        );

        $this->telegram->sendMessage($this->chatId, "Pots disponibles:", false, null, null, $keyboard);
    }

    private function sendPotTeams(): void
    {
        $pot = $this->args[2] ?? '';

        if (!is_numeric($pot) || $pot < 1 || $pot > 12) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot invàlid");

            return;
        }

        $teams = $this->teamsRepo->getTeamsByPot($pot);
        $rows  = [];
        $row   = [];

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
            "Equips del pot " . $pot . ":",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * Nombre de vots que li toquen al jugador, segons el JSON de la fila.
     *
     * Si la fila no porta 'max' el resultat és 0, que és el que feia la
     * comparació original amb null, però sense l'avís de PHP.
     */
    private function maxVotes(array $data): int
    {
        return (int) ($data['max'] ?? 0);
    }

    private function votesAreComplete(array $data): bool
    {
        return $this->maxVotes($data) === count($data['teams'] ?? []);
    }

    /**
     * Subcomandament: 'pot', 'vot', 'borrar' o res.
     */
    private function subCommand(): string
    {
        return (string) ($this->args[1] ?? '');
    }

    /**
     * Id del jugador associat al xat, o null si encara no existeix.
     */
    private function getPlayerId(): ?int
    {
        $player = $this->playersRepo->getPlayerByChatId($this->chatId);

        if (!isset($player[0]['id'])) {
            return null;
        }

        return (int) $player[0]['id'];
    }

    /**
     * El jugador encara no ha passat per /equips: no té equip i no pot votar.
     */
    private function sendNotRegistered(): void
    {
        $keyboard = new ReplyKeyboardMarkup([['/equips', '/inici']], true, true);

        $this->telegram->sendMessage(
            $this->chatId,
            "Encara no has triat cap equip. Fes /equips per començar.",
            false,
            null,
            null,
            $keyboard
        );
    }
}
