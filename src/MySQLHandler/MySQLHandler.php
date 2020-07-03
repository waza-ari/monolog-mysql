<?php

declare(strict_types=1);

namespace MySQLHandler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
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
     * defines whether the MySQL connection is been initialized
     *
     * @var bool
     */
    private $initialized;

    /**
     * pdo object of database connection
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * statement to insert a new record
     *
     * @var PDOStatement
     */
    private $statement;

    /**
     * the table to store the logs in
     *
     * @var string
     */
    private $table;

    /**
     * default fields that are stored in db
     *
     * @var array|string[]
     */
    private $defaultFields;

    /**
     * additional fields to be stored in the database
     *
     * For each field $field, an additional context field with the name $field
     * is expected along the message, and further the database needs to have these fields
     * as the values are stored in the column name $field.
     *
     * @var array|string[]
     */
    private $additionalFields;

    /**
     * @var array
     */
    private $fields;

    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo PDO Connector for the database
     * @param string $table Table in the database to store the logs in
     * @param array $additionalFields Additional Context Parameters to store in database
     * @param bool $skipDatabaseModifications Defines whether attempts to alter database should be skipped
     * @param bool|int $level Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(
        PDO $pdo,
        string $table,
        array $additionalFields = [],
        bool $skipDatabaseModifications = false,
        int $level = Logger::DEBUG,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->initialized = false;
        $this->defaultFields = [
            'id',
            'channel',
            'level',
            'message',
            'time',
        ];
        $this->pdo = $pdo;
        $this->table = $table;
        $this->additionalFields = $additionalFields;

        if ($skipDatabaseModifications) {
            $this->mergeDefaultAndAdditionalFields();
            $this->initialized = true;
        }
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, 
                channel VARCHAR(255), 
                level INTEGER, 
                message LONGTEXT, 
                time INTEGER UNSIGNED, 
                INDEX(channel) USING HASH, 
                INDEX(level) USING HASH, 
                INDEX(time) USING BTREE
            );
        ");

        //Read out actual columns
        $actualFields = [];
        $rs = $this->pdo->query("SELECT * FROM `{$this->table}` LIMIT 0;");
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff(
            $actualFields,
            $this->additionalFields,
            $this->defaultFields
        );
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (! empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec("ALTER TABLE `{$this->table}` DROP `$c`;");
            }
        }

        //Add columns
        if (! empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec("ALTER TABLE `{$this->table}` ADD `{$c}` TEXT NULL DEFAULT NULL;");
            }
        }

        $this->mergeDefaultAndAdditionalFields();

        $this->initialized = true;
    }

    /**
     * Prepare the sql statment depending on the fields that should be written to the database
     */
    private function prepareStatement(): void
    {
        //Prepare statement
        $columns = "";
        $fields = "";
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
            "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$fields});"
        );
    }


    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  array $record
     * @return void
     */
    protected function write(array $record): void
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        /**
         * reset $fields with default values
         */
        $this->fields = $this->defaultFields;

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
        foreach ($contentArray as $key => $context) {
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

        $this->prepareStatement();

        //Remove unused keys
        foreach ($this->additionalFields as $key => $context) {
            if (!isset($contentArray[$key])) {
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

    /**
     * Merges default and additional fields into one array
     */
    private function mergeDefaultAndAdditionalFields(): void
    {
        $this->defaultFields = array_merge($this->defaultFields, $this->additionalFields);
    }
}
