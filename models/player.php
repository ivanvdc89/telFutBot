<?php
class Player extends Connection {
    public function getPlayerByChatId($chatId){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from players where chat_id=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$chatId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function createPlayer($chatId) {
        try {
            $connection = parent::connect();

            $sql = "INSERT INTO players (chat_id) VALUES (:chat_id)";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
            $stmt->execute();

            return $connection->lastInsertId();

        } catch (PDOException $e) {
            error_log("DB insert error: " . $e->getMessage());
            return false;
        }
    }
}
?>