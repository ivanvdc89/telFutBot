<?php

require_once(__DIR__ . '/CommandAction.php');
require_once(__DIR__ . '/InlineMenu.php');
require_once(__DIR__ . '/../config/classification.php');
require_once(__DIR__ . '/../models/player.php');
require_once(__DIR__ . '/../models/matchDayPlayerPoint.php');

use TelegramBot\Api\BotApi;

/**
 * Les classificacions: /classificacio.
 *
 *   /classificacio                 el menú: general o per competició
 *   /classificacio general         la general (players.points)
 *   /classificacio CHL             la classificació de la competició
 *   /classificacio competicions    àlies del menú, pels botons vells
 *
 * És només de lectura i no depén de cap jugador concret: ho pot consultar
 * qualsevol, també qui encara no ha triat equips.
 *
 * El text s'envia dins d'un <pre> (parse_mode HTML) perquè les columnes
 * s'alineen amb espais, igual que a publishResults.php. Les posicions són
 * compartides: dos jugadors amb els mateixos punts tenen la mateixa i el
 * següent se'n salta una.
 */
class ClassificationAction extends CommandAction
{
    private Player $playersRepo;
    private MatchDayPlayerPoint $pointsRepo;

    public function __construct(
        BotApi $telegram,
        Player $playersRepo,
        MatchDayPlayerPoint $pointsRepo,
        int $chatId,
        array $args
    ) {
        parent::__construct($telegram, $chatId, $args);

        $this->playersRepo = $playersRepo;
        $this->pointsRepo  = $pointsRepo;
    }

    protected static function commands(): array
    {
        return ['/classificacio', '/classificacions'];
    }

    public function run(): void
    {
        $subCommand = $this->subCommand();

        if ($subCommand === 'general') {
            $this->showGeneral();

            return;
        }

        if (in_array($subCommand, ['CHL', 'EUL', 'COL'], true)) {
            $this->showCompetition($subCommand);

            return;
        }

        // Sense argument, amb 'competicions' (botons vells) o amb qualsevol
        // cosa que no siga una competició: el menú, que és on es tria.
        $this->showMenu();
    }

    /**
     * La classificació general: tots els jugadors pels punts de players.points.
     */
    private function showGeneral(): void
    {
        $players = $this->playersRepo->getAllPlayers();

        $text = classificationText(
            '🏆 Classificació general',
            classificationGeneralRows($players),
            classificationPlayerNames($players)
        );

        $this->sendTable($text, [['/classificacio', '/data']]);
    }

    /**
     * El menú: la general o una de les tres competicions.
     */
    private function showMenu(): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            "Classificacions:",
            false,
            null,
            null,
            InlineMenu::fromCommands([
                ['/classificacio general'],
                ['/classificacio CHL', '/classificacio EUL', '/classificacio COL'],
                ['/data'],
            ])
        );
    }

    /**
     * La classificació d'una competició, pels punts dels equips del jugador.
     *
     * Només s'ensenyen els cinc primers: el que interessa és vore qui mana i
     * quina distància trau a la resta. La posició és la de la classificació
     * completa, no la del retall.
     */
    private function showCompetition(string $competition): void
    {
        $players = $this->playersRepo->getAllPlayers();

        $text = classificationText(
            '🏆 Classificació ' . $competition,
            classificationCompetitionRows(
                $players,
                $this->pointsRepo->getLatestTotalsByPlayer(),
                $competition
            ),
            classificationPlayerNames($players),
            5
        );

        $this->sendTable($text, [['/classificacio', '/classificacio general']]);
    }

    /**
     * Envia una classificació dins d'un bloc de codi, amb els botons de tornada.
     *
     * @param array<int, array<int, string>> $buttonRows
     */
    private function sendTable(string $text, array $buttonRows): void
    {
        $this->telegram->sendMessage(
            $this->chatId,
            resultsPre($text),
            'HTML',
            false,
            null,
            InlineMenu::fromCommands($buttonRows)
        );
    }
}
