<?php

require_once(__DIR__ . '/CompetitionToggleAction.php');

/**
 * Acció #malDia (tipus badDay).
 *
 * Serveix per salvar una jornada dolenta: si el jugador fa pocs punts, en suma
 * més. Tota la lògica és a CompetitionToggleAction, compartida amb #socElMillor
 * i #guanyarOMorir; aquí només es diu com es diu esta acció.
 */
class BadDayAction extends CompetitionToggleAction
{
    protected static function commands(): array
    {
        return ['/malDia'];
    }

    protected function actionType(): string
    {
        return 'badDay';
    }
}
