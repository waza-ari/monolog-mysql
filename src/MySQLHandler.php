<?php

declare(strict_types=1);

namespace MySQLHandler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
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
    private bool $initialized;

    /**
     * pdo object of database connection
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * statement to insert a new record
     *
     * @var PDOStatement
     */
    private PDOStatement $statement;

    /**
     * @var MySQLRecord
     */
    private MySQLRecord $mySQLRecord;

    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo PDO Connector for the database
     * @param string $table Table in the database to store the logs in
     * @param array $additionalFields Additional Context Parameters to store in database
     * @param bool $initialize Defines whether attempts to alter database should be skipped
     * @param int|Level|string $level Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(
        PDO $pdo,
        string $table,
        array $additionalFields = [],
        bool $initialize = false,
        int|Level|string $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->pdo = $pdo;
        $this->initialized = $initialize;
        $this->mySQLRecord = new MySQLRecord($table, $additionalFields);
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$this->mySQLRecord->getTable()}` (
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
        $rs = $this->pdo->query("SELECT * FROM `{$this->mySQLRecord->getTable()}` LIMIT 0;");
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff(
            $actualFields,
            $this->mySQLRecord->getAdditionalColumns(),
            $this->mySQLRecord->getDefaultColumns()
        );
        $addedColumns = array_diff($this->mySQLRecord->getAdditionalColumns(), $actualFields);

        //Remove columns
        if (! empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec("ALTER TABLE `{$this->mySQLRecord->getTable()}` DROP `$c`;");
            }
        }

        //Add columns
        if (! empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec(
                    "ALTER TABLE `{$this->mySQLRecord->getTable()}` ADD `{$c}` TEXT NULL DEFAULT NULL;"
                );
            }
        }

        $this->initialized = true;
    }

    /**
     * Prepare the sql statement depending on the fields that should be written to the database
     * @param array $content
     */
    private function prepareStatement(array $content): void
    {
        // Remove 'id' column if it was passed
        // since it will be auto-incremented and should
        // not be set in the query
        $keys = array_filter(array_keys($content), function($key) {
            return $key != 'id';
        });

        $columns = '';
        $fields = '';

        foreach ($keys as $key => $f) {
            if (empty($columns)) {
                $columns .= $f;
                $fields .= ":{$f}";
                continue;
            }

            $columns .= ", {$f}";
            $fields .= ", :{$f}";
        }

        $this->statement = $this->pdo->prepare(
            "INSERT INTO `{$this->mySQLRecord->getTable()}` ({$columns}) VALUES ({$fields});"
        );
    }


    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        /*
         * merge $record['context'] and $record['extra'] as additional info of Processors
         * getting added to $record['extra']
         * @see https://github.com/Seldaek/monolog/blob/master/doc/02-handlers-formatters-processors.md
         */
        if (isset($record->extra)) {
            $record->with(context: array_merge($record->context, $record->extra));
        }

        $content = $this->mySQLRecord->filterContent(array_merge([
            'channel' => $record->channel,
            'level' => $record->level->value,
            'message' => $record->message,
            'time' => $record->datetime->format('U'),
        ], $record->context));

        $this->prepareStatement($content);

        $this->statement->execute($content);
    }
}
