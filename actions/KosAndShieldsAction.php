<?php

require_once(__DIR__ . '/KosAction.php');
require_once(__DIR__ . '/../config/pots.php');

/**
 * Acció #kosAmbEscuts (tipus kosAndShields).
 *
 * És la votació de boicot per equips: els 4 equips més votats no sumen punts, i
 * cada escut protegeix un dels equips propis del boicot.
 *
 *     /kos                    menú de pots i d'escuts
 *     /kos ko_pot CHL_1       equips d'aquell pot per votar-hi en contra
 *     /kos ko_vot EQUIP       registra un vot de KO
 *     /kos escut CHL          els teus equips d'aquella competició per escudar
 *     /kos escut EQUIP        posa un escut a l'equip
 *     /kos borrar             esborra tots els vots i escuts
 *
 * Els vots i els escuts es desen a la fila d'accions del jugador com un JSON
 * {max, kos:[teamId, ...], shields:[teamId, ...]}. L'esquelet (el màxim de vots,
 * la llista, els escuts i l'esborrat) viu a KosAction, que es comparteix amb
 * #kosPais i #kosPots.
 */
class KosAndShieldsAction extends KosAction
{
    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'kosAndShields';

    protected static function commands(): array
    {
        return ['/kos'];
    }

    protected function actionType(): string
    {
        return self::ACTION_TYPE;
    }

    protected function voteKey(): string
    {
        return 'kos';
    }

    protected function votesTitle(): string
    {
        return 'Equips votats:';
    }

    protected function voteNoun(): string
    {
        return 'kos';
    }

    protected function menuTitle(): string
    {
        return 'Pots disponibles:';
    }

    protected function voteCommand(): string
    {
        return 'ko_vot';
    }

    protected function menuRows(array $data, int $maxVotes): array
    {
        return $this->potRows($this->command() . ' ko_pot ');
    }

    protected function handleModeSubCommand(string $subCommand, int $actionId, array $data, int $maxVotes): bool
    {
        if ($subCommand !== 'ko_pot') {
            return false;
        }

        $this->sendPotTeams($data, $maxVotes);

        return true;
    }

    protected function registerVote(int $actionId, array $data, int $maxVotes): void
    {
        if ($this->hasMaxVotes($data, $maxVotes)) {
            $this->sendKosComplete();

            return;
        }

        $team = $this->teamsRepo->getTeamByName($this->argument(2));

        if (count($team) === 0) {
            $this->sendVoteError("ERROR, l'equip no existeix");

            return;
        }

        $data['kos'][] = $team[0]['id'];

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $this->sendVoteSaved($data, $maxVotes);
    }

    protected function voteText($vote): string
    {
        return $this->teamsRepo->getTeamName((int) $vote);
    }

    /**
     * Equips d'un pot per votar-hi en contra.
     */
    private function sendPotTeams(array $data, int $maxVotes): void
    {
        if ($this->hasMaxVotes($data, $maxVotes)) {
            $this->sendKosComplete();

            return;
        }

        $globalPot = potFromLabel($this->argument(2));

        if ($globalPot === null) {
            $this->telegram->sendMessage($this->chatId, "ERROR, pot invàlid");

            return;
        }

        $rows = $this->teamRows($this->teamsRepo->getTeamsByPot($globalPot), $this->command() . ' ko_vot ');

        if (count($rows) === 0) {
            $this->telegram->sendMessage(
                $this->chatId,
                "ERROR, no hi ha equips en el pot " . potMenuLabel(potLabel($globalPot))
            );

            return;
        }

        $this->telegram->sendMessage(
            $this->chatId,
            "Equips del pot " . potMenuLabel(potLabel($globalPot)) . ":",
            false,
            null,
            null,
            InlineMenu::fromCommands($rows)
        );
    }
}
