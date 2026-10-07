<?php

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Base de les accions que s'activen i es desactiven per competició.
 *
 * Les fan servir #malDia (badDay), #socElMillor (iAmTheBest) i #guanyarOMorir
 * (winOrDie). Estaven escrites tres vegades a basic.php: ho vaig comprovar
 * comparant les branques de #malDia i #socElMillor al repositori i, després de
 * normalitzar el comandament, el tipus, l'etiqueta i el nom de la variable de
 * la llista, eren 83 línies idèntiques amb zero diferències.
 *
 * Cada subclasse només ha de dir tres coses: command(), actionType() i label().
 *
 * El commutador no fa el mateix a tots els camins, i això es conserva tal com
 * estava:
 *   - Sense fila a la base de dades, si l'acció no està activada respon
 *     "No disponible" i no deixa crear-la.
 *   - Amb una fila, el commutador només bloqueja la modificació: l'estat es
 *     mostra sempre, amb o sense acció activada.
 *   - Amb més d'una fila (no hauria de passar) es mostra el menú, sense mirar
 *     el commutador.
 *
 * PENDENT: el teclat només ofereix CHL i EUL. Les normes diuen que aquestes
 * accions es poden activar a les 3 competicions i calculateMatchDayPoints ja
 * contempla COL, però aquí no hi ha cap botó per a la Conference.
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
abstract class CompetitionToggleAction
{
    protected BotApi $telegram;
    protected Player $playersRepo;
    protected Action $actionsRepo;
    protected int $chatId;
    protected int $matchDay;
    protected array $args;
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
        $this->telegram         = $telegram;
        $this->playersRepo      = $playersRepo;
        $this->actionsRepo      = $actionsRepo;
        $this->chatId           = $chatId;
        $this->matchDay         = $matchDay;
        $this->args             = $args;
        $this->actionsActivated = $actionsActivated;
    }

    /**
     * Comandament que gestiona l'acció, per exemple '/malDia'.
     */
    abstract public static function command(): string;

    /**
     * Valor de la columna `type` a la taula actions, per exemple 'badDay'.
     */
    abstract protected function actionType(): string;

    /**
     * Etiqueta que surt als missatges, per exemple '#malDia'.
     */
    abstract protected function label(): string;

    public static function handlesCommand(string $command): bool
    {
        return $command === static::command();
    }

    public function run(): void
    {
        $playerId = $this->getPlayerId();

        if ($playerId === null) {
            $this->sendNotRegistered();

            return;
        }

        $actions = $this->actionsRepo->getActionsByPlayerId($playerId, $this->matchDay, $this->actionType());

        if (count($actions) === 0) {
            $this->createOrShowMenu($playerId);

            return;
        }

        if (count($actions) === 1) {
            $this->updateAndShowState($actions[0]);

            return;
        }

        // Més d'una fila per al mateix jugador i jornada: no hauria de passar,
        // i es mostra el menú sense tocar res.
        $this->sendMenu();
    }

    /**
     * Sense fila a la base de dades: el commutador decideix si es pot crear.
     */
    private function createOrShowMenu(int $playerId): void
    {
        if (!$this->actionsActivated) {
            $this->telegram->sendMessage($this->chatId, "No disponible");

            return;
        }

        $list = [];

        if ($this->subCommand() === 'Activar') {
            $list[] = $this->args[2] ?? null;

            $this->actionsRepo->addAction(
                $playerId,
                $this->matchDay,
                $this->actionType(),
                json_encode($list)
            );
        }

        $this->sendMenu($list);
    }

    /**
     * Amb una fila: el commutador només decideix si es pot modificar. L'estat
     * es mostra sempre.
     */
    private function updateAndShowState(array $action): void
    {
        $list           = json_decode($action['data'], true);
        $messageClosure = '';
        $keyboard       = null;

        if ($this->actionsActivated) {
            if ($this->subCommand() === 'Activar') {
                $list[] = $this->args[2] ?? null;
                $list   = array_unique($list);
            } elseif ($this->subCommand() === 'Desactivar') {
                $list = array_diff($list, [$this->args[2] ?? null]);
            }

            // Es desa sempre, encara que no s'hagi demanat ni activar ni
            // desactivar res: és el que feia el codi original.
            $this->actionsRepo->updateAction((int) $action['id'], json_encode($list));

            $keyboard       = new ReplyKeyboardMarkup($this->toggleRows($list), true, true);
            $messageClosure = "\nActivar o desactivar:";
        }

        $message = "Actualment tens el " . $this->label() . ":\n"
            . "- Champions League: " . (in_array('CHL', $list) ? "activat\n" : "desactivat\n")
            . "- Europa League: " . (in_array('EUL', $list) ? "activat\n" : "desactivat\n");

        $this->telegram->sendMessage($this->chatId, $message . $messageClosure, false, null, null, $keyboard);
    }

    /**
     * Menú d'activació. Si li passem la llista d'estats, els botons es giren
     * perquè desactivin el que ja està activat; si va buida, són tots d'activar.
     */
    private function sendMenu(array $list = []): void
    {
        $keyboard = new ReplyKeyboardMarkup($this->toggleRows($list), true, true);

        $this->telegram->sendMessage(
            $this->chatId,
            $this->label() . " activar o desactivar:",
            false,
            null,
            null,
            $keyboard
        );
    }

    /**
     * Botons d'activar o desactivar segons el que ja està activat.
     */
    private function toggleRows(array $list): array
    {
        $command = static::command();

        $butCHL = $command . ' ' . (in_array('CHL', $list) ? 'Desactivar' : 'Activar') . ' CHL';
        $butEUL = $command . ' ' . (in_array('EUL', $list) ? 'Desactivar' : 'Activar') . ' EUL';

        return [[$butCHL, $butEUL]];
    }

    /**
     * Subcomandament: 'Activar', 'Desactivar' o res.
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
