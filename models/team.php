<?php
class Team extends Connection {
    public function getTeamsByPlayerId($playerId){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from player_teams pt join teams t on (pt.team_id = t.id) where pt.player_id=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$playerId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function getTeamsByPot($pot){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from teams where pot=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$pot);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>