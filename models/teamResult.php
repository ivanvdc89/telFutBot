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
     * favor i en contra, que són les dades de debò. Així la columna desada no pot
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
