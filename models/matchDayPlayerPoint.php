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
     * L'última fila calculada de cada jugador, indexada per player_id.
     *
     * És la foto més recent de cada jugador encara que s'haja saltat una
     * jornada: la classificació per competició no pot agafar només les files de
     * l'última jornada, perquè qui no hi ha jugat es quedaria sense punts.
     *
     * @return array<int, array> [player_id => fila de match_day_player_points]
     */
    public function getLatestTotalsByPlayer(): array
    {
        $connection = parent::connect();
        parent::set_names();

        $sql = "select p.*
                from match_day_player_points p
                inner join (
                    select player_id, max(match_day) as match_day
                    from match_day_player_points
                    group by player_id
                ) latest
                  on latest.player_id = p.player_id
                 and latest.match_day = p.match_day;";
        $stmt = $connection->prepare($sql);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(pdo::FETCH_ASSOC) as $row) {
            $rows[(int) $row['player_id']] = $row;
        }

        return $rows;
    }

    /**
     * L'acumulat d'un jugador que encara no ha jugat cap jornada.
     *
     * Porta els quatre totals a zero, perquè els scripts de càlcul els puguen
     * sumar sense comprovar si existeixen, i NO porta 'match_day': els qui
     * miren si el jugador ja havia jugat abans ho distingeixen amb isset(), i
     * eixa comprovació és la que decidix si s'actualitza la classificació
     * general (players.points).
     */
    public static function emptyTotals(): array
    {
        return [
            'chl_total' => 0,
            'eul_total' => 0,
            'col_total' => 0,
            'total'     => 0,
        ];
    }

    /**
     * L'última jornada calculada del jugador, amb els seus acumulats.
     *
     * A la jornada 1 (o a la primera jornada d'un jugador afegit més tard) no
     * hi ha cap fila prèvia: aleshores torna emptyTotals() en compte de null,
     * que és el que esperen els scripts que hi sumen els punts d'esta jornada.
     */
    public function getLastMatchDayByPlayer(int $playerId): array
    {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from match_day_player_points where player_id=? order by match_day desc limit 1;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $playerId);
        $sql->execute();
        $lastMatchDay = $sql->fetch(pdo::FETCH_ASSOC);

        return $lastMatchDay === false ? self::emptyTotals() : $lastMatchDay;
    }

    /**
     * Cert si la jornada ja té punts calculats.
     *
     * calculateMatchDayPoints.php ho mira abans de fer res: els punts són
     * acumulatius i passar una jornada dos voltes els inflaria. Abans es podia
     * fer sense que res ho aturara, perquè getLastMatchDayByPlayer torna la
     * fila de la mateixa jornada que s'està calculant.
     */
    public function matchDayHasPoints(int $matchDay): bool
    {
        $connection = parent::connect();
        $sql = "select count(*) as total from match_day_player_points where match_day=?;";
        $sql = $connection->prepare($sql);
        $sql->bindValue(1, $matchDay, PDO::PARAM_INT);
        $sql->execute();

        $row = $sql->fetch(pdo::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * Esborra els punts calculats d'una jornada, per tornar-los a calcular.
     *
     * Només ho fa servir calculateMatchDayPoints.php amb --recalculate. Els
     * acumulats no es toquen: el que esborra és justament el que es tornarà a
     * escriure.
     */
    public function deleteByMatchDay(int $matchDay): void
    {
        $connection = parent::connect();
        $sql = "delete from match_day_player_points where match_day=?;";
        $sql = $connection->prepare($sql);
        $sql->bindValue(1, $matchDay, PDO::PARAM_INT);
        $sql->execute();
    }
}
?>
