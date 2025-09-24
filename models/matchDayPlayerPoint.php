<?php

class MatchDayPlayerPoint extends Connection {
    public function addMatchDayPlayerPoint(
        int $playerId,
        int $matchDay,
        int $chlPoints,
        string $chlAction,
        int $chlTotal,
        int $chlSum,
        int $eulPoints,
        string $eulAction,
        int $eulTotal,
        int $eulSum,
        int $colPoints,
        string $colAction,
        int $colTotal,
        int $colSum,
        string $matchDayAction,
        int $matchDayTotal,
        int $sum
    ) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO match_day_player_points (
                        player_id, match_day, chl_points, chl_action, chl_total, chl_sum, eul_points, eul_action, eul_total, eul_sum, col_points, col_action, col_total, col_sum, match_day_action, match_day_total, sum
                    ) VALUES (
                        :player_id, :match_day, :chl_points, :chl_action, :chl_total, :chl_sum, :eul_points, :eul_action, :eul_total, :eul_sum, :col_points, :col_action, :col_total, :col_sum, :match_day_action, :match_day_total, :sum
                    )";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchDay, PDO::PARAM_INT);
            $stmt->bindValue(':chl_points', $chlPoints, PDO::PARAM_INT);
            $stmt->bindValue(':chl_action', $chlAction, PDO::PARAM_STR);
            $stmt->bindValue(':chl_total', $chlTotal, PDO::PARAM_INT);
            $stmt->bindValue(':chl_sum', $chlSum, PDO::PARAM_INT);
            $stmt->bindValue(':eul_points', $eulPoints, PDO::PARAM_INT);
            $stmt->bindValue(':eul_action', $eulAction, PDO::PARAM_STR);
            $stmt->bindValue(':eul_total', $eulTotal, PDO::PARAM_INT);
            $stmt->bindValue(':eul_sum', $eulSum, PDO::PARAM_INT);
            $stmt->bindValue(':col_points', $colPoints, PDO::PARAM_INT);
            $stmt->bindValue(':col_action', $colAction, PDO::PARAM_STR);
            $stmt->bindValue(':col_total', $colTotal, PDO::PARAM_INT);
            $stmt->bindValue(':col_sum', $colSum, PDO::PARAM_INT);
            $stmt->bindValue(':match_day_action', $matchDayAction, PDO::PARAM_STR);
            $stmt->bindValue(':match_day_total', $matchDayTotal, PDO::PARAM_INT);
            $stmt->bindValue(':sum', $sum, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }

    public function getAllMatchDayPlayerPoints(int $matchDay): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from match_day_player_points where match_day=? order by match_day_total desc;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>