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

    public function getPlayerByName($name){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from players where name=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1,$name);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    public function getAllPlayers(){
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from players;";
        $sql=$connection->prepare($sql);
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

    public function getPlayerById($playerId) {
        $connection= parent::connect();
        parent::set_names();
        $sql="select * from players where id=?;";
        $sql=$connection->prepare($sql);
        $sql->bindValue(1, $playerId);
        $sql->execute();
        return $sql->fetchAll(pdo::FETCH_ASSOC);
    }

    /**
     * Desa la puntuació acumulada del jugador després d'una jornada.
     *
     * És la classificació general en una sola xifra: el total que acumula
     * match_day_player_points, i el que fan servir les validacions que depenen
     * de la posició, com ara si el jugador està a més de 29 punts del líder i
     * els canvis li surten gratis.
     */
    public function updatePlayerPoints($playerId, $points) {
        try {
            $connection = parent::connect();

            $sql  = "UPDATE players SET points = :points WHERE id = :player_id";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':points', $points, PDO::PARAM_INT);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);

            return $stmt->execute();

        } catch (PDOException $e) {
            error_log("DB update error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Puntuació de cada jugador, com a [player_id => points].
     *
     * Només hi surten els jugadors que ja tenen una jornada calculada: points
     * val NULL fins al primer càlcul, i NULL no vol dir 0 punts. Amb aquesta
     * llista es pot saber qui va líder (el max) i quants punts té un jugador.
     */
    public function getPlayersPoints(): array
    {
        $connection = parent::connect();
        parent::set_names();
        $sql = "select id, points from players where points is not null;";
        $stmt = $connection->prepare($sql);
        $stmt->execute();

        $points = [];
        foreach ($stmt->fetchAll(pdo::FETCH_ASSOC) as $row) {
            $points[(int) $row['id']] = (int) $row['points'];
        }

        return $points;
    }
}
?>