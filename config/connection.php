<?php
require_once(__DIR__ . '/secrets.php');

class Connection{
    protected $dbh;

    /**
     * Connexió compartida per tots els models.
     *
     * Abans cada crida a connect() obria una PDO nova, i això tenia dos
     * problemes: una connexió per consulta, i que una transacció no podia
     * abastar més d'un model, perquè cada un tindria la seva connexió. Ara tots
     * els models comparteixen la mateixa, i per tant beginTransaction() des
     * d'un model cobreix les consultes de tots.
     */
    private static $shared = null;

    protected function connect() {
        if (self::$shared === null) {
            try {
                $dsn = "mysql:host=" . secret('db_host')
                    . ";dbname=" . secret('db_name')
                    . ";charset=utf8mb4";
                $user = secret('db_user');
                $pass = secret('db_pass', true);

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];

                self::$shared = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log("DB connection error: " . $e->getMessage());
                die("Database connection failed.");
            }
        }

        $this->dbh = self::$shared;

        return $this->dbh;
    }

    public function set_names(){
        return $this->dbh->query("SET NAMES 'utf8'");
    }

    public function beginTransaction(): void
    {
        $this->connect()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connect()->commit();
    }

    public function rollBack(): void
    {
        $this->connect()->rollBack();
    }
}
?>