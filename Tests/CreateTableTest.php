<?php

use MySQLHandler\MySQLHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\DbUnit\TestCaseTrait;
use PHPUnit\DbUnit\DataSet\DefaultDataSet;

class CreateTableTest extends TestCase
{

    use TestCaseTrait;

    /**
     * @var PDO The PDO connection object
     */
    private $pdo = null;

    /**
     * @var string Name of the table used for testing
     */
    private $tableName = 'logging';

    /**
     * @var Logger
     */
    private $logger = null;

    /**
     * @return \PHPUnit\DbUnit\Database\DefaultConnection
     */
    public function getConnection()
    {
        $this->pdo = new PDO($GLOBALS['db_dsn'], $GLOBALS['db_username'], $GLOBALS['db_password']);
        return $this->createDefaultDBConnection($this->pdo);
    }

    /**
     * @return DefaultDataSet
     */
    public function getDataSet()
    {
        return new DefaultDataSet();
        //$this->createXMLDataSet('Tests/logging_table.xml');
    }

    /**
     * Sets up a Monolog MySQL logger
     *
     * @param array $additionalFields
     * @param int $level
     */
    private function setupLogger($additionalFields = array(), $level = \Monolog\Logger::DEBUG, $timeFormat = 'U') {
        $mySQLHandler = new MySQLHandler($this->pdo, $this->tableName, $additionalFields, $level, true, $timeFormat);
        $this->logger = new Logger("test_context");
        $this->logger->pushHandler($mySQLHandler);
    }

    /**
     * Check whether the current state of the logging table matches expected state
     *
     * @param $filename
     */
    private function assertTableAgainstXMLDump($filename)
    {
        //Read out actual columns, except for time as it is not really testable
        $actualFields = array();
        $rs = $this->getConnection()->getConnection()->query('SELECT * FROM `'.$this->tableName.'` LIMIT 0');
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);

            //Exclude time as it is not really testable
            if ($col['name'] != 'time') {
                $actualFields[] = $col['name'];
            }
        }

        //Prepare MySQL compatible column string
        $column_string = implode(',', array_map(function ($a) { return '`'.$a.'`'; }, $actualFields));

        //Get current state of the table
        $queryTable = $this->getConnection()->createQueryTable($this->tableName, 'SELECT '.$column_string.' FROM `'.$this->tableName.'`;');
        $expectedTable = $this->createMySQLXMLDataSet($filename)->getTable($this->tableName);
        $this->assertTablesEqual($expectedTable, $queryTable);
    }

    /**
     * There should be no table in the beginning
     */
    public function testTableAbsent()
    {
        //Drop table if it exists
        $this->getConnection()->getConnection()->exec('DROP TABLE IF EXISTS `'.$this->tableName.'`;');

        //Table should not exist right now - try to get RowCount on a non-existent table should throw an exception
        $this->expectException(PDOException::class);
        $this->assertEquals(0, $this->getConnection()->getRowCount($this->tableName), "Pre-Condition");
    }

    /**
     * Now add a single log message, which should have on table created
     */
    public function testCreateTable()
    {
        //Setup connection
        $this->setupLogger();

        //Write one test message to create table
        $this->logger->addInfo("Test log message");

        //Now there should be at least one entry
        $this->assertEquals(1, $this->getConnection()->getRowCount($this->tableName), "There should be one row now");

        //Compare against expected table
        $this->assertTableAgainstXMLDump('Tests/testCreateTable.xml');
    }

    /**
     * Extend the table by adding two additional fields
     */
    public function testAddAdditionalField()
    {
        $this->setupLogger(array('username', 'userid'));

        //Currently, there should still be one entry
        $this->assertEquals(1, $this->getConnection()->getRowCount($this->tableName), "There should be one row now");

        //Write another test message
        $this->logger->addAlert("User tried to access area 51 without permission", array('username' => 'waza-ari', 'userid' => 1337));

        //Now there should be two rows
        $this->assertEquals(2, $this->getConnection()->getRowCount($this->tableName), "There should be two rows now");

        //Compare against expected table - check that the username column was added and previous log message was
        //extended by null value
        $this->assertTableAgainstXMLDump('Tests/testAddAdditionalField.xml');
    }

    /**
     * Try to add an entry with one additional field missing
     */
    public function testAddEntryWithIncompleteAdditionalFields()
    {
        $this->setupLogger(array('username', 'userid'));

        //Should have two entries not and should still be same table as in previous test
        $this->assertEquals(2, $this->getConnection()->getRowCount($this->tableName), "There should be two rows now");
        $this->assertTableAgainstXMLDump('Tests/testAddAdditionalField.xml');

        //Log entry with user-id missing
        $this->logger->addAlert("User tried to access area 51,5 without permission", array('username' => 'waza-ari'));

        //Should be three entries now, the last one missing the userid (should be null, checked in dump)
        $this->assertEquals(3, $this->getConnection()->getRowCount($this->tableName), "There should be two rows now");
        $this->assertTableAgainstXMLDump('Tests/testAddEntryWithIncompleteAdditionalFields.xml');
    }

    /**
     * Remove one of the additional fields
     */
    public function testRemoveAdditionalField()
    {
        //Drop userid now
        $this->setupLogger(array('username'));

        //Should have three entries not and should still be same table as in previous test
        $this->assertEquals(3, $this->getConnection()->getRowCount($this->tableName), "There should be two rows now");
        $this->assertTableAgainstXMLDump('Tests/testAddEntryWithIncompleteAdditionalFields.xml');

        //Create new entry
        $this->logger->addAlert("User tried to access area 52 without permission", array('username' => 'waza-ari'));

        //Now, there should be four entries
        $this->assertEquals(4, $this->getConnection()->getRowCount($this->tableName), "There should be two rows now");
        $this->assertTableAgainstXMLDump('Tests/testDeleteAdditionalField.xml');
    }

    /**
     * Try to log an unknown additional field
     * Expected: unknown field should be ignored
     */
    public function testLogUnknownAdditionalField()
    {
        $this->setupLogger(array('username'));
        $this->logger->addEmergency("Schroedinger has opened the box!", array('username' => 'Schroedinger', 'item' => 'Cat'));

        //Now, there should be five entries
        $this->assertEquals(5, $this->getConnection()->getRowCount($this->tableName), "There should be two rows now");
        $this->assertTableAgainstXMLDump('Tests/testLogUnknownAdditionalField.xml');
    }

    /**
     * Test severity handling
     */
    public function testSeverityHandling()
    {
        $this->setupLogger(array('username'), \Monolog\Logger::WARNING);

        //There should be 5 entries, should still be the outcome of last test
        $this->assertEquals(5, $this->getConnection()->getRowCount($this->tableName), "There should be five rows");
        $this->assertTableAgainstXMLDump('Tests/testLogUnknownAdditionalField.xml');

        //Add Entry with lower severity
        $this->logger->addInfo("Schroedinger found a cat in the box!", array('username' => 'Schroedinger'));

        //Nothing should have changed
        $this->assertEquals(5, $this->getConnection()->getRowCount($this->tableName), "There should be five rows");
        $this->assertTableAgainstXMLDump('Tests/testLogUnknownAdditionalField.xml');


        //Now, log a warning
        $this->logger->addWarning('The cat is dead', array('username' => 'Schroedinger'));

        //There should be 6 entries now
        $this->assertEquals(6, $this->getConnection()->getRowCount($this->tableName), "There should be six rows");
        $this->assertTableAgainstXMLDump('Tests/testSeverityHandling.xml');
    }

    /**
     * Test default time format
     */
    public function testDefaultTimeFormat()
    {
        //Get type of database field "time"
        $rs = $this->getConnection()->getConnection()->query('SELECT * FROM `'.$this->tableName.'` LIMIT 0');
        $col = $rs->getColumnMeta(4);
        $this->assertEquals('time', $col['name']);
        $this->assertEquals(2, $col['pdo_type']);
        $this->assertEquals(11, $col['len']);
    }

    /**public function testChangeTimeFormat()
    {
        $this->setupLogger(array('username'), \Monolog\Logger::DEBUG, 'Y-m-d');

        //TODO: verify current number, add logging entry, verify new format
    }**/
}