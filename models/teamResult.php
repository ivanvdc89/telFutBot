<?php
class TeamResult extends Connection {
    public function addTeamPoints(
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

            $sql = "INSERT INTO team_points (player_id, match_day, pot, team_id, points, action, total) VALUES (:player_id, :match_day, :pot, :team_id, :points, :action, :total)";
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
}
?>