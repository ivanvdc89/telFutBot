<?php
class Substitution extends Connection {
    public function getPendingSubstitutionsByPlayerId($playerId){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from substitutions where player_id=? and pending = 1;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $playerId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function addSubstitution($playerId, $oldTeamId, $newTeamId, $competition) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO substitutions (player_id, old_team_id, new_team_id, competition) VALUES (:player_id, :old_team_id, :new_team_id, :competition)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':old_team_id', $oldTeamId, PDO::PARAM_INT);
            $stmt->bindValue(':new_team_id', $newTeamId, PDO::PARAM_INT);
            $stmt->bindValue(':competition', $competition, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }

    public function removePendingSubstitution($id)
    {
        $connection = parent::connect();
        parent::set_names();
        $sql = "DELETE FROM substitutions WHERE id = ? AND pending = 1";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
?>