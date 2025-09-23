<?php
class Group extends Connection {
    public function getGroup(int $groupId = 1){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from groups where id=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$groupId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }
}
?>