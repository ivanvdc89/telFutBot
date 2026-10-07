<?php

require_once(__DIR__ . '/CompetitionToggleAction.php');

/**
 * Acció #guanyarOMorir (tipus winOrDie).
 *
 * Per cada victòria dels seus equips suma un punt extra als punts de la jornada
 * i per cada empat o derrota en resta un. Tota la lògica és a
 * CompetitionToggleAction, compartida amb #malDia i #socElMillor; aquí només es
 * diu com es diu aquesta acció.
 */
class WinOrDieAction extends CompetitionToggleAction
{
    public static function command(): string
    {
        return '/guanyarOMorir';
    }

    protected function actionType(): string
    {
        return 'winOrDie';
    }

    protected function label(): string
    {
        return '#guanyarOMorir';
    }
}
