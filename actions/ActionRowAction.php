<?php

require_once(__DIR__ . '/PlayerAction.php');

use TelegramBot\Api\BotApi;

/**
 * Base de les accions que es desen a la taula actions, una fila per jugador i
 * jornada: #malDia, #socElMillor, #guanyarOMorir, #dobleORes, #kosAmbEscuts,
 * #kosPais, #kosPots, #segurQuePasse i #millorFinal.
 *
 * Afegeix a PlayerAction el repositori d'accions, l'interruptor de la fase i
 * les operacions que totes fan servir: llegir la fila del jugador, crear-la i
 * desar-la. La forma del JSON de cada fila és cosa de cada acció.
 */
abstract class ActionRowAction extends PlayerAction
{
    protected Action $actionsRepo;
    protected bool $actionsActivated;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Action $actionsRepo,
        int $chatId,
        int $matchDay,
        array $args,
        bool $actionsActivated
    ) {
        parent::__construct($telegram, $playersRepo, $chatId, $matchDay, $args);

        $this->actionsRepo      = $actionsRepo;
        $this->actionsActivated = $actionsActivated;
    }

    /**
     * Valor de la columna `type` a la taula actions.
     */
    abstract protected function actionType(): string;

    /**
     * L'acció no està disponible en esta fase.
     */
    protected function sendNotAvailable(): void
    {
        $this->telegram->sendMessage($this->chatId, "No disponible");
    }

    /**
     * Files de l'acció d'este jugador i jornada (normalment cap o una).
     */
    protected function actionRows(int $playerId): array
    {
        return $this->actionsRepo->getActionsByPlayerId($playerId, $this->matchDay, $this->actionType());
    }

    /**
     * Crea la fila de l'acció per a este jugador i jornada.
     *
     * @return int Id de la fila nova, o 0 si la inserció ha fallat. Qui la crida
     *             ha de comprovar-ho: amb un 0, qualsevol desada posterior es
     *             perdria sense avisar.
     */
    protected function createActionRow(int $playerId, array $data): int
    {
        $actionId = $this->actionsRepo->addAction(
            $playerId,
            $this->matchDay,
            $this->actionType(),
            json_encode($data)
        );

        return $actionId === false ? 0 : (int) $actionId;
    }

    /**
     * Desa el contingut de la fila.
     *
     * @return bool False si la BD no l'ha poguda desar.
     */
    protected function saveActionRow(int $actionId, array $data): bool
    {
        $json = json_encode($data);

        if ($json === false) {
            return false;
        }

        return $this->actionsRepo->updateAction($actionId, $json) !== false;
    }

    /**
     * La fila no s'ha pogut crear o desar: val més dir-ho que fer creure al
     * jugador que el vot ha quedat guardat.
     */
    protected function sendSaveError(): void
    {
        $this->telegram->sendMessage($this->chatId, "ERROR, no s'ha pogut guardar. Torna-ho a provar.");
    }

    /**
     * Assegura que la fila porti el nombre de vots que li toquen al jugador.
     *
     * Surt de la classificació general (players.points) i es desa només quan
     * canvia: aixina, mentre dura la jornada, el número queda congelat amb la
     * general que va vore el jugador, i publishActions compta els vots amb el
     * mateix valor.
     */
    protected function withAllowedVotes(int $actionId, array $data, int $playerId): array
    {
        $allowedVotes = $this->playersRepo->getAllowedVotes($playerId);

        // Normalitzar ABANS de desar: si la fila vella no portava la llista de
        // vots, es desava només amb 'max' i el JSON quedava a mitges.
        $normalized = $this->normalizeRow($data);
        $needsSave  = $normalized !== $data || ($normalized['max'] ?? null) !== $allowedVotes;

        if ($needsSave) {
            // 'max' al davant: la fila queda sempre amb la mateixa forma.
            $normalized = ['max' => $allowedVotes] + $normalized;
            $this->saveActionRow($actionId, $normalized);
        }

        return $normalized;
    }

    /**
     * Les claus que l'acció espera trobar sempre a la fila, amb el valor per
     * defecte si falten. Les accions que no en tinguin cap no cal que la
     * sobreescriguin.
     */
    protected function normalizeRow(array $data): array
    {
        return $data;
    }
}
