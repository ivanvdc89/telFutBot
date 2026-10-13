<?php

/**
 * La classificació d'equips guardada (team_standings).
 *
 * ÉS UNA TAULA DERIVADA de team_results: aquí no s'hi decideix res. La que mana
 * és calculateTeamStandings.php, que la recalcula amb config/standings.php i la
 * reescriu sencera. Es guarda perquè les accions de votació puguin desempatar
 * (quin equip queda KO amb els mateixos vots, quin és el segon més votat...) amb
 * una sola consulta, i perquè la classificació es pugui mirar quan calgui.
 *
 * La posició (ranking) és dins de cada competició: 1 és el primer de la seva.
 */
class TeamStanding extends Connection {
    /**
     * La classificació d'una competició, del primer a l'últim.
     *
     * @param string $competition Codi del joc: 'CHL', 'EUL' o 'COL'.
     */
    public function getStandingsByCompetition(string $competition): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from team_standings where competition=? order by ranking asc;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $competition);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    /**
     * La posició de cada equip, com a [team_id => ranking].
     *
     * És el que necessiten els desempats per vots: amb el mapa sencer no cal cap
     * consulta per equip.
     */
    public function getRankingsByTeam(): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select team_id, ranking from team_standings;";
        $sql=$connection->prepare($sql);
        $sql->execute();

        $rankings = [];
        foreach ($sql->fetchAll(pdo::FETCH_ASSOC) as $row) {
            $rankings[(int) $row['team_id']] = (int) $row['ranking'];
        }

        return $rankings;
    }

    /**
     * Reescriu la taula sencera amb les files passades.
     *
     * La classificació és un recanvi complet, no una actualització fila a fila:
     * primer s'esborra tot i després s'hi tornen a posar les files. Qui crida
     * este mètode ha d'haver obert una transacció (com fan els altres
     * scripts): si una inserció falla, torna false i la transacció de fora desfà
     * també l'esborrat, de manera que la taula no queda mai a mitges.
     *
     * @param array<int, array> $rows Files de standingsRows(), amb team_id, competition, ranking, points, goals_for, goals_against, goal_difference i match_days.
     */
    public function replaceStandings(array $rows): bool
    {
        try {
            $connection = parent::connect();

            $connection->exec('DELETE FROM team_standings;');

            $sql = "INSERT INTO team_standings
                        (team_id, competition, ranking, points, goals_for, goals_against, goal_difference, match_days)
                    VALUES
                        (:team_id, :competition, :ranking, :points, :goals_for, :goals_against, :goal_difference, :match_days)";
            $stmt = $connection->prepare($sql);

            foreach ($rows as $row) {
                $stmt->bindValue(':team_id', (int) $row['team_id'], PDO::PARAM_INT);
                $stmt->bindValue(':competition', (string) $row['competition'], PDO::PARAM_STR);
                $stmt->bindValue(':ranking', (int) $row['ranking'], PDO::PARAM_INT);
                $stmt->bindValue(':points', (int) $row['points'], PDO::PARAM_INT);
                $stmt->bindValue(':goals_for', (int) $row['goals_for'], PDO::PARAM_INT);
                $stmt->bindValue(':goals_against', (int) $row['goals_against'], PDO::PARAM_INT);
                $stmt->bindValue(':goal_difference', (int) $row['goal_difference'], PDO::PARAM_INT);
                $stmt->bindValue(':match_days', (int) $row['match_days'], PDO::PARAM_INT);
                $stmt->execute();
            }

            return true;

        } catch (PDOException $e) {
            error_log("DB error: " . $e->getMessage());

            return false;
        }
    }
}
