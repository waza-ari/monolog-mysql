<?php

namespace MySQLHandler;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDO;
use PDOStatement;
use DateTime;

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
    private $defaultFields = array('id', 'channel', 'level', 'message', 'time');

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
     * @var string format the time should be stored in, defaults to seconds since epoch
     */
    private $dateFormat;

    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo                  PDO Connector for the database
     * @param bool $table               Table in the database to store the logs in
     * @param array $additionalFields   Additional Context Parameters to store in database
     * @param bool|int $level           Debug level which this handler should store
     * @param bool $bubble
     * @param string $dateFormat        Format the time should be stored in
     */
    public function __construct(
        PDO $pdo = null,
        $table,
        $additionalFields = array(),
        $level = Logger::DEBUG,
        $bubble = true,
        $dateFormat = 'U'
    ) {
       if (!is_null($pdo)) {
            $this->pdo = $pdo;
        }
        $this->table = $table;
        $this->additionalFields = $additionalFields;
        $this->dateFormat = $dateFormat;
        parent::__construct($level, $bubble);
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize()
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `'.$this->table.'` '
            .'(id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY, channel VARCHAR(255), level INTEGER, message LONGTEXT, time ' . $this->getTimeColumnType() . ', INDEX(channel) USING HASH, INDEX(level) USING HASH, INDEX(time) USING BTREE)'
        );

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
            $this->defaultFields
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

        // If the dateFormat supplied doesn't match the existing format then change the
        // time format to whatever was passed
        $existingTimeFormat = $this->getExistingTimeFormat();
        if ($existingTimeFormat !== false) {
            if ($this->dateFormat != $existingTimeFormat) {
                $this->updateTimeFormat($existingTimeFormat, $this->dateFormat);
            }
        }

        // merge default and additional field to one array
        $this->defaultFields = array_merge($this->defaultFields, $this->additionalFields);

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
    protected function write(array $record)
    {
        if (!$this->initialized) {
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
                                        'time' => $record['datetime']->format($this->dateFormat)
                                    ), $record['context']);

        // unset array keys that are passed put not defined to be stored, to prevent sql errors
        foreach($contentArray as $key => $context) {
            if (! in_array($key, $this->fields)) {
                unset($contentArray[$key]);
                unset($this->fields[array_search($key, $this->fields)]);
                continue;
            }
        }

        $this->prepareStatement();

        //Fill content array with "null" values if not provided
        $contentArray = $contentArray + array_combine(
            $this->additionalFields,
            array_fill(0, count($this->additionalFields), null)
        );

        $this->statement->execute($contentArray);
    }

    /**
     * Returns the appropriate MySQL data type to use based on the dateFormat supplied
     *
     * @return string
     */
    private function getTimeColumnType()
    {
        $format = $this->dateFormat;

        if ($format == "U") {
            return "INTEGER";
        } else if ($format == "Y-m-d") {
            return "DATE";
        } else if ($format == "Y-m-d H:i:s") {
            return "DATETIME";
        } else if ($format == "YmdHis") {
            return "TIMESTAMP";
        } else if ($format == "H:i:s") {
            return "TIME";
        } else if ($format == "Y") {
            return "YEAR";
        }

        return "VARCHAR(255)";
    }

    /**
     * Get the MySQL data type for the time column
     *
     * @return string|boolean
     */
    private function getExistingTimeFormat()
    {
        $existingTimeFormat = '';

        // Get the existing data type
        $stmt = $this->pdo->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '" . $this->table . "' AND COLUMN_NAME = 'time'");
        $rs = $stmt->fetch();
        $existingColumnType = $rs['DATA_TYPE'];

        // We can determine the format from the data type
        if ($existingColumnType == 'int') {
            $existingTimeFormat = 'U';
        } else if ($existingColumnType == 'date') {
            $existingTimeFormat = 'Y-m-d';
        } else if ($existingColumnType == 'datetime') {
            $existingTimeFormat = 'Y-m-d H:i:s';
        } else if ($existingColumnType== 'timestamp') {
            $existingTimeFormat = 'YmdHis';
        } else if ($existingColumnType == 'time') {
            $existingTimeFormat = 'H:i:s';
        } else if ($existingColumnType == 'year') {
            $existingTimeFormat = 'Y';
        } else {
            // Not sure what to do about custom formats...
            return false;
        }

        return $existingTimeFormat;
    }

    /**
     * Updates the table to use a predefined format
     *
     * @param  string $oldFormat The existing format
     * @param  string $newFormat The format to update to
     * @return null
     */
    private function updateTimeFormat($oldFormat, $newFormat)
    {
        // Get the existing times
        $stmt = $this->pdo->query("SELECT id, time FROM {$this->table}");
        $existingRows = $stmt->fetchAll();

        // Convert the times to the new format
        for ($i = 0; $i < count($existingRows); $i++) {
            $originalTime = DateTime::createFromFormat($oldFormat, $existingRows[$i]['time']);
            $existingRows[$i]['time'] = $originalTime->format($newFormat);
        }

        // Change the column type
        $this->pdo->exec("UPDATE {$this->table} SET time = NULL");
        $this->pdo->exec("ALTER TABLE {$this->table} CHANGE time time " . $this->getTimeColumnType());

        // Re-apply the times in the new format
        for ($i = 0; $i < count($existingRows); $i++) {
            $this->pdo->exec("UPDATE {$this->table} SET time = '{$existingRows[$i]['time']}' WHERE id = {$existingRows[$i]['id']}");
        }
    }
}
