<?php
class TeamPoint extends Connection {
    public function getPointByTeamIdAndMatchday(int $teamId, int $matchDay) {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from team_points where team_id=:team_id and match_day=:match_day;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(':team_id', $teamId, PDO::PARAM_INT);
        $sql->bindValue(':match_day', $matchDay, PDO::PARAM_INT);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>