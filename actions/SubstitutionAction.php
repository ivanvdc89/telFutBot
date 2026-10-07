<?php

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;

/**
 * Acció de substitució d'equips.
 *
 * Agrupa els tres comandaments que en realitat són una sola funcionalitat:
 *   - /substitució  estat dels canvis pendents, menú d'equips i "remove"
 *   - /out          triar l'equip que es deixa anar (o un forat buit)
 *   - /in           triar l'equip que entra i guardar el canvi
 *
 * REGLA: es pot fer UN canvi per competició i jornada, o sigui fins a 3
 * pendents alhora (CHL, EUL, COL), i es poden eliminar tots o un de sol.
 *
 * Dues idees fan que les validacions siguin correctes amb diversos pendents:
 *
 *   1. Les pendents es filtren per la jornada en curs, no pel jugador sencer.
 *   2. Les regles de país i de "ja tens aquest equip" es calculen sobre la
 *      PLANTILLA EFECTIVA (la plantilla real amb tots els canvis pendents
 *      aplicats), no sobre la real. Si no fos així, dos canvis podrien acabar
 *      deixant dos equips del mateix país: per exemple, amb Espanya al pot 1 i
 *      França al pot 5, podries demanar pot 1 -> Alemanya i, tot seguit,
 *      pot 5 -> Alemanya, perquè la plantilla real encara no conté Alemanya.
 *      Amb 0 pendents la plantilla efectiva és la real, o sigui que el
 *      comportament d'un sol canvi no canvia.
 *
 * Una instància viu una sola petició del webhook: rep les dependències pel
 * constructor, i els mètodes retornen en lloc de cridar exit(), perquè qui
 * decideix acabar la petició és el router de basic.php.
 */
class SubstitutionAction
{
    /**
     * Comandaments que gestiona aquesta acció.
     */
    private const COMMANDS = ['/substitució', '/out', '/in'];

    /**
     * Competicions en què es pot fer un canvi, una per jornada.
     */
    private const COMPETITIONS = ['CHL', 'EUL', 'COL'];

    /**
     * Pots que el jugador encara pot tenir buits, en l'ordre CHL 1-4, EUL 1-4,
     * COL 1-4, que és l'ordre de les posicions 1-12 del pot global. La clau de
     * cada element és pot - 1, i per això es pot fer unset() directament amb el
     * pot de cada equip. No porten el prefix del botó: s'afegeix al construir-lo.
     */
    private const EMPTY_POT_SLOTS = [
        'CHL_Pot_1', 'CHL_Pot_2', 'CHL_Pot_3', 'CHL_Pot_4',
        'EUL_Pot_1', 'EUL_Pot_2', 'EUL_Pot_3', 'EUL_Pot_4',
        'COL_Pot_1', 'COL_Pot_2', 'COL_Pot_3', 'COL_Pot_4',
    ];

    /**
     * Desplaçament del pot dins de la competició al pot global.
     */
    private const POT_OFFSETS = ['CHL' => 0, 'EUL' => 4, 'COL' => 8];

    /**
     * Punts que resta cada canvi.
     */
    private const SUBSTITUTION_COST = 6;

    /**
     * Els canvis són gratis si el jugador està a MÉS de tants punts del líder.
     */
    private const FREE_CHANGE_MIN_GAP = 29;

    private BotApi $telegram;
    private Player $playersRepo;
    private Team $teamsRepo;
    private Substitution $substitutionsRepo;
    private MatchDayPlayerPoint $pointsRepo;
    private int $chatId;
    private int $matchDay;
    private int $lastMatchDayWithChanges;
    private array $args;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        Team $teamsRepo,
        Substitution $substitutionsRepo,
        MatchDayPlayerPoint $pointsRepo,
        int $chatId,
        int $matchDay,
        int $lastMatchDayWithChanges,
        array $args
    ) {
        $this->telegram                = $telegram;
        $this->playersRepo             = $playersRepo;
        $this->teamsRepo               = $teamsRepo;
        $this->substitutionsRepo       = $substitutionsRepo;
        $this->pointsRepo              = $pointsRepo;
        $this->chatId                  = $chatId;
        $this->matchDay                = $matchDay;
        $this->lastMatchDayWithChanges = $lastMatchDayWithChanges;
        $this->args                    = $args;
    }

    public static function handlesCommand(string $command): bool
    {
        return in_array($command, self::COMMANDS, true);
    }

    public function run(string $command): void
    {
        $playerId = $this->getPlayerId();

        if ($playerId === null) {
            $this->sendNotRegistered();

            return;
        }

        $pending = $this->getPendingSubstitutions($playerId);

        if ($command === '/substitució') {
            $this->showSubstitution($playerId, $pending);

            return;
        }

        // A partir de les semifinals ja no es pot obrir cap canvi nou.
        if ($this->changesClosed()) {
            $this->sendChangesClosed();

            return;
        }

        if ($command === '/out') {
            $this->chooseOldTeam($playerId, $pending);

            return;
        }

        $this->chooseNewTeam($playerId, $pending);
    }

    /**
     * /substitució: estat dels canvis pendents, menú d'equips i "remove".
     */
    private function showSubstitution(int $playerId, array $pending): void
    {
        if (($this->args[1] ?? '') === 'remove') {
            $this->removeSubstitutions($pending);

            return;
        }

        $pendingCompetitions = $this->pendingCompetitions($pending);
        $message             = $this->pendingSubstitutionsText($pending);

        if ($this->changesClosed()) {
            $message .= "Els canvis estan tancats: no es poden fer canvis a partir de les semifinals.";

            $keyboard = count($pending) > 0
                ? new ReplyKeyboardMarkup($this->removeRows(), true, true)
                : null;

            $this->telegram->sendMessage($this->chatId, $message, false, null, null, $keyboard);

            return;
        }

        $freeCompetitions = array_values(array_diff(self::COMPETITIONS, $pendingCompetitions));

        if (count($freeCompetitions) === 0) {
            $message .= "Ja has fet un canvi a cada competició.";
            $keyboard = new ReplyKeyboardMarkup($this->removeRows(), true, true);
            $this->telegram->sendMessage($this->chatId, $message, false, null, null, $keyboard);

            return;
        }

        // Només s'ofereixen equips de les competicions que encara no tenen canvi.
        $rows = $this->teamChoiceRows($playerId, $freeCompetitions);

        if (count($pending) > 0) {
            $rows = array_merge($rows, $this->removeRows());
        }

        $message  .= "Els teus equips:";
        $keyboard = new ReplyKeyboardMarkup($rows, true, true);
        $this->telegram->sendMessage($this->chatId, $message, false, null, null, $keyboard);
    }

    /**
     * /out: triar l'equip que es deixa anar.
     */
    private function chooseOldTeam(int $playerId, array $pending): void
    {
        $oldTeamName = (string) ($this->args[1] ?? '');

        if (str_contains($oldTeamName, '_Pot_')) {
            $this->chooseOldTeamByEmptyPot($playerId, $pending);

            return;
        }

        $oldTeam = $this->teamsRepo->getTeamByName($oldTeamName);
        if (!is_array($oldTeam) || count($oldTeam) == 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $oldTeamId = $oldTeam[0]['id'];

        if ($this->hasPendingInCompetition($pending, $oldTeam[0]['competition'])) {
            $this->sendCompetitionAlreadyUsed($oldTeam[0]['competition']);

            return;
        }

        // La propietat es mira sobre la plantilla real: l'equip és teu encara
        // que tinguis un canvi pendent que el faci fora.
        $playerTeams       = $this->teamsRepo->getTeamsByPlayerId($playerId);
        $alreadyAddedTeams = array_map(function ($team) { return $team['id']; }, $playerTeams);

        if (!in_array($oldTeamId, $alreadyAddedTeams)) {
            $this->telegram->sendMessage($this->chatId, "ERROR, este equip no és teu");

            return;
        }

        $roster                = $this->getEffectiveTeams($playerId, $pending);
        $alreadyAddedCountries = array_map(function ($team) { return $team['country']; }, $roster);
        $alreadyAddedCountries = array_diff($alreadyAddedCountries, [$oldTeam[0]['country']]);
        $rosterIds             = array_map(function ($team) { return (int) $team['id']; }, $roster);

        $possibleNewTeams = $this->teamsRepo->getTeamsByPot($oldTeam[0]['pot']);
        $possibleNewTeams = array_filter($possibleNewTeams, function ($team) use ($alreadyAddedCountries, $oldTeamId, $rosterIds) {
            return !in_array($team['country'], $alreadyAddedCountries)
                && (int) $team['id'] !== (int) $oldTeamId
                && !in_array((int) $team['id'], $rosterIds, true);
        });

        $this->sendNewTeamKeyboard($possibleNewTeams);
    }

    /**
     * /out sobre un pot buit (CHL_Pot_1, EUL_Pot_3...): no es deixa anar res,
     * s'omple un forat.
     */
    private function chooseOldTeamByEmptyPot(int $playerId, array $pending): void
    {
        $slot = $this->parseEmptyPotSlot((string) ($this->args[1] ?? ''));

        if ($slot === null) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        [$competition, $pot] = $slot;

        if ($this->hasPendingInCompetition($pending, $competition)) {
            $this->sendCompetitionAlreadyUsed($competition);

            return;
        }

        $roster                = $this->getEffectiveTeams($playerId, $pending);
        $alreadyAddedCountries = array_map(function ($team) { return $team['country']; }, $roster);
        $rosterIds             = array_map(function ($team) { return (int) $team['id']; }, $roster);

        $possibleNewTeams = $this->teamsRepo->getTeamsByPot($pot);
        $possibleNewTeams = array_filter($possibleNewTeams, function ($team) use ($alreadyAddedCountries, $rosterIds) {
            return !in_array($team['country'], $alreadyAddedCountries)
                && !in_array((int) $team['id'], $rosterIds, true);
        });

        $this->sendNewTeamKeyboard($possibleNewTeams);
    }

    /**
     * /in: triar l'equip que entra i guardar el canvi.
     */
    private function chooseNewTeam(int $playerId, array $pending): void
    {
        $newTeam = $this->teamsRepo->getTeamByName((string) ($this->args[1] ?? ''));
        if (!is_array($newTeam) || count($newTeam) == 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, l'equip no existeix");

            return;
        }

        $competition = $newTeam[0]['competition'];

        if ($this->hasPendingInCompetition($pending, $competition)) {
            $this->sendCompetitionAlreadyUsed($competition);

            return;
        }

        // Com que aquesta competició encara no té cap canvi pendent, el pot que
        // es canvia és el de la plantilla real.
        $playerTeams = $this->teamsRepo->getTeamsByPlayerId($playerId);
        $oldTeam     = [
            'id'      => 0,
            'name'    => 'Empty',
            'pot'     => $newTeam[0]['pot'],
            'country' => 0,
        ];

        foreach ($playerTeams as $team) {
            if ($team['pot'] == $newTeam[0]['pot']) {
                $oldTeam = $team;
                break;
            }
        }

        $roster    = $this->getEffectiveTeams($playerId, $pending);
        $rosterIds = array_map(function ($team) { return (int) $team['id']; }, $roster);

        if (in_array((int) $newTeam[0]['id'], $rosterIds, true)) {
            $this->telegram->sendMessage($this->chatId, "ERROR, ja tens aquest equip");

            return;
        }

        $alreadyAddedCountries = array_map(function ($team) { return $team['country']; }, $roster);
        $alreadyAddedCountries = array_diff($alreadyAddedCountries, [$oldTeam['country']]);

        if (in_array($newTeam[0]['country'], $alreadyAddedCountries)) {
            $this->telegram->sendMessage($this->chatId, "ERROR, ja tens un equip d'aquest país");

            return;
        }

        $pointsCost = $this->substitutionCost($playerId);

        $this->substitutionsRepo->addSubstitution(
            $playerId,
            $this->matchDay,
            (int) $oldTeam['id'],
            (int) $newTeam[0]['id'],
            $competition,
            $pointsCost
        );

        $this->telegram->sendMessage(
            $this->chatId,
            "Substitució guardada: " . $oldTeam['name'] . " -> " . $newTeam[0]['name']
                . ($pointsCost === 0 ? " (gratis)" : " (cost: " . $pointsCost . " punts)")
        );
    }

    /**
     * Esborra tots els canvis pendents de la jornada.
     *
     *     /substitució remove
     *
     * No existeix l'esborrat individual. Les validacions de país i de pot es fan
     * sobre la plantilla efectiva (la real amb tots els pendents aplicats), i
     * treure'n un de sol podria deixar els altres en una combinació il·legal. Per
     * exemple, amb Espanya al pot 1 i França al pot 5: si demanes pot 1 ->
     * Alemanya i després pot 5 -> un equip espanyol, els dos canvis junts són
     * legals (Alemanya + Espanya), però si n'esborressis el primer et quedaries
     * amb l'Espanya del pot 1 i el del pot 5, dos equips del mateix país.
     * Esborrar-los tots sempre torna a la plantilla original, que és legal.
     *
     * El comandament no té cap argument: si n'arriba algun s'ignora i s'ho
     * emporta tot, que és l'única cosa que sap fer.
     */
    private function removeSubstitutions(array $pending): void
    {
        if (count($pending) === 0) {
            $this->telegram->sendMessage($this->chatId, "No tens cap substitució pendent");

            return;
        }

        foreach ($pending as $substitution) {
            $this->substitutionsRepo->removePendingSubstitution($substitution['id']);
        }

        $this->telegram->sendMessage(
            $this->chatId,
            count($pending) === 1
                ? "Substitució eliminada"
                : "Canvis eliminats (" . count($pending) . ")"
        );
    }

    /**
     * Files de botons amb els equips del jugador, més els pots que encara té
     * buits, limitades a les competicions sense cap canvi pendent.
     */
    private function teamChoiceRows(int $playerId, array $freeCompetitions): array
    {
        $playerTeams = $this->teamsRepo->getTeamsByPlayerId($playerId);
        $rows        = [];
        $row         = [];
        $emptyPots   = self::EMPTY_POT_SLOTS;

        foreach ($playerTeams as $team) {
            // Un pot ocupat no és mai un forat, encara que la seva competició
            // estigui bloquejada per un canvi pendent.
            unset($emptyPots[$team['pot'] - 1]);

            if (!in_array($team['competition'], $freeCompetitions, true)) {
                continue;
            }

            $row[] = '/out ' . $team['name'];

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        foreach ($emptyPots as $slot) {
            $parsed = $this->parseEmptyPotSlot($slot);

            if ($parsed === null || !in_array($parsed[0], $freeCompetitions, true)) {
                continue;
            }

            $row[] = '/out ' . $slot;

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
     * Files de botons per eliminar.
     *
     * Només hi ha l'opció d'eliminar-ho tot: un canvi individual no es pot
     * treure sense poder deixar la resta en una combinació il·legal, tal com
     * s'explica a removeSubstitutions().
     */
    private function removeRows(): array
    {
        return [['/substitució remove', '/inici']];
    }

    /**
     * Text amb els canvis pendents, buit si no n'hi ha cap.
     */
    private function pendingSubstitutionsText(array $pending): string
    {
        if (count($pending) === 0) {
            return '';
        }

        $text = "Canvis pendents:\n";

        foreach ($pending as $substitution) {
            $newTeam = $this->teamsRepo->getTeamById($substitution['new_team_id']);

            $text .= "- " . $substitution['competition'] . ": "
                . $this->teamName($substitution['old_team_id'])
                . " -> " . $newTeam[0]['name'] . "\n";
        }

        return $text . "\n";
    }

    /**
     * Teclat amb els equips que poden entrar, o error si no n'hi ha cap.
     *
     * @param array $possibleNewTeams Equips filtrats per les regles d'elegibilitat.
     */
    private function sendNewTeamKeyboard(array $possibleNewTeams): void
    {
        if (count($possibleNewTeams) == 0) {
            $this->telegram->sendMessage($this->chatId, "ERROR, no hi ha possibilitats de substitució");

            return;
        }

        $rows = [];
        $row  = [];

        foreach ($possibleNewTeams as $team) {
            $row[] = '/in ' . $team['name'];

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        $keyboard = new ReplyKeyboardMarkup($rows, true, true);

        $this->telegram->sendMessage($this->chatId, "Nou equip:", false, null, null, $keyboard);
    }

    private function sendCompetitionAlreadyUsed(string $competition): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "ERROR, ja tens un canvi pendent a " . $competition . ". Elimina'l abans de fer-ne un altre."
        );
    }

    /**
     * Nom de l'equip, o "Empty" si l'id és 0 o ja no existeix (forat buit).
     */
    private function teamName($teamId): string
    {
        $team = $this->teamsRepo->getTeamById($teamId);

        return isset($team[0]) ? $team[0]['name'] : 'Empty';
    }

    /**
     * Plantilla amb tots els canvis pendents ja aplicats.
     *
     * És la base de les validacions de país i d'equip repetit, perquè dos
     * canvis simultanis no poden deixar la plantilla en un estat il·legal.
     */
    private function getEffectiveTeams(int $playerId, array $pending): array
    {
        $teams = $this->teamsRepo->getTeamsByPlayerId($playerId);

        foreach ($pending as $substitution) {
            $oldTeamId = (int) $substitution['old_team_id'];

            if ($oldTeamId !== 0) {
                foreach ($teams as $index => $team) {
                    if ((int) $team['id'] === $oldTeamId) {
                        unset($teams[$index]);
                        break;
                    }
                }
            }

            $newTeam = $this->teamsRepo->getTeamById($substitution['new_team_id']);
            if (isset($newTeam[0])) {
                $teams[] = $newTeam[0];
            }
        }

        return array_values($teams);
    }

    /**
     * Competicions que ja tenen un canvi pendent.
     */
    private function pendingCompetitions(array $pending): array
    {
        $competitions = [];

        foreach ($pending as $substitution) {
            if (!in_array($substitution['competition'], $competitions, true)) {
                $competitions[] = $substitution['competition'];
            }
        }

        return $competitions;
    }

    private function hasPendingInCompetition(array $pending, string $competition): bool
    {
        return in_array($competition, $this->pendingCompetitions($pending), true);
    }

    /**
     * Els canvis estan tancats a partir de les semifinals.
     */
    private function changesClosed(): bool
    {
        return $this->matchDay > $this->lastMatchDayWithChanges;
    }

    private function sendChangesClosed(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Els canvis estan tancats: no es poden fer canvis a partir de les semifinals."
        );
    }

    /**
     * Punts que costa el canvi: SUBSTITUTION_COST, o 0 si el jugador està a més
     * de FREE_CHANGE_MIN_GAP punts del líder.
     *
     * Es calcula en el moment de registrar el canvi i es desa a la fila, perquè
     * quedi congelat amb la classificació d'aquell moment (el líder i la
     * distància canvien cada jornada). Si encara no hi ha cap classificació
     * publicada, es tracta com a gratuït per no inventar un càrrec.
     */
    private function substitutionCost(int $playerId): int
    {
        $totals = $this->pointsRepo->getLastTotalsByPlayer();

        if (count($totals) === 0 || !isset($totals[$playerId])) {
            return 0;
        }

        $leaderTotal = max($totals);
        $playerTotal = $totals[$playerId];

        return ($leaderTotal - $playerTotal) > self::FREE_CHANGE_MIN_GAP
            ? 0
            : self::SUBSTITUTION_COST;
    }

    /**
     * Desa el pot global (1-12) i la competició d'un botó de pot buit, o null
     * si el botó no és vàlid.
     *
     * @return array{0: string, 1: int}|null
     */
    private function parseEmptyPotSlot(string $slot): ?array
    {
        if (preg_match('/^(CHL|EUL|COL)_Pot_([1-4])$/', $slot, $matches) !== 1) {
            return null;
        }

        return [$matches[1], (int) $matches[2] + self::POT_OFFSETS[$matches[1]]];
    }

    /**
     * Canvis pendents del jugador en la jornada en curs.
     */
    private function getPendingSubstitutions(int $playerId): array
    {
        $pendingSubstitutions = $this->substitutionsRepo->getPendingSubstitutionsByPlayerId($playerId);

        if (!is_array($pendingSubstitutions)) {
            return [];
        }

        return array_values(array_filter($pendingSubstitutions, function ($substitution) {
            return (int) $substitution['match_day'] === $this->matchDay;
        }));
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
     * El jugador encara no ha passat per /equips: no té cap equip i no pot fer
     * canvis. Abans això acabava en error fatal i l'usuari no rebia resposta.
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
