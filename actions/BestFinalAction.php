<?php

use TelegramBot\Api\BotApi;
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
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
class BestFinalAction
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

    private BotApi $telegram;
    private Player $playersRepo;
    private Action $actionsRepo;
    private int $chatId;
    private int $matchDay;
    private array $args;
    private bool $actionsActivated;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Action $actionsRepo,
        int $chatId,
        int $matchDay,
        array $args,
        bool $actionsActivated
    ) {
        $this->telegram         = $telegram;
        $this->playersRepo      = $playersRepo;
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

        $actions   = $this->actionsRepo->getActionsByPlayerId($playerId, $this->matchDay, self::ACTION_TYPE);
        $subCommand = $this->subCommand();

        if ($subCommand === 'borrar') {
            // Sense fila no hi ha res a esborrar: es mostra el menú igualment.
            if (count($actions) > 0) {
                $this->actionsRepo->updateAction((int) $actions[0]['id'], json_encode([]));
            }

            $this->sendCompetitionMenu();

            return;
        }

        if (in_array($subCommand, self::COMPETITIONS, true)) {
            $this->saveVote($playerId, $actions, $subCommand);
            $actions = $this->actionsRepo->getActionsByPlayerId($playerId, $this->matchDay, self::ACTION_TYPE);
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
        $data = json_encode([
            'competition' => $competition,
            'points'      => $this->playersRepo->getAllowedVotes($playerId),
        ]);

        if (count($actions) > 0) {
            $this->actionsRepo->updateAction((int) $actions[0]['id'], $data);

            return;
        }

        $this->actionsRepo->addAction($playerId, $this->matchDay, self::ACTION_TYPE, $data);
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

    /**
     * Subcomandament: 'borrar', una competició o res.
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
