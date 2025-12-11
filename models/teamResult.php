<?php
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

    public function addTeamResult(
        int $teamId,
        int $teamPts,
        int $matchday,
        string $competition
    ) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO team_results (team_id, points, match_day, competition) VALUES (:team_id, :points, :match_day, :competition)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
            $stmt->bindValue(':points', $teamPts, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchday, PDO::PARAM_INT);
            $stmt->bindValue(':competition', $competition, PDO::PARAM_STR);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }
}
?>