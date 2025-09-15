<?php
class Connection{
    protected $dbh;

    protected function connect() {
        try {
            $dsn = "mysql:host=localhost;dbname=fut_ko;charset=utf8mb4";
            $user = "myappuser";
            $pass = "123ggg";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $this->dbh = new PDO($dsn, $user, $pass, $options);
            return $this->dbh;

        } catch (PDOException $e) {
            error_log("DB connection error: " . $e->getMessage());
            die("Database connection failed.");
        }
    }

    public function set_names(){
        return $this->dbh->query("SET NAMES 'utf8'");
    }
}
?>