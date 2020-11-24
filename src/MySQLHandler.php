<?php

declare(strict_types=1);

namespace MySQLHandler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use PDO;

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
     * @var MySQLRecord
     */
    private $mySQLRecord;

    /**
     * @var MySQLExecutor
     */
    private $executor;

    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo PDO Connector for the database
     * @param string $table Table in the database to store the logs in
     * @param array $additionalFields Additional Context Parameters to store in database
     * @param bool $initialize Defines whether attempts to alter database should be skipped
     * @param bool|int $level Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(
        PDO $pdo,
        string $table,
        array $additionalFields = [],
        bool $initialize = false,
        int $level = Logger::DEBUG,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->initialized = $initialize;
        $this->mySQLRecord = new MySQLRecord($table, $additionalFields);
        $this->executor = new MySQLExecutor($pdo, $this->mySQLRecord);
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize(): void
    {
        $this->executor->createIfNotExistsLogTable();
        $this->executor->syncLogTable();
        $this->initialized = true;
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

        /*
         * merge $record['context'] and $record['extra'] as additional info of Processors
         * getting added to $record['extra']
         * @see https://github.com/Seldaek/monolog/blob/master/doc/02-handlers-formatters-processors.md
         */
        if (isset($record['extra'])) {
            $record['context'] = array_merge($record['context'], $record['extra']);
        }

        $content = $this->mySQLRecord->filterContent(array_merge([
            'channel' => $record['channel'],
            'level' => $record['level'],
            'message' => $record['message'],
            'time' => $record['datetime']->format('U'),
        ], $record['context']));

        $this->executor->writeLog($content);
    }
}
