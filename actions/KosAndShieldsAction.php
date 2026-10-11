<?php

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció #kosAmbEscuts (tipus kosAndShields).
 *
 * És la votació de boicot: els equips més votats no sumen punts, i cada escut
 * protegeix un dels equips propis del boicot.
 *
 *     /kos                    menú de pots i d'escuts
 *     /kos ko_pot N           equips del pot N per votar-hi en contra
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
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
class KosAndShieldsAction
{
    /**
     * Comandament que gestiona aquesta acció.
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

        $maxVotes   = (int) $data['max'];
        $subCommand = (string) ($this->args[1] ?? '');

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
        // utilitzant els botons -> /inici", que no hi té res a veure.
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
     * Assegura que la fila porti el nombre de vots que li toquen al jugador, i
     * que les llistes de kos i escuts existeixin.
     *
     * El màxim surt de la classificació general (players.points) i es desa a la
     * fila només quan canvia: així, mentre dura la jornada, el número queda
     * congelat amb la general que va veure el jugador.
     */
    private function withAllowedVotes(int $actionId, array $data, int $playerId): array
    {
        $allowedVotes = $this->playersRepo->getAllowedVotes($playerId);

        if (($data['max'] ?? null) !== $allowedVotes) {
            // 'max' al davant: la fila queda sempre amb la mateixa forma.
            $data = ['max' => $allowedVotes] + $data;
            $this->actionsRepo->updateAction($actionId, json_encode($data));
        }

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
        $this->actionsRepo->updateAction($actionId, json_encode($data));

        $this->telegram->sendMessage(
            $this->chatId,
            "Vots i escuts borrats",
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
        $message = "Vots màxims: " . $maxVotes . "\n" . "Equipts votats:\n";

        foreach ($data['kos'] as $teamId) {
            $teamInfo = $this->teamsRepo->getTeamById($teamId);
            $message .= "- " . $teamInfo[0]['name'] . "\n";
        }

        $message .= "\nEscuts:\n";

        foreach ($data['shields'] as $teamId) {
            $teamInfo = $this->teamsRepo->getTeamById($teamId);
            $message .= "- " . $teamInfo[0]['name'] . "\n";
        }

        return $message;
    }

    /**
     * Menú inicial: pots per votar i competicions per escudar.
     */
    private function sendMenu(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Pots disponibles:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup(
                [
                    ['/kos ko_pot 1', '/kos ko_pot 2', '/kos ko_pot 3', '/kos ko_pot 4'],
                    ['/kos ko_pot 5', '/kos ko_pot 6', '/kos ko_pot 7', '/kos ko_pot 8'],
                    ['/kos ko_pot 9', '/kos ko_pot 10', '/kos ko_pot 11', '/kos ko_pot 12'],
                    ['/kos escut CHL', '/kos escut EUL', '/kos escut COL', '/kos'],
                ],
                true,
                true
            )
        );
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

        $pot = $this->args[2] ?? '';

        if (!is_numeric($pot) || $pot < 1 || $pot > 12) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot invàlid");

            return;
        }

        $rows = $this->teamRows($this->teamsRepo->getTeamsByPot($pot), '/kos ko_vot ');

        $this->telegram->sendMessage(
            $this->chatId,
            "Equips del pot " . $pot . ":",
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

        $team = $this->teamsRepo->getTeamByName((string) ($this->args[2] ?? ''));

        if (count($team) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $data['kos'][] = $team[0]['id'];
        $this->actionsRepo->updateAction($actionId, json_encode($data));

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

        $target = (string) ($this->args[2] ?? '');

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
        $this->actionsRepo->updateAction($actionId, json_encode($data));

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
            "Ja has posat el màxim d'escuts, ara pots votat els kos si vols:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup(
                [
                    ['/kos ko_pot 1', '/kos ko_pot 2', '/kos ko_pot 3', '/kos ko_pot 4'],
                    ['/kos ko_pot 5', '/kos ko_pot 6', '/kos ko_pot 7', '/kos ko_pot 8'],
                    ['/kos ko_pot 9', '/kos ko_pot 10', '/kos ko_pot 11', '/kos ko_pot 12'],
                ],
                true,
                true
            )
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
        return $maxVotes === count($data['kos']);
    }

    private function hasMaxShields(array $data, int $maxVotes): bool
    {
        return $maxVotes === count($data['shields']);
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
     * El jugador encara no ha passat per /equips: no té cap equip i no pot
     * votar ni escudar res. Abans això acabava en error fatal sense resposta.
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
