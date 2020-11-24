<?php

declare(strict_types=1);

namespace Tests;

use Faker\Factory;
use MySQLHandler\MySQLQuery;
use PHPUnit\Framework\TestCase;

/**
 * Class MySQLQueryTest
 * @package Tests
 */
class MySQLQueryTest extends TestCase
{
    /**
     * @test
     * @return void
     */
    public function get_create_table_if_not_exists_sql(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);

        $query = new MySQLQuery();
        $sql = $query->getCreateIfNotExistsTableSQL($table);

        $this->assertEquals("CREATE TABLE IF NOT EXISTS `{$table}` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, 
            channel VARCHAR(255), 
            level INTEGER, 
            message LONGTEXT, 
            time INTEGER UNSIGNED, 
            INDEX(channel) USING HASH, 
            INDEX(level) USING HASH, 
            INDEX(time) USING BTREE
        )", $sql);
    }

    /**
     * @test
     * @return void
     */
    public function get_select_table_limit_zero_sql(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);

        $query = new MySQLQuery();
        $sql = $query->getSelectTableLimitZeroSQL($table);

        $this->assertEquals("SELECT * FROM `{$table}` LIMIT 0;", $sql);
    }

    /**
     * @test
     * @return void
     */
    public function get_drop_column_sql(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);
        $column = strtolower($faker->unique()->word);

        $query = new MySQLQuery();
        $sql = $query->getDropColumnSQL($table, $column);

        $this->assertEquals("ALTER TABLE `{$table}` DROP `{$column}`;", $sql);
    }

    /**
     * @test
     * @return void
     */
    public function get_add_column_sql(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);
        $column = strtolower($faker->unique()->word);

        $query = new MySQLQuery();
        $sql = $query->getAddColumnSQL($table, $column);

        $this->assertEquals("ALTER TABLE `{$table}` ADD `{$column}` TEXT NULL DEFAULT NULL;", $sql);
    }

    /**
     * @test
     * @return void
     */
    public function get_prepare_insert_sql(): void
    {
        $faker = Factory::create();
        $table = strtolower($faker->unique()->word);
        $column = strtolower($faker->unique()->word);

        $query = new MySQLQuery();
        $sql = $query->getPrepareInsertSQL($table, $column, ":{$column}");

        $this->assertEquals("INSERT INTO `{$table}` ({$column}) VALUES (:{$column});", $sql);
    }
}