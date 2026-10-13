<?php

require_once(__DIR__ . '/ActionRowAction.php');

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
 * calculateMatchDayPoints els sumi tal com els va vore el jugador, i no amb la
 * general de després de la jornada. Les files antigues només portaven [teamId]
 * i es continuen llegint.
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * La fila només es crea quan el jugador tria equip: obrir el menú no escriu res.
 */
class SureToBeQualifiedAction extends ActionRowAction
{
    /**
     * Comandaments que gestiona esta acció.
     */
    private const COMMANDS = ['/segurQuePasse', '/passe'];

    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'sureToBeQualified';

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
        return self::COMMANDS;
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

        // Amb una fila ja creada: es pot treure l'aposta o vore què hi ha ara
        // mateix. Amb un equip triat no se'n pot posar un altre de directe:
        // primer s'ha de treure l'aposta, com feia el codi original.
        if (count($actions) > 0) {
            $actionId     = (int) $actions[0]['id'];
            $data         = json_decode($actions[0]['data'], true);
            $chosenTeamId = $data['team'] ?? ($data[0] ?? null);

            if ($this->subCommand() === 'Borrar') {
                $this->saveActionRow($actionId, []);
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

        $data = ['team' => $team[0]['id'], 'points' => $pointsAtStake];

        if ($actionId === null) {
            $this->createActionRow($playerId, $data);
        } else {
            $this->saveActionRow($actionId, $data);
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
}
