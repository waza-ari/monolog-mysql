<?php

namespace MySQLHandler;

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
class MySQLHandler extends AbstractProcessingHandler
{

    /**
     * @var bool defines whether the MySQL connection is been initialized
     */
    private $initialized = false;

    /**
     * @var PDO pdo object of database connection
     */
    protected $pdo;

    /**
     * @var PDOStatement statement to insert a new record
     */
    private $statement;

    /**
     * @var string the table to store the logs in
     */
    private $table = 'logs';

    /**
     * @var array default fields that are stored in db
     */
    private $defaultfields = array('id', 'channel', 'level', 'message', 'time');

    /**
     * @var string[] additional fields to be stored in the database
     *
     * For each field $field, an additional context field with the name $field
     * is expected along the message, and further the database needs to have these fields
     * as the values are stored in the column name $field.
     */
    private $additionalFields = array();

    /**
     * @var array
     */
    private $fields           = array();


    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo                  PDO Connector for the database
     * @param bool $table               Table in the database to store the logs in
     * @param array $additionalFields   Additional Context Parameters to store in database
     * @param bool|int $level           Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(
        PDO $pdo = null,
        $table,
        $additionalFields = array(),
        $level = Logger::DEBUG,
        $bubble = true
    ) {
        if (!is_null($pdo)) {
            $this->pdo = $pdo;
        }
        $this->table = $table;
        $this->additionalFields = $additionalFields;
        parent::__construct($level, $bubble);
    }

    /**
     * Checks if logs table exists
     * @return bool
     */
    private function logsTableExists(): bool
    {
        // Try a select statement against the table
        try {
            $result = $this->pdo->query('
                SELECT 1
                FROM ' . $this->table . '
                LIMIT 1
            ');
        } catch (\PDOException $e) {
            // We got an exception == table not found
            return false;
        }

        // Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
        return $result !== false;
    }

    /**
     * Create log's table
     * We don't want to call CREATE TABLE IF EXISTS every time in __CLASS__::initialize(), because it automatically issues an implicit COMMIT when in transaction, @see https://www.php.net/manual/en/pdo.begintransaction.php
     * @todo A way to prevent autoCOMMIT transaction if table doesn't exist - first call of this method (when the table doesn't exist) still breaks transaction
     */
    private function createLogsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `'.$this->table.'` '
            .'(id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, channel VARCHAR(255), level INTEGER, message LONGTEXT, time INTEGER UNSIGNED, INDEX(channel) USING HASH, INDEX(level) USING HASH, INDEX(time) USING BTREE)'
        );
    }


    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize()
    {
        if ($this->logsTableExists() === false) {
            $this->createLogsTable();
        }

        //Read out actual columns
        $actualFields = array();
        $rs = $this->pdo->query('SELECT * FROM `'.$this->table.'` LIMIT 0');
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff(
            $actualFields,
            $this->additionalFields,
            $this->defaultfields
        );
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (!empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `'.$this->table.'` DROP `'.$c.'`;');
            }
        }

        //Add columns
        if (!empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `'.$this->table.'` add `'.$c.'` TEXT NULL DEFAULT NULL;');
            }
        }

        // merge default and additional field to one array
        $this->defaultfields = array_merge($this->defaultfields, $this->additionalFields);

        $this->initialized = true;
    }

    /**
     * Prepare the sql statment depending on the fields that should be written to the database
     */
    private function prepareStatement()
    {
        //Prepare statement
        $columns = "";
        $fields  = "";
        foreach ($this->fields as $key => $f) {
            if ($f == 'id') {
                continue;
            }
            if ($key == 1) {
                $columns .= "$f";
                $fields .= ":$f";
                continue;
            }

            $columns .= ", $f";
            $fields .= ", :$f";
        }

        $this->statement = $this->pdo->prepare(
            'INSERT INTO `' . $this->table . '` (' . $columns . ') VALUES (' . $fields . ')'
        );
    }


    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  $record[]
     * @return void
     */
    protected function write(array $record): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        /**
         * reset $fields with default values
         */
        $this->fields = $this->defaultfields;

        /*
         * merge $record['context'] and $record['extra'] as additional info of Processors
         * getting added to $record['extra']
         * @see https://github.com/Seldaek/monolog/blob/master/doc/02-handlers-formatters-processors.md
         */
        if (isset($record['extra'])) {
            $record['context'] = array_merge($record['context'], $record['extra']);
        }

        //'context' contains the array
        $contentArray = array_merge(array(
                                        'channel' => $record['channel'],
                                        'level' => $record['level'],
                                        'message' => $record['message'],
                                        'time' => $record['datetime']->format('U')
                                    ), $record['context']);

        // unset array keys that are passed put not defined to be stored, to prevent sql errors
        foreach($contentArray as $key => $context) {
            if (! in_array($key, $this->fields)) {
                unset($contentArray[$key]);
                unset($this->fields[array_search($key, $this->fields)]);
                continue;
            }

            if ($context === null) {
                unset($contentArray[$key]);
                unset($this->fields[array_search($key, $this->fields)]);
            }
        }

        // remove default fields which are not provided in $contentArray; we can skip 'id' too, because it is PRIMARY AI key
        $this->fields = array_filter($this->fields, static function($val) use ($contentArray) {
            return array_key_exists($val, $contentArray);
        });

        $this->prepareStatement();

        //Remove unused keys
        foreach($this->additionalFields as $key => $context) {
            if(! isset($contentArray[$key])) {
                unset($this->additionalFields[$key]);
            }
        }

        //Fill content array with "null" values if not provided
        $contentArray = $contentArray + array_combine(
                $this->additionalFields,
                array_fill(0, count($this->additionalFields), null)
            );

        $this->statement->execute($contentArray);
    }
}
