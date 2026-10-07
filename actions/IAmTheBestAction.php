<?php

require_once(__DIR__ . '/CompetitionToggleAction.php');

/**
 * Acció #socElMillor (tipus iAmTheBest).
 *
 * Entre els jugadors que l'activen a una competició, els que sumen més punts
 * reben un extra i la resta una penalització. Tota la lògica és a
 * CompetitionToggleAction, compartida amb #malDia i #guanyarOMorir; aquí només
 * es diu com es diu aquesta acció.
 */
class IAmTheBestAction extends CompetitionToggleAction
{
    public static function command(): string
    {
        return '/socElMillor';
    }

    protected function actionType(): string
    {
        return 'iAmTheBest';
    }
}
