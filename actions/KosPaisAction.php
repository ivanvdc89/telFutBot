<?php

require_once(__DIR__ . '/KosAction.php');

use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Acció #kosPais (tipus kosPais).
 *
 * És la votació de boicot per països: els equips dels 2 països més votats no
 * sumen punts, i cada escut protegeix un dels equips propis del boicot.
 *
 *     /kosPais               menú de països i d'escuts
 *     /kosPais paisos 2      la segona pàgina de països
 *     /kosPais vot Spain     registra un vot de KO a un país
 *     /kosPais escut CHL     els teus equips d'aquella competició per escudar
 *     /kosPais escut EQUIP   posa un escut a l'equip
 *     /kosPais borrar        esborra tots els vots i escuts
 *
 * Els vots es desen a la fila d'accions del jugador com un JSON
 * {max, paisos:["Spain", ...], shields:[teamId, ...]}: el país es desa pel nom
 * perquè és la dada que hi ha a teams.country i així l'anunci i els punts no
 * hagen de traduir res.
 *
 * Els països ixen paginats (PAISOS_PER_PAGINA per pàgina) perquè la llista
 * sencera no cap en un teclat de Telegram sense convertir-se en un mur de
 * botons. L'orde és el de Team::getCountries(): els països amb més punts sumats
 * primer, perquè a l'hora de votar es veja de seguida quins són els forts.
 */
class KosPaisAction extends KosAction
{
    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'kosPais';

    /**
     * Països per pàgina: quatre files de tres botons.
     */
    private const PAISOS_PER_PAGINA = 12;

    /**
     * Pàgina que s'està mostrant. La mou el subcomandament 'paisos' i la
     * lligen menuRows() i menuTitle().
     */
    private int $page = 1;

    protected static function commands(): array
    {
        return ['/kosPais'];
    }

    protected function actionType(): string
    {
        return self::ACTION_TYPE;
    }

    protected function voteKey(): string
    {
        return 'paisos';
    }

    protected function votesTitle(): string
    {
        return 'Països votats:';
    }

    protected function voteNoun(): string
    {
        return 'països';
    }

    protected function menuTitle(): string
    {
        $pages = $this->pageCount();

        if ($pages <= 1) {
            return 'Països disponibles:';
        }

        return 'Països disponibles (pàgina ' . $this->page . ' de ' . $pages . '):';
    }

    protected function voteCommand(): string
    {
        return 'vot';
    }

    /**
     * Els països de la pàgina en curs.
     */
    protected function menuRows(array $data, int $maxVotes): array
    {
        $countries = $this->teamsRepo->getCountries();
        $offset    = ($this->page - 1) * self::PAISOS_PER_PAGINA;

        return $this->labelRows(
            array_slice($countries, $offset, self::PAISOS_PER_PAGINA),
            $this->command() . ' vot '
        );
    }

    /**
     * El teclat de la pàgina: països, navegació i escuts.
     */
    protected function menuKeyboard(array $data, int $maxVotes): InlineKeyboardMarkup
    {
        $keyboard = InlineMenu::fromCommands($this->menuRows($data, $maxVotes));
        $nav      = $this->navButtons();

        if (count($nav) > 0) {
            $keyboard = InlineMenu::appendRow($keyboard, $nav);
        }

        return InlineMenu::appendRow($keyboard, $this->buttonsFor($this->shieldRows()));
    }

    /**
     * '‹ Anterior' i 'Següent ›', només els que tenen sentit en esta pàgina.
     *
     * Els botons porten el comandament de sempre (/kosPais paisos N): el router
     * els torna a passar per handleModeSubCommand().
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    private function navButtons(): array
    {
        $nav = [];

        if ($this->page > 1) {
            $nav[] = InlineMenu::button(
                '‹ Anterior',
                commandCallbackData($this->command() . ' paisos ' . ($this->page - 1))
            );
        }

        if ($this->page < $this->pageCount()) {
            $nav[] = InlineMenu::button(
                'Següent ›',
                commandCallbackData($this->command() . ' paisos ' . ($this->page + 1))
            );
        }

        return $nav;
    }

    /**
     * Quantes pàgines hi ha amb els països de hui.
     */
    private function pageCount(): int
    {
        return max(1, (int) ceil(count($this->teamsRepo->getCountries()) / self::PAISOS_PER_PAGINA));
    }

    /**
     * /kosPais paisos N: canvia de pàgina i torna a mostrar el menú.
     *
     * Un número fora de rang (0, 99, una lletra) es queda dins de les pàgines:
     * val més ensenyar la primera o l'última que un error.
     */
    protected function handleModeSubCommand(string $subCommand, int $actionId, array $data, int $maxVotes): bool
    {
        if ($subCommand !== 'paisos') {
            return false;
        }

        $this->page = max(1, min((int) $this->argument(2), $this->pageCount()));
        $this->sendMenu($data, $maxVotes);

        return true;
    }

    protected function registerVote(int $actionId, array $data, int $maxVotes): void
    {
        if ($this->hasMaxVotes($data, $maxVotes)) {
            $this->sendKosComplete();

            return;
        }

        // El país pot tindre espais al nom ("Bosnia and Herzegovina"), així que
        // el vot és tot el que ve després del subcomandament.
        $country = trim(implode(' ', array_slice($this->args, 2)));

        if (!in_array($country, $this->teamsRepo->getCountries(), true)) {
            $this->sendVoteError("ERROR, el país no existeix");

            return;
        }

        $data['paisos'][] = $country;

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $this->sendVoteSaved($data, $maxVotes);
    }

    protected function voteText($vote): string
    {
        return (string) $vote;
    }
}
