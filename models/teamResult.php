<?php
class TeamResult extends Connection {
    public function getResultByTeamIdAndMatchday(int $teamId) {
        //TODO add matchday filter
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from team_results where team_id=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$teamId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>