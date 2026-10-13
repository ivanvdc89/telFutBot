<?php

/**
 * Els resultats d'un equip en una jornada.
 *
 * ELS RESULTATS NO S'ACTUALITZEN MAI.
 *
 * team_results té una clau única (team_id, match_day, competition). Un cop desat
 * el resultat d'un equip en una jornada, tornar-lo a desar ha de FALLAR: si els
 * punts d'una jornada ja calculada es poguessin canviar per darrere, la
 * classificació i els punts dels jugadors deixarien de quadrar i ningú no se
 * n'adonaria. Per tant aquí no hi ha cap UPDATE ni cap ON DUPLICATE KEY UPDATE:
 * només INSERT, i el xoc amb la clau única es torna com un error.
 */
class TeamResult extends Connection {
    public function getResultByTeamIdAndMatchDay(int $teamId, int $matchDay) {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from team_results where team_id=? and match_day=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $teamId);
        $sql->bindValue(2, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    /**
     * Tots els resultats d'una jornada.
     *
     * El fan servir els scripts que han de comprovar si una jornada ja està
     * carregada abans de tornar-hi a escriure.
     */
    public function getResultsByMatchDay(int $matchDay) {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from team_results where match_day=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    /**
     * Les sumes de cada equip fins a una jornada, per a la classificació.
     *
     * És la matèria primera de la classificació d'equips
     * (calculateTeamStandings.php). Torna una fila per equip, amb la competició,
     * els punts, els gols a favor i en contra i quantes jornades s'hi han sumat.
     *
     * ELS PUNTS NO SÓN ELS DEL JOC
     *
     * team_results.points són els punts que el joc reparteix al jugador, i a la
     * lligueta de la Conference la victòria en val 4 (el joc ho compensa perquè
     * juga 6 jornades i no 8). La classificació, en canvi, ha de ser la de la
     * competició, on la victòria val 3 a tot arreu: per això els punts es tornen
     * a calcular del resultat de cada partit (3/1/0) i no es fa sum(points).
     *
     * I per això també es mira si el joc hi va donar punts: les jornades
     * simulades de la Conference (jornades 1 i 8) són files 0-0 amb 0 punts i no
     * són cap empat, mentre que un 0-0 de debò en dona 1.
     *
     * Els àlies de l'SQL no poden ser paraules reservades: gf i gc, per exemple,
     * fan fallar la consulta.
     */
    public function getTotalsUpToMatchDay(int $lastMatchDay): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select team_id, competition,
                     sum(case
                           when points = 0 then 0
                           when goals_for > goals_against then 3
                           when goals_for = goals_against then 1
                           else 0
                         end) as standing_points,
                     sum(goals_for) as total_goals_for,
                     sum(goals_against) as total_goals_against,
                     count(*) as match_days
              from team_results
              where match_day <= ?
              group by team_id, competition;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $lastMatchDay, PDO::PARAM_INT);
        $sql->execute();

        $totals = [];
        foreach ($sql->fetchAll(pdo::FETCH_ASSOC) as $row) {
            $totals[(int) $row['team_id']] = [
                'competition'   => (string) $row['competition'],
                'points'        => (int) $row['standing_points'],
                'goals_for'     => (int) $row['total_goals_for'],
                'goals_against' => (int) $row['total_goals_against'],
                'match_days'    => (int) $row['match_days'],
            ];
        }

        return $totals;
    }

    /**
     * Cert si la taula team_results ja té les columnes dels gols.
     *
     * Les columnes es van afegir després de la resta: si la base de dades és
     * d'abans i no s'hi ha aplicat l'ALTER, val més dir-ho clarament que no pas
     * deixar que l'INSERT falli amb "Unknown column".
     */
    public function hasGoalColumns(): bool
    {
        try {
            $connection = parent::connect();

            $sql  = "SHOW COLUMNS FROM team_results
                     WHERE Field IN ('goals_for', 'goals_against', 'goal_difference');";
            $stmt = $connection->prepare($sql);
            $stmt->execute();

            return count($stmt->fetchAll(pdo::FETCH_ASSOC)) === 3;

        } catch (PDOException $e) {
            error_log("DB error: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Desa els punts i els gols d'un equip en una jornada.
     *
     * Només INSERT: si l'equip ja té resultat en aquella jornada, la clau única
     * fa fallar la inserció, es deixa constància al registre d'errors i es torna
     * false. No s'actualitza res.
     *
     * La diferència de gols no s'hi passa: es calcula aquí a partir dels gols a
     * favor i en contra, que són les dades de debò. Aixina la columna desada no pot
     * quedar mai descompensada amb el resultat del partit.
     */
    public function addTeamResult(
        int $teamId,
        int $teamPts,
        int $goalsFor,
        int $goalsAgainst,
        int $matchday,
        string $competition
    ) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO team_results
                        (team_id, points, goals_for, goals_against, goal_difference, match_day, competition)
                    VALUES
                        (:team_id, :points, :goals_for, :goals_against, :goal_difference, :match_day, :competition)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
            $stmt->bindValue(':points', $teamPts, PDO::PARAM_INT);
            $stmt->bindValue(':goals_for', $goalsFor, PDO::PARAM_INT);
            $stmt->bindValue(':goals_against', $goalsAgainst, PDO::PARAM_INT);
            $stmt->bindValue(':goal_difference', $goalsFor - $goalsAgainst, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchday, PDO::PARAM_INT);
            $stmt->bindValue(':competition', $competition, PDO::PARAM_STR);
            $stmt->execute();

            return true;

        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                error_log(sprintf(
                    "team_results: l'equip %d ja té resultat a la jornada %d (%s). No s'ha desat res ni s'ha actualitzat.",
                    $teamId,
                    $matchday,
                    $competition
                ));
            } else {
                error_log("DB insert error: " . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Cert si l'error és un xoc amb una clau única.
     *
     * MySQL i MariaDB el donen com a errno 1062 dins de SQLSTATE 23000; per si
     * algun dia canvia, també es mira el text.
     */
    private static function isDuplicateKey(PDOException $e): bool
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return true;
        }

        return str_contains($e->getMessage(), 'Duplicate entry');
    }
}
?>
