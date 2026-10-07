<?php

class MatchDayPlayerPoint extends Connection {
    public function addMatchDayPlayerPoint(
        int $playerId,
        int $matchDay,
        int $chlPoints,
        string $chlAction,
        int $chlSum,
        int $chlTotal,
        int $eulPoints,
        string $eulAction,
        int $eulSum,
        int $eulTotal,
        int $colPoints,
        string $colAction,
        int $colSum,
        int $colTotal,
        string $matchDayAction,
        int $matchDayTotal,
        int $total
    ) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO match_day_player_points (
                        player_id, match_day, chl_points, chl_action, chl_sum, chl_total, eul_points, eul_action, eul_sum, eul_total, col_points, col_action, col_sum, col_total, match_day_action, match_day_total, total
                    ) VALUES (
                        :player_id, :match_day, :chl_points, :chl_action, :chl_sum, :chl_total, :eul_points, :eul_action, :eul_sum, :eul_total, :col_points, :col_action, :col_sum, :col_total, :match_day_action, :match_day_total, :total
                    )";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchDay, PDO::PARAM_INT);
            $stmt->bindValue(':chl_points', $chlPoints, PDO::PARAM_INT);
            $stmt->bindValue(':chl_action', $chlAction, PDO::PARAM_STR);
            $stmt->bindValue(':chl_sum', $chlSum, PDO::PARAM_INT);
            $stmt->bindValue(':chl_total', $chlTotal, PDO::PARAM_INT);
            $stmt->bindValue(':eul_points', $eulPoints, PDO::PARAM_INT);
            $stmt->bindValue(':eul_action', $eulAction, PDO::PARAM_STR);
            $stmt->bindValue(':eul_sum', $eulSum, PDO::PARAM_INT);
            $stmt->bindValue(':eul_total', $eulTotal, PDO::PARAM_INT);
            $stmt->bindValue(':col_points', $colPoints, PDO::PARAM_INT);
            $stmt->bindValue(':col_action', $colAction, PDO::PARAM_STR);
            $stmt->bindValue(':col_sum', $colSum, PDO::PARAM_INT);
            $stmt->bindValue(':col_total', $colTotal, PDO::PARAM_INT);
            $stmt->bindValue(':match_day_action', $matchDayAction, PDO::PARAM_STR);
            $stmt->bindValue(':match_day_total', $matchDayTotal, PDO::PARAM_INT);
            $stmt->bindValue(':total', $total, PDO::PARAM_INT);
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
        $sql="select * from match_day_player_points where match_day=? order by total desc;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function getMatchDayRanking(int $matchDay): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from match_day_player_points where match_day=? order by match_day_total desc;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    /**
     * Total acumulat més recent de cada jugador, com a [player_id => total].
     *
     * Serveix per saber qui va líder i quants punts té un jugador, que és el que
     * necessita la regla "els canvis són gratis si estàs a més de 29 punts del
     * líder". Es pren l'última jornada de CADA jugador per separat: el total és
     * acumulat i pot baixar (les accions i el cost dels canvis resten), així que
     * no es pot fer servir el max(total) de tota la taula.
     */
    public function getLastTotalsByPlayer(): array
    {
        $connection = parent::connect();
        parent::set_names();
        $sql = "select m.player_id, m.total
                from match_day_player_points m
                join (
                    select player_id, max(match_day) as last_match_day
                    from match_day_player_points
                    group by player_id
                ) latest
                  on latest.player_id = m.player_id
                 and latest.last_match_day = m.match_day;";
        $stmt = $connection->prepare($sql);
        $stmt->execute();

        $totals = [];
        foreach ($stmt->fetchAll(pdo::FETCH_ASSOC) as $row) {
            $totals[(int) $row['player_id']] = (int) $row['total'];
        }

        return $totals;
    }

    public function getLastMatchDayByPlayer(int $playerId): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from match_day_player_points where player_id=? order by match_day desc limit 1;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $playerId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC)[0];
    }
}
?>