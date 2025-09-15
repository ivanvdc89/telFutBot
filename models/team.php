<?php
class Team extends Connection {
    public function getTeamsByPlayerId($playerId){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from player_teams pt join teams t on (pt.team_id = t.id) where pt.player_id=? order by t.pot ASC;";
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

    public function getTeamByName($name){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from teams where name=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$name);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function addPlayerTeam($playerId, $teamId) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO player_teams (player_id, team_id) VALUES (:player_id, :team_id)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }
}
?>