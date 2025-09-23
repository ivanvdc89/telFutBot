<?php
class TeamPoint extends Connection {
    public function getPointByTeamIdAndMatchday(int $teamId) {
        //TODO add matchday filter
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from team_points where team_id=:?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$teamId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>