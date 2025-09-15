<?php
class Connection{
    protected $dbh;

    protected function connect(){
        try{
            return $this->dbh = new PDO("mysql:host=localhost;dbname=fut_ko","myappuser","123ggg");
        }catch(Exception $e){
            print_r("Error BD: ". $e->getMessage());
            error_log("Error BD: " . $e->getMessage());
            die();
        }
    }

    public function set_names(){
        return $this->dbh->query("SET NAMES 'utf8'");
    }
}
?>