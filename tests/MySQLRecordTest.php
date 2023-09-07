<?php

declare(strict_types=1);

namespace Tests;

use Faker\Factory;
use Monolog\Level;
use Monolog\Logger;
use MySQLHandler\MySQLRecord;
use PHPUnit\Framework\TestCase;

/**
 * Class MySQLRecordTest
 * @package Tests
 */
class MySQLRecordTest extends TestCase
{
    /**
     * @test
     * @return void
     */
    public function get_columns_will_equals_default_columns_and_additional_columns_merge(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);
        $columns = array_pad([], 5, strtolower($faker->unique()->word));
        $record = new MySQLRecord($table, $columns);

        $this->assertEquals(array_merge([
            'id',
            'channel',
            'level',
            'message',
            'time',
        ], $columns), $record->getColumns());
    }

    /**
     * @test
     * @return void
     */
    public function filter_content_will_equals_argument(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);
        $columns = array_pad([], 5, strtolower($faker->unique()->word));
        $record = new MySQLRecord($table, $columns);

        $data = array_merge([
            'channel' => strtolower($faker->unique()->word),
            'level' => $faker->randomElement(Level::NAMES),
            'message' => $faker->text,
            'time' => $faker->dateTime,
        ], array_fill_keys($columns, $faker->text));

        $content = $record->filterContent($data);

        $this->assertEquals($data, $content);
    }

    /**
     * @test
     * @return void
     */
    public function filter_content_will_exclude_out_of_columns(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);
        $columns = array_pad([], 5, strtolower($faker->unique()->word));
        $outOfColumns = array_pad([], 5, strtolower($faker->unique()->word));
        $record = new MySQLRecord($table, $columns);

        $data = array_merge([
            'channel' => strtolower($faker->unique()->word),
            'level' => $faker->randomElement(Level::NAMES),
            'message' => $faker->text,
            'time' => $faker->dateTime,
        ], array_fill_keys($outOfColumns, $faker->text));

        $content = $record->filterContent($data);

        $this->assertNotEquals($data, $content);
        $this->assertArrayHasKey('channel', $content);
        $this->assertArrayHasKey('level', $content);
        $this->assertArrayHasKey('message', $content);
        $this->assertArrayHasKey('time', $content);
        array_map(function ($key) use ($content) {
            $this->assertArrayNotHasKey($key, $content);
        }, $outOfColumns);
    }

}