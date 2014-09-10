<?php

namespace wazaari\MysqlHandler;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDO;
use PDOStatement;

/**
 * This class is a handler for Monolog, which can be used
 * to write records in a MySQL table
 *
 * Class MySQLHandler
 * @package wazaari\MysqlHandler
 */
class MySQLHandler extends AbstractProcessingHandler {

    /**
     * @var bool defines whether the MySQL connection is been initialized
     */
    private $initialized = false;

    /**
     * @var PDO pdo object of database connection
     */
    private $pdo;

    /**
     * @var PDOStatement statement to insert a new record
     */
    private $statement;

    /**
     * @var string the table to store the logs in
     */
    private $table = 'logs';

    /**
     * @var string[] additional fields to be stored in the database
     *
     * For each field $field, an additional context field with the name $field
     * is expected along the message, and further the database needs to have these fields
     * as the values are stored in the column name $field.
     */
    private $additionalFields = array();

    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo                  PDO Connector for the database
     * @param bool $table               Table in the database to store the logs in
     * @param array $additionalFields   Additional Context Parameters to store in database
     * @param bool|int $level           Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(PDO $pdo, $table, $additionalFields = array(), $level = Logger::DEBUG, $bubble = true) {
        $this->pdo = $pdo;
        $this->additionalFields = $additionalFields;
        $this->table = $table;
        parent::__construct($level, $bubble);
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize() {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `'.$this->table.'` '
            .'(channel VARCHAR(255), level INTEGER, message LONGTEXT, time INTEGER UNSIGNED)'
        );

        //Read out actual columns
        $q = $this->pdo->prepare("DESCRIBE `'.$this->table.'`;");
        $q->execute();
        $actualFields = $q->fetchAll(PDO::FETCH_COLUMN);

        //Calculate changed entries
        $removedColumns = array_diff($actualFields, $this->additionalFields);
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (!empty($removedColumns)) foreach ($removedColumns as $c) {
            $this->pdo->exec('ALTER TABLE `'.$this->table.'` DROP `'.$c.'`;');
        }

        //Add columns
        if (!empty($addedColumns)) foreach ($addedColumns as $c) {
            $this->pdo->exec('ALTER TABLE `'.$this->table.'` add `'.$c.'` VARCHAR(200) NULL DEFAULT NULL;');
        }

        //Prepare statement
        $columns = "";
        $fields = "";
        foreach ($this->additionalFields as $f) {
            $columns.= ", $f";
            $fields.= ", :$f";
        }

        $this->statement = $this->pdo->prepare(
            'INSERT INTO `'.$this->table.'` (channel, level, message, time'.$columns.') VALUES (:channel, :level, :message, :time'.$fields.')'
        );

        $this->initialized = true;
    }

    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  $record[]
     * @return void
     */
    protected function write(array $record) {
        if (!$this->initialized) {
            $this->initialize();
        }

        //'extra' contains the array

        $this->statement->execute(array_merge([
            'channel' => $record['channel'],
            'level' => $record['level'],
            'message' => $record['formatted'],
            'time' => $record['datetime']->format('U'),
        ], $record['extra']));
    }
}