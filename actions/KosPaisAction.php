<?php

require_once(__DIR__ . '/KosAction.php');

/**
 * Acció #kosPais (tipus kosPais).
 *
 * És la votació de boicot per països: els equips dels 2 països més votats no
 * sumen punts, i cada escut protegeix un dels equips propis del boicot.
 *
 *     /kosPais            menú de països i d'escuts
 *     /kosPais vot Spain  registra un vot de KO a un país
 *     /kosPais escut CHL  els teus equips d'aquella competició per escudar
 *     /kosPais escut EQUIP posa un escut a l'equip
 *     /kosPais borrar     esborra tots els vots i escuts
 *
 * Els vots es desen a la fila d'accions del jugador com un JSON
 * {max, paisos:["Spain", ...], shields:[teamId, ...]}: el país es desa pel nom
 * perquè és la dada que hi ha a teams.country i així l'anunci i els punts no
 * hagen de traduir res.
 */
class KosPaisAction extends KosAction
{
    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'kosPais';

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
        return 'Països disponibles:';
    }

    protected function voteCommand(): string
    {
        return 'vot';
    }

    protected function menuRows(array $data, int $maxVotes): array
    {
        return $this->labelRows($this->teamsRepo->getCountries(), $this->command() . ' vot ');
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
