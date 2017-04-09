<?php

use MySQLHandler\MySQLHandler;
use Monolog\Logger;

class CreateTableTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var PDO
     */
    private $pdo;
    private $logger;

    public function setUp()
    {
        $this->pdo = new PDO($GLOBALS['db_dsn'], $GLOBALS['db_username'], $GLOBALS['db_password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $mySQLHandler = new MySQLHandler($this->pdo, "logs");
        $this->logger = new Monolog\Logger();
        $this->logger->pushHandler($mySQLHandler);

    }
    public function tearDown()
    {
        $this->pdo->query("DROP TABLE logs;");
    }
    public function testHelloWorld()
    {
        //
    }
}