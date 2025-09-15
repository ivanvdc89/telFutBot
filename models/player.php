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
}
?>