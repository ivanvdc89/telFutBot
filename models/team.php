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

    public function getCountPlayerTeamsByPot($pot){
        $connection= parent::connect();
        parent::set_names();
        $sql="select count(*) as total, t.name as name, t.id as id from player_teams pt join teams t on pt.team_id = t.id where t.pot = ? group by pt.team_id;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$pot);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function getPlayersByTeam($teamId){
        $connection= parent::connect();
        parent::set_names();
        $sql="select p.name as name from player_teams pt join players p on pt.player_id = p.id where pt.team_id = ?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $teamId);
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

    public function changePlayerTeam($playerId, $oldTeamId, $newTeamId) {
        try {
            $connection = parent::connect();

            $sql = "UPDATE player_teams set team_id = :new_team_id where player_id = :player_id and team_id = :old_team_id";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':old_team_id', $oldTeamId, PDO::PARAM_INT);
            $stmt->bindValue(':new_team_id', $newTeamId, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB update error: " . $e->getMessage());
            return false;
        }
    }

    public function getTeamById($teamId)
    {
        $connection = parent::connect();
        parent::set_names();
        $sql = "select * from teams where id=?;";
        $sql = $connection->prepare($sql);
        $sql->bindValue(1, $teamId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>