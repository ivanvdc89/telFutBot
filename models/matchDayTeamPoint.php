<?php
class MatchDayTeamPoint extends Connection {
    public function addMatchDayTeamPoint(
        int $playerId,
        int $matchDay,
        int $pot,
        int $teamId,
        int $points,
        string $action,
        int $total
    ) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO match_day_team_points (player_id, match_day, pot, team_id, points, action, total) VALUES (:player_id, :match_day, :pot, :team_id, :points, :action, :total)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchDay, PDO::PARAM_INT);
            $stmt->bindValue(':pot', $pot, PDO::PARAM_INT);
            $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
            $stmt->bindValue(':points', $points, PDO::PARAM_INT);
            $stmt->bindValue(':action', $action, PDO::PARAM_STR);
            $stmt->bindValue(':total', $total, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }

    public function getAllMatchDayTeamPointsByPlayerIdAndMatchDay(int $player_id, int $matchDay): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from match_day_team_points where player_id=? and match_day=? order by pot asc;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $player_id);
        $sql->bindValue(2, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    /**
     * Cert si la jornada ja té punts d'equip calculats.
     *
     * És la segona meitat de la guarda de calculateMatchDayPoints.php: si una
     * execució va quedar a mitges, potser hi ha files ací encara que
     * match_day_player_points estiga buit.
     */
    public function matchDayHasPoints(int $matchDay): bool
    {
        $connection = parent::connect();
        $sql = "select count(*) as total from match_day_team_points where match_day=?;";
        $sql = $connection->prepare($sql);
        $sql->bindValue(1, $matchDay, PDO::PARAM_INT);
        $sql->execute();

        $row = $sql->fetch(pdo::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * Esborra els punts d'equip d'una jornada, per tornar-los a calcular.
     */
    public function deleteByMatchDay(int $matchDay): void
    {
        $connection = parent::connect();
        $sql = "delete from match_day_team_points where match_day=?;";
        $sql = $connection->prepare($sql);
        $sql->bindValue(1, $matchDay, PDO::PARAM_INT);
        $sql->execute();
    }
}
?>