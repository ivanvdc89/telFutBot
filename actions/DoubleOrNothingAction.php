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
 * La fila d'accions del jugador la crea el bot la primera vegada que obre
 * l'acció (abans l'havia de sembrar algú a mà a la base de dades) i porta un
 * JSON {max, teams:[teamId, ...]}. 'max' és el nombre de vots que li toquen,
 * calculat amb la regla de posició a partir de la classificació general
 * (players.points): 1 vot per al líder i 1 més per cada 10 punts de diferència,
 * fins a 4.
 *
 * El número es desa a la fila quan canvia, de manera que durant la jornada
 * queda congelat amb la general que va veure el jugador; publishActions compta
 * els vots amb el mateix valor.
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

        if (!$this->actionsActivated) {
            $this->telegram->sendMessage($this->chatId, "No disponible");

            return;
        }

        $actions = $this->actionsRepo->getActionsByPlayerId($playerId, $this->matchDay, self::ACTION_TYPE);

        if (count($actions) === 0) {
            // La fila la crea el bot la primera vegada que el jugador obre
            // l'acció; abans l'havia de sembrar algú a mà a la base de dades.
            $data     = $this->newRowData($playerId);
            $actionId = (int) $this->actionsRepo->addAction(
                $playerId,
                $this->matchDay,
                self::ACTION_TYPE,
                json_encode($data)
            );
        } else {
            $actionId = (int) $actions[0]['id'];
            $data     = $this->withAllowedVotes($actionId, json_decode($actions[0]['data'], true), $playerId);
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
     * Assegura que la fila porti el nombre de vots que li toquen al jugador.
     *
     * Surt de la classificació general (players.points) i es desa a la fila
     * només quan canvia: així, mentre dura la jornada, el número queda congelat
     * amb la general que va veure el jugador, i no es paga una escriptura a cada
     * missatge.
     */
    private function withAllowedVotes(int $actionId, array $data, int $playerId): array
    {
        $allowedVotes = $this->playersRepo->getAllowedVotes($playerId);

        if (($data['max'] ?? null) !== $allowedVotes) {
            // 'max' al davant: la fila queda sempre amb la mateixa forma.
            $data = ['max' => $allowedVotes] + $data;
            $this->actionsRepo->updateAction($actionId, json_encode($data));
        }

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
