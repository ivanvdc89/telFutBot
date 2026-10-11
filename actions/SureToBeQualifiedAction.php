<?php

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció #segurQuePasse (tipus sureToBeQualified).
 *
 * El jugador aposta per un dels seus equips: si passa de ronda, a més dels
 * punts del partit suma els punts que hi ha apostats. Quants punts s'hi juguen
 * surt de la classificació general (players.points): 1 punt per al líder i 1
 * més per cada 10 punts de diferència, fins a 4.
 *
 *     /segurQuePasse EQUIP    aposta per l'equip
 *     /segurQuePasse Borrar   treu l'aposta
 *     /segurQuePasse          els teus equips per triar
 *     /passe EQUIP            àlies del mateix comandament
 *
 * Mentre hi hagi una aposta feta el bot només la mostra: per canviar d'equip
 * primer s'ha de treure amb 'Borrar', que és com es comportava abans.
 *
 * La tria es desa a la fila d'accions del jugador com un JSON
 * {team: teamId, points: N}. Els punts es desen amb l'equip perquè
 * calculateMatchDayPoints els sumi tal com els va veure el jugador, i no amb la
 * general de després de la jornada. Les files antigues només portaven [teamId]
 * i es continuen llegint.
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
class SureToBeQualifiedAction
{
    /**
     * Comandaments que gestiona aquesta acció.
     */
    private const COMMANDS = ['/segurQuePasse', '/passe'];

    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'sureToBeQualified';

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
        return in_array($command, self::COMMANDS, true);
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

        // Amb una fila ja creada: es pot treure l'aposta o veure què hi ha ara
        // mateix. Amb un equip triat no se'n pot posar un altre de directe:
        // primer s'ha de treure l'aposta, com feia el codi original.
        if (count($actions) > 0) {
            $actionId     = (int) $actions[0]['id'];
            $data         = json_decode($actions[0]['data'], true);
            $chosenTeamId = $data['team'] ?? ($data[0] ?? null);

            if ($this->subCommand() === 'Borrar') {
                $this->actionsRepo->updateAction($actionId, json_encode([]));
                $this->sendTeamMenu($playerId);

                return;
            }

            if ($chosenTeamId !== null) {
                $this->sendCurrentChoice((int) $chosenTeamId);

                return;
            }

            // Fila sense equip triat (acabada de treure l'aposta o creada buida).
            if ($this->subCommand() !== '') {
                $this->chooseTeam($playerId, $actionId, $this->pointsAtStake($playerId));

                return;
            }

            $this->sendTeamMenu($playerId);

            return;
        }

        // Sense fila: el bot no la crea fins que el jugador tria equip.
        if ($this->subCommand() !== '') {
            $this->chooseTeam($playerId, null, $this->pointsAtStake($playerId));

            return;
        }

        $this->sendTeamMenu($playerId);
    }

    /**
     * Desa l'equip triat amb els punts que s'hi juguen, en la fila que ja
     * existeix o en una de nova.
     */
    private function chooseTeam(int $playerId, ?int $actionId, int $pointsAtStake): void
    {
        $team = $this->teamsRepo->getTeamByName($this->subCommand());

        if (count($team) === 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $data = json_encode(['team' => $team[0]['id'], 'points' => $pointsAtStake]);

        if ($actionId === null) {
            $this->actionsRepo->addAction($playerId, $this->matchDay, self::ACTION_TYPE, $data);
        } else {
            $this->actionsRepo->updateAction($actionId, $data);
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "Has activat el #segurQuePasse amb el " . $team[0]['name'] . "\n"
        );
    }

    /**
     * Punts que s'aposten segons la posició a la general.
     */
    private function pointsAtStake(int $playerId): int
    {
        return $this->playersRepo->getAllowedVotes($playerId);
    }

    /**
     * L'aposta que hi ha ara mateix, amb el botó per treure-la.
     */
    private function sendCurrentChoice(int $teamId): void
    {
        $teamInfo = $this->teamsRepo->getTeamById($teamId);
        $message  = "Actualment tens activat el #segurQuePasse amb el " . $teamInfo[0]['name'] . "\n";

        $this->telegram->sendMessage(
            $this->chatId,
            $message,
            false,
            null,
            null,
            new ReplyKeyboardMarkup([['/segurQuePasse Borrar']], true, true)
        );
    }

    /**
     * Els equips del jugador per triar-ne un.
     */
    private function sendTeamMenu(int $playerId): void
    {
        $rows = [];
        $row  = [];

        foreach ($this->teamsRepo->getTeamsByPlayerId($playerId) as $team) {
            $row[] = '/passe ' . $team['name'];

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "#segurQuePasse activar amb quin equip:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup($rows, true, true)
        );
    }

    /**
     * Subcomandament: un nom d'equip, 'Borrar' o res.
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
     * El jugador encara no ha passat per /equips.
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
