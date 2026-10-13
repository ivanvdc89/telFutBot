<?php
class Player extends Connection {
    /**
     * Regla de posició: vots mínims i màxims que pot tenir un jugador.
     */
    public const MIN_VOTES = 1;
    public const MAX_VOTES = 4;

    /**
     * Punts de diferència amb el líder que donen un vot més.
     */
    private const POINTS_PER_EXTRA_VOTE = 10;

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
     * Desa el nom del jugador, el que surt als anuncis i a /data players.
     *
     * El nom arriba ja normalitzat i validat per SettingsAction (config/
     * settings.php): aquí només s'escriu. Es pot cridar sempre, també per
     * canviar un nom que ja hi era.
     */
    public function updatePlayerName($playerId, $name) {
        try {
            $connection = parent::connect();

            $sql  = "UPDATE players SET name = :name WHERE id = :player_id";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);

            return $stmt->execute();

        } catch (PDOException $e) {
            error_log("DB update error: " . $e->getMessage());
            return false;
        }
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
     * val NULL fins al primer càlcul, i NULL no vol dir 0 punts. Amb esta
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

    /**
     * Vots que li toquen al jugador segons la seva posició a la general.
     *
     * És la regla comuna de totes les accions que depenen de la classificació:
     * #dobleORes i #kosAmbEscuts (quants vots pot fer), #millorFinal (pes del
     * vot) i #segurQuePasse (punts que s'aposten). La norma diu 1 per al líder i
     * 1 més per cada 10 punts de diferència, amb un màxim de 4.
     *
     * Un jugador sense puntuació calculada (points NULL, o sigui abans de la
     * primera jornada) compta com el mínim: no se li inventa cap posició.
     */
    public function getAllowedVotes(int $playerId): int
    {
        $votes = $this->getAllowedVotesByPlayer();

        return $votes[$playerId] ?? self::MIN_VOTES;
    }

    /**
     * Els vots de tots els jugadors amb puntuació calculada, [player_id => vots].
     *
     * Es calcula d'una tacada perquè qui després mou la classificació
     * (calculateMatchDayPoints) no barregi la general d'abans i la de després
     * d'escriure-hi: tothom ha de veure la mateixa foto.
     */
    public function getAllowedVotesByPlayer(): array
    {
        $points       = $this->getPlayersPoints();
        $leaderPoints = count($points) === 0 ? 0 : max($points);

        $votes = [];
        foreach ($points as $playerId => $playerPoints) {
            $votes[$playerId] = self::allowedVotesForGap($leaderPoints - $playerPoints);
        }

        return $votes;
    }

    /**
     * Vots que corresponen a una distància amb el líder.
     */
    private static function allowedVotesForGap(int $gap): int
    {
        if ($gap < 0) {
            $gap = 0;
        }

        return min(self::MAX_VOTES, self::MIN_VOTES + intdiv($gap, self::POINTS_PER_EXTRA_VOTE));
    }
}
?>