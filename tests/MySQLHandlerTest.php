<?php

declare(strict_types=1);

namespace Tests;

use Faker\Factory;
use MySQLHandler\MySQLHandler;
use MySQLHandler\MySQLRecord;
use PHPUnit\Framework\TestCase;

/**
 * Class MySQLHandlerTest
 * @package Tests
 */
class MySQLHandlerTest extends TestCase
{
    protected static $createSql = "
            CREATE TABLE IF NOT EXISTS `%s` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(255),
                level INTEGER,
                message LONGTEXT,
                time INTEGER UNSIGNED,
                INDEX(channel) USING HASH,
                INDEX(level) USING HASH,
                INDEX(time) USING BTREE
            );
        ";

    /**
     * @test
     * @return void
     * @dataProvider provideFakeData
     */
    public function test_handle(
        string $expectedInsert,
        array $expectedParams,
        array $initialColumns,
        array $record,
        string $table,
        array $additionalFields = [],
        bool $initialize = false,
        int $level = 100,
        bool $bubble = true
    ): void {
        $pdo = $this->createMock(\PDO::class);
        $columnsStmt = $this->createMock(\PDOStatement::class);
        $insertStmt = $this->createMock(\PDOStatement::class);

        $isHandling = $record['level'] >= $level;
        $expectedResult = (!$isHandling) ? false : false === $bubble;
        $mysqlRecord = new MySQLRecord($table, $additionalFields);
        $addedColumns = array_diff($additionalFields, $initialColumns);
        $removedColumns = array_diff($initialColumns, $mysqlRecord->getDefaultColumns(), $additionalFields);

        if ($isHandling) {
            if (!$initialize) {
                $pdo->expects($this->exactly(1 + count($removedColumns) + count($addedColumns)))
                    ->method('exec')
                    ->withConsecutive(
                        [sprintf(static::$createSql, $table)],
                        ...array_map(static function (string $c) use ($table) {
                            return ["ALTER TABLE `{$table}` DROP `{$c}`;"];
                        }, $removedColumns),
                        ...array_map(static function (string $c) use ($table) {
                            return ["ALTER TABLE `{$table}` ADD `{$c}` TEXT NULL DEFAULT NULL;"];
                        }, $addedColumns)
                    )
                    ->willReturn(0);

                $pdo->expects($this->once())
                    ->method('query')
                    ->with("SELECT * FROM `{$table}` LIMIT 0;")
                    ->willReturn($columnsStmt);
                $columnsStmt->expects($this->exactly(count($initialColumns) + 1))
                    ->method('columnCount')
                    ->willReturn(count($initialColumns));

                $columnsStmt->expects($this->exactly(count($initialColumns)))
                    ->method('getColumnMeta')
                    ->willReturn(...array_map(static function (string $c) {
                        return ['name' => $c];
                    }, $initialColumns));
            } else {
                $pdo->expects($this->never())->method('exec');
                $pdo->expects($this->never())->method('query');
            }

            $pdo->expects($this->once())
                ->method('prepare')
                ->with($expectedInsert)
                ->willReturn($insertStmt);

            $insertStmt->expects($this->once())
                ->method('execute')
                ->with($expectedParams)
                ->willReturn(true);
        }

        $handler = new MySQLHandler($pdo, $table, $additionalFields, $initialize, $level, $bubble);
        $this->assertEquals($expectedResult, $handler->handle($record));
    }

    public function provideFakeData(): array
    {
        $faker = Factory::create();
        $faker->seed('1234');
        // Monolog 3.2.0 contains enum Level class while old static method deprecated
        $loggerLevels = class_exists(\Monolog\Level::class) ? \Monolog\Level::VALUES : \Monolog\Logger::getLevels();
        $data = [];

        while (count($data) < 50) {
            $initialColumns = $faker->unique()->words(5);
            $additionalFields = $faker->unique()->words(5);
            $table = $faker->unique()->word();
            $time = $faker->unixTime();
            $channel = $faker->unique()->word();
            $level = $faker->randomElement($loggerLevels);
            $msg = $faker->text;
            $context = array_reduce(
                $faker->randomElements(array_merge($faker->unique()->words(5), $additionalFields), 3),
                static function (array $carry, string $f) use ($faker) {
                    $carry[$f] = $faker->word();
                    return $carry;
                },
                ['id' => 'foobar'],
            );
            $extra = array_reduce(
                $faker->randomElements(array_merge($faker->unique()->words(5), $additionalFields), 3),
                static function (array $carry, string $f) use ($faker) {
                    $carry[$f] = $faker->word();
                    return $carry;
                },
                ['id' => 'foobaz']
            );

            $record = [
                'datetime' => \DateTimeImmutable::createFromFormat('U', strval($time)),
                'channel' => $channel,
                'level' => \Monolog\Logger::toMonologLevel($level),
                'message' => $msg,
                'context' => $context,
                'extra' => $extra
            ];

            $params = array_merge(
                [
                    'channel' => $channel,
                    'level' => $level,
                    'message' => $msg,
                    'time' => strval($time),
                ],
                array_filter(
                    $context,
                    static function ($v, $k) use ($additionalFields) {
                        return in_array($k, $additionalFields);
                    },
                    ARRAY_FILTER_USE_BOTH
                ),
                array_filter(
                    $extra,
                    static function ($v, $k) use ($additionalFields) {
                        return in_array($k, $additionalFields);
                    },
                    ARRAY_FILTER_USE_BOTH
                ),
            );

            if (array_key_exists('id', $params)) {
                unset($params['id']);
            }

            $insertSql = sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s);',
                $table,
                implode(', ', array_keys($params)),
                ':' . implode(', :', array_keys($params))
            );

            $data[] = [
                $insertSql,
                $params,
                $initialColumns,
                $record,
                $table,
                $additionalFields, // additional fields
                $faker->boolean(), // initialize,
                $faker->randomElement($loggerLevels), // level
                $faker->boolean(), // bubble,
            ];
        }

        return $data;
    }
}
