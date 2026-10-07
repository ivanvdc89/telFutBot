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
 * Les tres competicions es poden activar de forma independent. Abans el teclat
 * només oferia CHL i EUL tot i que les normes i calculateMatchDayPoints ja
 * parlaven de la Conference; ara surten les tres, definides a COMPETITIONS per
 * no haver-les de repetir al teclat i al missatge d'estat.
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
abstract class CompetitionToggleAction
{
    /**
     * Competicions que es poden activar, amb el nom que surt als missatges.
     *
     * L'ordre és el de sempre al projecte —Champions, Europa, Conference—, el
     * mateix que el dels pots (1-4, 5-8, 9-12).
     */
    private const COMPETITIONS = [
        'CHL' => 'Champions League',
        'EUL' => 'Europa League',
        'COL' => 'Conference League',
    ];

    /**
     * Les tres accions que comparteixen aquesta base, amb l'etiqueta que surt
     * als missatges.
     *
     * És la llista del grup i, alhora, la font de label(): així el nom de cada
     * acció és en un sol lloc. Només se'n pot activar UNA per competició i
     * jornada, i per comprovar-ho cal conèixer les altres dues.
     */
    private const GROUP = [
        'badDay'     => '#malDia',
        'iAmTheBest' => '#socElMillor',
        'winOrDie'   => '#guanyarOMorir',
    ];

    /**
     * Vegades que es pot fer servir cada acció en TOTA la temporada.
     *
     * El límit compta competicions activades, no jornades: activar les 3
     * competicions a la mateixa jornada gasta les 3, i activar-ne una a tres
     * jornades seguides també. Com que el que compta és el que està activat,
     * desactivar-ne una torna a deixar marge.
     */
    private const MAX_USES_PER_SEASON = 3;

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
     * Etiqueta que surt als missatges, per exemple '#malDia'. Surt del grup, i
     * per tant una subclasse només ha de declarar command() i actionType().
     */
    protected function label(): string
    {
        $type = $this->actionType();

        if (!isset(self::GROUP[$type])) {
            throw new RuntimeException(
                "L'acció '$type' no està al grup d'accions de competició de CompetitionToggleAction."
            );
        }

        return self::GROUP[$type];
    }

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
            $this->updateAndShowState($playerId, $actions[0]);

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
            if ($this->usedThisSeason($playerId) >= self::MAX_USES_PER_SEASON) {
                $this->sendLimitReached();

                return;
            }

            $competition = $this->args[2] ?? null;

            if (!$this->canActivateCompetition($playerId, $competition)) {
                return;
            }

            $list[] = $competition;

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
    private function updateAndShowState(int $playerId, array $action): void
    {
        $list           = json_decode($action['data'], true);
        $messageClosure = '';
        $keyboard       = null;

        if ($this->actionsActivated) {
            $save = true;

            if ($this->subCommand() === 'Activar') {
                $competition = $this->args[2] ?? null;

                // El límit és de temporada i compta totes les jornades, també la
                // que s'està jugant ara. Si ja ha arribat a 3 no s'activa res:
                // ni tan sols si el que demana ja estava activat, perquè la
                // comanda ha de fallar i no escriure res.
                if ($this->usedThisSeason($playerId) >= self::MAX_USES_PER_SEASON) {
                    $this->sendLimitReached();
                    $save = false;
                } elseif (!in_array($competition, $list)) {
                    if ($this->canActivateCompetition($playerId, $competition)) {
                        $list[] = $competition;
                    } else {
                        $save = false;
                    }
                }
            } elseif ($this->subCommand() === 'Desactivar') {
                $list = array_diff($list, [$this->args[2] ?? null]);
            }

            // Es desa sempre que la comanda no hagi estat bloquejada pel límit,
            // encara que no s'hagi demanat ni activar ni desactivar res: és el
            // que feia el codi original.
            if ($save) {
                $this->actionsRepo->updateAction((int) $action['id'], json_encode($list));
            }

            $keyboard       = new ReplyKeyboardMarkup($this->toggleRows($list), true, true);
            $messageClosure = "\nActivar o desactivar:";
        }

        $message = "Actualment tens el " . $this->label() . ":\n";

        foreach (self::COMPETITIONS as $competition => $name) {
            $message .= "- " . $name . ": " . (in_array($competition, $list) ? "activat\n" : "desactivat\n");
        }

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
        $rows    = [];
        $row     = [];

        foreach (array_keys(self::COMPETITIONS) as $competition) {
            $row[] = $command . ' '
                . (in_array($competition, $list) ? 'Desactivar' : 'Activar')
                . ' ' . $competition;

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

    /**
     * Subcomandament: 'Activar', 'Desactivar' o res.
     */
    private function subCommand(): string
    {
        return (string) ($this->args[1] ?? '');
    }

    /**
     * Competicions que el jugador té activades aquesta temporada amb aquesta
     * acció, sumant totes les jornades.
     *
     * El límit és per acció: les 3 del #malDia són independents de les 3 del
     * #socElMillor.
     */
    private function usedThisSeason(int $playerId): int
    {
        $total = 0;

        foreach ($this->actionsRepo->getActionsByPlayerAndType($playerId, $this->actionType()) as $row) {
            $list = json_decode($row['data'], true);
            $total += is_array($list) ? count($list) : 0;
        }

        return $total;
    }

    /**
     * Comprova que es pugui activar aquesta competició: que sigui una de les
     * tres i que cap de les altres dues accions del grup no la tingui ja
     * activada en aquesta mateixa jornada.
     *
     * Si no es pot, envia el motiu i retorna false.
     */
    private function canActivateCompetition(int $playerId, ?string $competition): bool
    {
        if (!isset(self::COMPETITIONS[$competition])) {
            $this->telegram->sendMessage($this->chatId, "ERROR, competició no vàlida");

            return false;
        }

        $takenBy = $this->competitionTakenBy($playerId, $competition);

        if ($takenBy !== null) {
            $this->telegram->sendMessage(
                $this->chatId,
                "A la " . self::COMPETITIONS[$competition] . " d'aquesta jornada ja hi tens el "
                    . $takenBy . ". Només s'hi pot activar una de les tres accions."
            );

            return false;
        }

        return true;
    }

    /**
     * Etiqueta de l'acció del grup que ja té aquesta competició activada en
     * aquesta jornada, o null si no en té cap.
     *
     * Només mira la jornada en curs: la restricció és per competició i jornada,
     * no de temporada.
     */
    private function competitionTakenBy(int $playerId, string $competition): ?string
    {
        foreach ($this->actionsRepo->getActionsByPlayerAndMatchDay($playerId, $this->matchDay) as $row) {
            // La meva pròpia acció no em bloqueja.
            if ($row['type'] === $this->actionType() || !isset(self::GROUP[$row['type']])) {
                continue;
            }

            $list = json_decode($row['data'], true);

            if (is_array($list) && in_array($competition, $list)) {
                return self::GROUP[$row['type']];
            }
        }

        return null;
    }

    private function sendLimitReached(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Ja has fet servir el " . $this->label() . " " . self::MAX_USES_PER_SEASON
                . " vegades aquesta temporada, que és el màxim. Si en vols activar una altra, "
                . "desactiva'n primer alguna."
        );
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
