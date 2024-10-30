<?php
// /home/bot.dailymu.com/private/src/DatabaseHandler.php

class DatabaseHandler {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . Config::DB_HOST . 
                ";dbname=" . Config::DB_NAME . 
                ";charset=utf8mb4",
                Config::DB_USER,
                Config::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
            // ตั้งค่า charset และ collation
            $this->pdo->exec("SET CHARACTER SET utf8mb4");
            $this->pdo->exec("SET NAMES utf8mb4");
            
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage());
            throw new Exception("Database query failed");
        }
    }

    public function transaction(callable $callback) {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Transaction Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function escape_string($string)
    {
        $escape_string = $this->pdo->quote($string);
        return $escape_string;
    }


}