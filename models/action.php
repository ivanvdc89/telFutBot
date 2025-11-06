<?php
class Action extends Connection {
    public function getActionsByPlayerId(int $playerId, int $matchDay, string $type){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from actions where player_id=? and match_day=? and type=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $playerId);
        $sql->bindValue(2, $matchDay);
        $sql->bindValue(3, $type);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function getActions(int $matchDay, string $type){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from actions where match_day=? and type=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->bindValue(2, $type);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function getActionsByMatchDay(int $matchDay){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from actions where match_day=? order by type;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $matchDay);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function addAction(int $playerId, int $matchDay, string $type, string $data) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO actions (player_id, match_day, type, data) VALUES (:player_id, :match_day, :type, :data)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':match_day', $matchDay, PDO::PARAM_INT);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':data', $data, PDO::PARAM_STR);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }

    public function updateAction(int $id, string $data) {
        try {
            $connection = parent::connect();

            $sql = "UPDATE actions set data=:data where id=:id";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':data', $data, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }
}
?>