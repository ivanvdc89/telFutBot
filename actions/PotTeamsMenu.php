<?php

require_once(__DIR__ . '/../config/pots.php');
require_once(__DIR__ . '/../models/team.php');

/**
 * La tria d'equips per pots, compartida per /equips i /add.
 *
 * Les dos accions feien la mateixa passejada: mirar quins pots té el jugador,
 * triar el primer que li falta, traure els equips del país que ja té i fer-ne
 * files de tres. Viure ací evita que una de les dos es quede enrere.
 */
final class PotTeamsMenu
{
    /**
     * El pot següent i les files de botons amb els equips que hi pot triar.
     *
     * @return array{pot: int|null, rows: array<int, array<int, string>>} pot és
     *         null quan el jugador ja té tots els equips.
     */
    public static function next(Team $teamsRepo, int $playerId): array
    {
        $playerTeams = $teamsRepo->getTeamsByPlayerId($playerId);

        $pots      = array_map(function ($team) { return $team['pot']; }, $playerTeams);
        $countries = array_map(function ($team) { return $team['country']; }, $playerTeams);

        $remainingPots = array_diff(POTS, $pots);

        if (count($remainingPots) === 0) {
            return ['pot' => null, 'rows' => []];
        }

        $nextPot = (int) min($remainingPots);

        $rows = [];
        $row  = [];

        foreach ($teamsRepo->getTeamsByPot($nextPot) as $team) {
            if (in_array($team['country'], $countries, true)) {
                continue;
            }

            $row[] = '/add ' . $team['name'];

            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }

        if (count($row) !== 0) {
            $rows[] = $row;
        }

        return ['pot' => $nextPot, 'rows' => $rows];
    }
}
