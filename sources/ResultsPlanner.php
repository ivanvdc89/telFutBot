<?php

require_once(__DIR__ . '/ResultsSource.php');
require_once(__DIR__ . '/TeamNameResolver.php');
require_once(__DIR__ . '/../config/results.php');

/**
 * Decideix què s'ha de desar a team_results per a una jornada.
 *
 * És la part de getResults.php que no toca ni la xarxa ni la base de dades, i
 * per tant es pot provar: donada una font de partits i un traductor de noms,
 * torna
 *
 *   - 'matches'  tots els partits amb l'estat de cada equip (resolt, exclòs o
 *                no resolt) i els punts que li toquen;
 *   - 'rows'     les files que s'han d'escriure, una per equip;
 *   - 'problems' els equips que no s'han sabut reconèixer;
 *   - 'empty'    les competicions que no tenen cap partit publicat.
 *
 * ELS ESTATS EXCLOSOS
 *
 * Hi ha estats els equips dels quals no participen al joc (vegeu
 * RESULTS_EXCLUDED_COUNTRIES a config/results.php). Quan un partit en porta
 * un, l'equip exclòs es marca i no es desa; el rival sí, perquè els seus punts
 * només depenen del resultat. Un partit amb un equip exclòs no és cap problema i
 * no ha de bloquejar la jornada.
 */
final class ResultsPlanner
{
    private ResultsSource $source;
    private TeamNameResolver $resolver;

    /** @var array<string, true> Codis de país exclosos. */
    private array $excludedCountries;

    /**
     * @param array<int, string> $excludedCountries Codis de país que no es desen.
     */
    public function __construct(ResultsSource $source, TeamNameResolver $resolver, array $excludedCountries = [])
    {
        $this->source            = $source;
        $this->resolver          = $resolver;
        $this->excludedCountries = array_fill_keys($excludedCountries, true);
    }

    /**
     * @param array<int, string> $competitions
     *
     * @return array{matches:array<int, array>, rows:array<int, array>, problems:array<int, array>, empty:array<int, string>, simulated:array<int, string>}
     */
    public function plan(array $competitions, int $matchDay): array
    {
        $matches   = [];
        $problems  = [];
        $empty     = [];
        $simulated = [];
        $forced    = [];

        foreach ($competitions as $competition) {
            $sourceMatches = $this->source->getMatches($competition, $matchDay);

            // Jornada de lliga que aquesta competició no juga (la Conference en té
            // 6 i el joc 8): es dona per jugada i tots els seus equips hi tenen 0
            // punts i 0 de diferència de gols.
            if ($sourceMatches === [] && resultsIsSimulatedLeagueMatchDay($competition, $matchDay)) {
                $simulated[] = $competition;

                foreach ($this->resolver->teamsInCompetition($competition) as $team) {
                    $forced[$competition . '|' . $team['id']] = [
                        'competition'    => $competition,
                        'teamId'         => $team['id'],
                        'teamName'       => $team['name'],
                        'points'         => 0,
                        'goalDifference' => 0,
                        'label'          => 'jornada simulada',
                    ];
                }

                continue;
            }

            if ($sourceMatches === []) {
                $empty[] = $competition;
                continue;
            }

            foreach ($sourceMatches as $match) {
                $matches[] = $this->planMatch($competition, $matchDay, $match, $problems);
            }
        }

        return [
            'matches'   => $matches,
            'rows'      => array_values(self::rows($matches) + $forced),
            'problems'  => $problems,
            'empty'     => $empty,
            'simulated' => $simulated,
        ];
    }

    /**
     * @param array{home:string, away:string, home_country:string, away_country:string, home_goals:int, away_goals:int} $match
     * @param array<int, array>                                                                                        $problems
     */
    private function planMatch(string $competition, int $matchDay, array $match, array &$problems): array
    {
        // A la lligueta de la Conference la victòria val 4 punts; a la resta, 3.
        [$homePoints, $awayPoints] = self::pointsFor(
            $match['home_goals'],
            $match['away_goals'],
            resultsWinPoints($competition, $matchDay)
        );

        // La diferència de gols és a favor de cada equip: el local suma els seus
        // gols menys els del visitant, i el visitant al revés. Serveix per
        // desempatar la classificació d'equips.
        return [
            'competition' => $competition,
            'label'       => sprintf('%s %d-%d %s', $match['home'], $match['home_goals'], $match['away_goals'], $match['away']),
            'home'        => $this->planSide(
                $competition,
                $match['home'],
                $match['home_country'],
                $homePoints,
                $match['home_goals'] - $match['away_goals'],
                $problems
            ),
            'away'        => $this->planSide(
                $competition,
                $match['away'],
                $match['away_country'],
                $awayPoints,
                $match['away_goals'] - $match['home_goals'],
                $problems
            ),
        ];
    }

    /** @param array<int, array> $problems */
    private function planSide(
        string $competition,
        string $name,
        string $country,
        int $points,
        int $goalDifference,
        array &$problems
    ): array {
        if (isset($this->excludedCountries[$country])) {
            return [
                'source'         => $name,
                'excluded'       => true,
                'teamId'         => null,
                'teamName'       => null,
                'points'         => $points,
                'goalDifference' => $goalDifference,
            ];
        }

        $result = $this->resolver->rank($name, $competition);

        if ($result['id'] === null) {
            $problems[] = [
                'competition' => $competition,
                'name'        => $name,
                'result'      => $result,
            ];

            return [
                'source'         => $name,
                'excluded'       => false,
                'teamId'         => null,
                'teamName'       => null,
                'points'         => $points,
                'goalDifference' => $goalDifference,
                'problem'        => true,
            ];
        }

        return [
            'source'         => $name,
            'excluded'       => false,
            'teamId'         => $result['id'],
            'teamName'       => $result['name'],
            'points'         => $points,
            'goalDifference' => $goalDifference,
        ];
    }

    /**
     * Una fila per equip i competició, sense duplicats.
     *
     * Si la font repetís un partit, l'equip només hi pot sortir una vegada: la
     * taula team_results té una clau única per equip, jornada i competició.
     *
     * @param array<int, array> $matches
     *
     * @return array<string, array{competition:string, teamId:int, teamName:string, points:int, goalDifference:int, label:string}> Claus: competició|equip.
     */
    private static function rows(array $matches): array
    {
        $rows = [];

        foreach ($matches as $match) {
            foreach (['home', 'away'] as $side) {
                $planned = $match[$side];

                if ($planned['teamId'] === null) {
                    continue;
                }

                $key        = $match['competition'] . '|' . $planned['teamId'];
                $rows[$key] = [
                    'competition'    => $match['competition'],
                    'teamId'         => $planned['teamId'],
                    'teamName'       => $planned['teamName'],
                    'points'         => $planned['points'],
                    'goalDifference' => $planned['goalDifference'],
                    'label'          => $match['label'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Punts de cada equip segons el resultat.
     *
     * La victòria val $winPoints (4 a la lligueta de la Conference, 3 a la
     * resta); l'empat sempre val 1 i la derrota 0.
     */
    private static function pointsFor(int $homeGoals, int $awayGoals, int $winPoints): array
    {
        if ($homeGoals > $awayGoals) {
            return [$winPoints, 0];
        }
        if ($awayGoals > $homeGoals) {
            return [0, $winPoints];
        }

        return [1, 1];
    }
}
