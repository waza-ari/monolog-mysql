<?php

declare(strict_types=1);

namespace Tests;

use Faker\Factory;
use Faker\Generator;
use HJerichen\DBUnit\Dataset\Dataset;
use HJerichen\DBUnit\Dataset\DatasetArray;
use HJerichen\DBUnit\MySQLTestCaseTrait;
use Monolog\Level;
use Monolog\Logger;
use MySQLHandler\MySQLHandler;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Class MySQLRecordTest
 * @package Tests
 */
class MySQLHandlerTest extends TestCase
{
    use MySQLTestCaseTrait;

    private PDO $database;
    private readonly string $tableName;
    private readonly Generator $faker;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->tableName = 'log';
        $this->faker = Factory::create();
    }

    /**
     * @test
     * @return void
     */
    public function create_mysql_handler_on_non_existing_table_and_insert_record_with_additional_fields(): void
    {
        $this->dropTable();

        $message = $this->faker->sentence();
        $level = $this->faker->randomElement(Level::VALUES);
        $additionalFieldNames = $this->faker->words(3);
        $additionalFields = [];
        foreach ($additionalFieldNames as $fieldName) {
            $additionalFields[$fieldName] = $this->faker->sentence();
        }

        // Create MysqlHandler with some random, additional fields
        $mySQLHandler = new MySQLHandler(
            $this->getDatabase(),
            $this->tableName,
            array_keys($additionalFields),
            false,
            $level
        );

        // Create logger
        $logger = new Logger($this->name(), [$mySQLHandler]);

        // Add new record and fill additional fields
        $logger->addRecord($level, $message, $additionalFields);

        $expected = new DatasetArray([
            $this->tableName => [
                ['id' => 1, 'channel' => $this->name(), 'message' => $message, 'level' => $level, ...$additionalFields],
            ]
        ]);

        $this->assertDatasetEqualsCurrentOne($expected);
    }

    /**
     * Helper method which drops the monolog mysql
     * table used for storing the log records.
     *
     * @return void
     */
    private function dropTable()
    {
        $this->getDatabase()->prepare('DROP TABLE IF EXISTS `'.$this->tableName.'`')->execute();
    }

    protected function getDatabase(): PDO
    {
        if (!isset($this->database)) {
            $this->database = new PDO('mysql:host=mysql;dbname=monolog_mysql_sample;', 'root', 'root');
        }

        return $this->database;
    }

    private function assertDatasetEqualsCurrentOne(DatasetArray $expected): void
    {
        try {
            $this->assertDatasetEqualsCurrent($expected);
            $this->expectNotToPerformAssertions();
        } catch (ComparisonFailure $failure) {
            throw new ExpectationFailedException($failure->getMessage(), $failure);
        }
    }

    /**
     * This test provokes a mysql error: SQLSTATE[HY093]
     * with description: "Invalid parameter number: number of bound
     * variables does not match number of tokens"
     *
     * @see https://github.com/waza-ari/monolog-mysql/issues/41
     * @test
     * @return void
     */
    public function provoke_sqlstate_hy093_number_of_bound_variables_does_not_match_number_of_tokens()
    {
        $this->dropTable();

        $additionalFields = ['channel', 'CORRELATION', 'errorFile', 'errorLine', 'errorTrace'];
        $mysqlHandler = new MySQLHandler(
            $this->getDatabase(),
            $this->tableName,
            $additionalFields,
            false,
            Level::Info
        );

        $logger = new Logger($this->name(), [$mysqlHandler]);

        $content = [
            'id' => 11, // this is expected to be ignored and not inserted into database
            'test1' => 'aaa',
            'test2' => 'bbb'
        ];

        $message = 'test';
        $logger->error($message, $content);

        $expected = new DatasetArray([
            $this->tableName => [
                [
                    'id' => 1,
                    'channel' => $this->name(),
                    'message' => $message,
                    'level' => Level::Error->value,
                    'CORRELATION' => null,
                    'errorFile' => null,
                    'errorLine' => null,
                    'errorTrace' => null,
                ],
            ]
        ]);

        $this->assertDatasetEqualsCurrentOne($expected);
    }

    /**
     * @test
     * @return void
     */
    public function alter_table_schema_in_initialisation(): void
    {
        $this->dropTable();

        $additionalFieldNames = $this->faker->words(5);
        $additionalFields = [];
        foreach ($additionalFieldNames as $fieldName) {
            $additionalFields[$fieldName] = $this->faker->sentence();
        }

        $mySQLHandler = new MySQLHandler($this->getDatabase(), $this->tableName, array_keys($additionalFields), false);
        $logger = new Logger($this->name(), [$mySQLHandler]);
        $logger->info('test', $additionalFields);

        // now create a new random schema which should
        // cause the initialisation process to generate a data migration
        // and schema update
        $additionalFieldNames = $this->faker->words(5);
        $additionalFields = [];
        foreach ($additionalFieldNames as $fieldName) {
            $additionalFields[$fieldName] = $this->faker->sentence();
        }

        $mySQLHandler = new MySQLHandler($this->getDatabase(), $this->tableName, array_keys($additionalFields), false);
        $logger = new Logger($this->name(), [$mySQLHandler]);
        $logger->info('test', $additionalFields);

        $this->expectNotToPerformAssertions();
    }

    protected function getDatasetForSetup(): Dataset
    {
        return new DatasetArray([$this->tableName => []]);
    }
}
