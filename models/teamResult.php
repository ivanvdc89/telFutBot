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

    public function getBestByCompetitionAndMatchDay(int $matchDay, array $players, string $competition): int
    {
        if(count($players)===0){
            return 0;
        }
        return 10;

        $connection= parent::connect();
        parent::set_names();
        $sql="select sum() from team_results where match_day=? and competition=? and player_id into ?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->bindValue(2, $competition);
        $sql->bindValue(3, $competition);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);

    }
}
?>