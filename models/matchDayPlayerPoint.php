<?php

class MatchDayPlayerPoint extends Connection {
    public function addMatchDayPlayerPoint(
        int $playerId,
        int $matchDay,
        int $chlPoints,
        string $chlAction,
        int $chlTotal,
        int $eulPoints,
        string $eulAction,
        int $eulTotal,
        int $colPoints,
        string $colAction,
        int $colTotal,
        string $matchDayAction,
        int $matchDayTotal
    ) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO match_day_player_points (player_id, match_day, pot, team_id, points, action, total) VALUES (:player_id, :match_day, :pot, :team_id, :points, :action, :total)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchDay, PDO::PARAM_INT);
            $stmt->bindValue(':chl_points', $chlPoints, PDO::PARAM_INT);
            $stmt->bindValue(':chl_action', $chlAction, PDO::PARAM_STR);
            $stmt->bindValue(':chl_total', $chlTotal, PDO::PARAM_INT);
            $stmt->bindValue(':eul_points', $eulPoints, PDO::PARAM_INT);
            $stmt->bindValue(':eul_action', $eulAction, PDO::PARAM_STR);
            $stmt->bindValue(':eul_total', $eulTotal, PDO::PARAM_INT);
            $stmt->bindValue(':col_points', $colPoints, PDO::PARAM_INT);
            $stmt->bindValue(':col_action', $colAction, PDO::PARAM_STR);
            $stmt->bindValue(':col_total', $colTotal, PDO::PARAM_INT);
            $stmt->bindValue(':match_day_action', $matchDayAction, PDO::PARAM_STR);
            $stmt->bindValue(':match_day_total', $matchDayTotal, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }
}
?>