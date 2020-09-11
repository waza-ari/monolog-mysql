<?php

declare(strict_types=1);

namespace MySQLHandler;

/**
 * Class MySQLRecord
 * @package MySQLHandler
 */
class MySQLRecord
{
    /**
     * the table to store the logs in
     *
     * @var string
     */
    private $table;

    /**
     * default columns that are stored in db
     *
     * @var array|string[]
     */
    private $defaultColumns;

    /**
     * additional fields to be stored in the database
     *
     * For each field $field, an additional context field with the name $field
     * is expected along the message, and further the database needs to have these fields
     * as the values are stored in the column name $field.
     *
     * @var array
     */
    private $additionalColumns;

    /**
     * MySQLRecord constructor.
     * @param string $table
     * @param array $additionalColumns
     */
    public function __construct(string $table, array $additionalColumns = [])
    {
        $this->table = $table;

        $this->defaultColumns = [
            'id',
            'channel',
            'level',
            'message',
            'time',
        ];

        $this->additionalColumns = $additionalColumns;
    }

    /**
     * @param array $content
     * @return array
     */
    public function filterContent(array $content): array
    {
        return array_filter($content, function($key) {
            return in_array($key, $this->getColumns());
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return array|string[]
     */
    public function getDefaultColumns()
    {
        return $this->defaultColumns;
    }

    /**
     * @return array
     */
    public function getAdditionalColumns(): array
    {
        return $this->additionalColumns;
    }

    /**
     * @return array
     */
    public function getColumns(): array
    {
        return array_merge($this->defaultColumns, $this->additionalColumns);
    }
}