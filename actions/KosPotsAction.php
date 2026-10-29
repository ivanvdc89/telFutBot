<?php

require_once(__DIR__ . '/KosAction.php');

/**
 * Acció #kosPots (tipus kosPots).
 *
 * És la votació de boicot per pots: els equips dels 2 pots més votats no sumen
 * punts, i cada escut protegeix un dels equips propis del boicot.
 *
 *     /kosPots             menú de pots i d'escuts
 *     /kosPots vot CHL_1   registra un vot de KO a un pot
 *     /kosPots escut CHL   els teus equips d'aquella competició per escudar
 *     /kosPots escut EQUIP posa un escut a l'equip
 *     /kosPots borrar      esborra tots els vots i escuts
 *
 * Els vots es desen a la fila d'accions del jugador com un JSON
 * {max, pots:[potGlobal, ...], shields:[teamId, ...]}: el pot es desa pel
 * número global (1-12) i es mostra amb l'etiqueta de competició (CHL_1), com
 * fan /equips i /data.
 */
class KosPotsAction extends KosAction
{
    /**
     * Valor de la columna `type` a la taula actions.
     */
    private const ACTION_TYPE = 'kosPots';

    protected static function commands(): array
    {
        return ['/kosPots'];
    }

    protected function actionType(): string
    {
        return self::ACTION_TYPE;
    }

    protected function voteKey(): string
    {
        return 'pots';
    }

    protected function votesTitle(): string
    {
        return 'Pots votats:';
    }

    protected function voteNoun(): string
    {
        return 'pots';
    }

    protected function menuTitle(): string
    {
        return 'Pots disponibles:';
    }

    protected function voteCommand(): string
    {
        return 'vot';
    }

    protected function menuRows(array $data, int $maxVotes): array
    {
        return $this->potRows($this->command() . ' vot ');
    }

    protected function registerVote(int $actionId, array $data, int $maxVotes): void
    {
        if ($this->hasMaxVotes($data, $maxVotes)) {
            $this->sendKosComplete();

            return;
        }

        $globalPot = potFromLabel($this->argument(2));

        if ($globalPot === null) {
            $this->sendVoteError("ERROR, pot invàlid");

            return;
        }

        $data['pots'][] = $globalPot;

        if (!$this->saveActionRow($actionId, $data)) {
            $this->sendSaveError();

            return;
        }

        $this->sendVoteSaved($data, $maxVotes);
    }

    protected function voteText($vote): string
    {
        return potMenuLabel(potLabel((int) $vote));
    }
}
