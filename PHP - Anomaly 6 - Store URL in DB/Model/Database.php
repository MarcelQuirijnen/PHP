<?php
require_once "../inc/config.php";

class Database
{
    public $connection = null;

    public function getConnection(){
        try {
            $this->connection = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_DATABASE_NAME, DB_USERNAME, DB_PASSWORD);
            $this->connection->exec("set names utf8");
        } catch( PDOException $exception ){
            echo "Database could not be connected: " . $exception->getMessage();
        }
        return $this->connection;
    }

}