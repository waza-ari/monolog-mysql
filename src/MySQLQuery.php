<?php

declare(strict_types=1);

namespace MySQLHandler;

/**
 * Class MySQLQuery
 * @package MySQLHandler
 */
class MySQLQuery
{
    /**
     * @param string $tableName
     * @return string
     */
    public function getCreateIfNotExistsTableSQL(string $tableName): string
    {
        return "CREATE TABLE IF NOT EXISTS `{$tableName}` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, 
            channel VARCHAR(255), 
            level INTEGER, 
            message LONGTEXT, 
            time INTEGER UNSIGNED, 
            INDEX(channel) USING HASH, 
            INDEX(level) USING HASH, 
            INDEX(time) USING BTREE
        )";
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getSelectTableLimitZeroSQL(string $tableName): string
    {
        return "SELECT * FROM `{$tableName}` LIMIT 0;";
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    public function getDropColumnSQL(string $tableName, string $columnName): string
    {
        return "ALTER TABLE `{$tableName}` DROP `{$columnName}`;";
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    public function getAddColumnSQL(string $tableName, string $columnName): string
    {
        return "ALTER TABLE `{$tableName}` ADD `{$columnName}` TEXT NULL DEFAULT NULL;";
    }

    /**
     * @param string $tableName
     * @param string $columns
     * @param string $fields
     * @return string
     */
    public function getPrepareInsertSQL(string $tableName, string $columns, string $fields): string
    {
        return "INSERT INTO `{$tableName}` ({$columns}) VALUES ({$fields});";
    }
}