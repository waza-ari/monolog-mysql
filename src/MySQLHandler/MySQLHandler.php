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
class MySQLHandler extends AbstractProcessingHandler {

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
    private $table_name = 'logs';

    /**
     * @var string The default time field type.
     */
    private $table_time_type = 'TEXT';

    /**
     * @var array Allowed time types
     */
    private $allowed_time_types = array('TEXT', 'DATETIME');

    private $time_type_data = array('TEXT'       => array('time_format' => 'U', 'field_type' =>'INTEGER UNSIGNED'),
                                    'DATETIME'  => array('time_format' => 'Y-m-d H:i:s', 'field_type' =>'DATETIME NULL DEFAULT NULL'));

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
     * @param array $table_data         An array containing key => value data expects an array with any
     *                                  of the following key => values.
     *
     *                                  array('table_name'  => 'Your_table_name',   /* If not specified will use $this->table_name
     *                                          'time_type' => 'TEXT|DATETIME');    /* If not specified will use TEXT
     *
     * @param array $additionalFields   Additional Context Parameters to store in database
     * @param bool|int $level           Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(PDO $pdo = null, $table_data, $additionalFields = array(), $level = Logger::DEBUG, $bubble = true) {
    	if(!is_null($pdo)) {
        	$this->pdo = $pdo;
        }
        $this->table_name = !empty( $table_data['table_name'] ) ? $table_data['table_name'] : $this->table_name;
        $table_time_type = !empty( $table_data['time_type'] ) ? $table_data['time_type'] : $this->table_time_type;

        if ( !in_array( $table_time_type, $this->allowed_time_types ) ){
            $table_time_type = 'TEXT';
        }
        $this->table_time_format =  $this->time_type_data[ $table_time_type ]['time_format'];
        $this->table_time_field = $this->time_type_data[ $table_time_type ]['field_type'];
        $this->additionalFields = $additionalFields;
        parent::__construct($level, $bubble);
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize() {

        $query = "CREATE TABLE IF NOT EXISTS `{$this->table_name}` "
                    . '(channel VARCHAR(255), '
                    . 'level INTEGER, '
                    . 'message LONGTEXT, '
                    . "time {$this->table_time_field})";

        $this->pdo->exec( $query );

        //Read out actual columns
        $actualFields = array();
        $rs = $this->pdo->query('SELECT * FROM `'.$this->table_name.'` LIMIT 0');
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff($actualFields, $this->additionalFields, array('channel', 'level', 'message', 'time'));
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (!empty($removedColumns)) foreach ($removedColumns as $c) {
            $this->pdo->exec('ALTER TABLE `'.$this->table_name.'` DROP `'.$c.'`;');
        }

        //Add columns
        if (!empty($addedColumns)) foreach ($addedColumns as $c) {
            $this->pdo->exec('ALTER TABLE `'.$this->table_name.'` add `'.$c.'` TEXT NULL DEFAULT NULL;');
        }

        //Prepare statement
        $columns = "";
        $fields = "";
        foreach ($this->additionalFields as $f) {
            $columns.= ", $f";
            $fields.= ", :$f";
        }

        $this->statement = $this->pdo->prepare(
            'INSERT INTO `'.$this->table_name.'` (channel, level, message, time'.$columns.') VALUES (:channel, :level, :message, :time'.$fields.')'
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

        //'context' contains the array
        $contentArray = array_merge(array(
            'channel' => $record['channel'],
            'level' => $record['level'],
            'message' => $record['message'],
            'time' => $record['datetime']->format( $this->table_time_format )
        ), $record['context']);

        $this->statement->execute($contentArray);
    }
}