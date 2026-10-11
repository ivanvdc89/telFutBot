<?php

require_once(__DIR__ . '/ActionRowAction.php');

use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció #millorFinal (tipus bestFinal).
 *
 * Només té efectes a les finals: el jugador vota quina de les tres finals vale
 * més. El pes del vot surt de la classificació general (players.points): 1 vot
 * per al líder i 1 més per cada 10 punts de diferència, fins a 4.
 *
 *     /millorFinal CHL|EUL|COL   vota aquella final
 *     /millorFinal borrar        treu el vot
 *
 * La tria es desa a la fila d'accions del jugador com un JSON
 * {competition: 'CHL', points: N}. publishActions compta el vot amb els punts
 * que hi ha desats, que són els que el jugador va veure. Les files antigues
 * només portaven ['CHL'] i es continuen llegint.
 */
class BestFinalAction extends ActionRowAction
{
    /**
     * Comandament que gestiona aquesta acció.
     */
    private const COMMAND = '/millorFinal';

    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'bestFinal';

    /**
     * Les tres finals que es poden votar.
     */
    private const COMPETITIONS = ['CHL', 'EUL', 'COL'];

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

        $actions    = $this->actionRows($playerId);
        $subCommand = $this->subCommand();

        if ($subCommand === 'borrar') {
            // Sense fila no hi ha res a esborrar: es mostra el menú igualment.
            if (count($actions) > 0) {
                $this->saveActionRow((int) $actions[0]['id'], []);
            }

            $this->sendCompetitionMenu();

            return;
        }

        if (in_array($subCommand, self::COMPETITIONS, true)) {
            $this->saveVote($playerId, $actions, $subCommand);
            $actions = $this->actionRows($playerId);
        }

        $competition = $this->chosenCompetition($actions);

        if ($competition !== null) {
            $this->sendCurrentChoice($competition);

            return;
        }

        $this->sendCompetitionMenu();
    }

    /**
     * Desa la final votada amb el pes del vot que li toca al jugador.
     *
     * @param array $actions Files que ja existien abans de desar res.
     */
    private function saveVote(int $playerId, array $actions, string $competition): void
    {
        $data = [
            'competition' => $competition,
            'points'      => $this->playersRepo->getAllowedVotes($playerId),
        ];

        if (count($actions) > 0) {
            $this->saveActionRow((int) $actions[0]['id'], $data);

            return;
        }

        $this->createActionRow($playerId, $data);
    }

    /**
     * La final votada, o null si no n'hi ha cap.
     *
     * @param array $actions Files de l'acció d'aquest jugador i jornada.
     */
    private function chosenCompetition(array $actions): ?string
    {
        if (count($actions) === 0) {
            return null;
        }

        $data = json_decode($actions[0]['data'], true);

        // Les files antigues només portaven ['CHL'].
        return $data['competition'] ?? ($data[0] ?? null);
    }

    /**
     * La final votada ara mateix, amb el botó per treure el vot.
     */
    private function sendCurrentChoice(string $competition): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "\nMillorFinal: " . $competition . "\n",
            false,
            null,
            null,
            new ReplyKeyboardMarkup([['/millorFinal borrar']], true, true)
        );
    }

    /**
     * Menú de les tres finals.
     */
    private function sendCompetitionMenu(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "#millorFinal:",
            false,
            null,
            null,
            new ReplyKeyboardMarkup([['/millorFinal CHL', '/millorFinal EUL', '/millorFinal COL']], true, true)
        );
    }

}
