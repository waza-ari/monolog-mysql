<?php

declare(strict_types=1);

namespace MySQLHandler;

use PDO;

/**
 * Class MySQLExecutor
 * @package MySQLHandler
 */
class MySQLExecutor
{
    /**
     * @var PDO
     */
    private $pdo;

    /**
     * @var MySQLRecord
     */
    private $record;

    /**
     * @var MySQLQuery
     */
    private $query;

    /**
     * MySQLExecutor constructor.
     * @param PDO $pdo
     * @param MySQLRecord $record
     */
    public function __construct(PDO $pdo, MySQLRecord $record)
    {
        $this->pdo = $pdo;
        $this->record = $record;
        $this->query = new MySQLQuery();
    }

    /**
     * @return void
     */
    public function createIfNotExistsLogTable(): void
    {
        $this->pdo->exec($this->query->getCreateIfNotExistsTableSQL($this->record->getTable()));
    }

    /**
     * @return void
     */
    public function syncLogTable(): void
    {
        $actualFields = [];
        $rs = $this->pdo->query($this->query->getSelectTableLimitZeroSQL($this->record->getTable()));
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        $removedColumns = array_diff(
            $actualFields,
            $this->record->getAdditionalColumns(),
            $this->record->getDefaultColumns()
        );
        $addedColumns = array_diff($this->record->getAdditionalColumns(), $actualFields);

        //Remove columns
        if (! empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec($this->query->getDropColumnSQL($this->record->getTable(), $c));
            }
        }

        //Add columns
        if (! empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec($this->query->getAddColumnSQL($this->record->getTable(), $c));
            }
        }
    }

    /**
     * @param array $content
     * @return void
     */
    public function writeLog(array $content): void
    {
        $columns = '';
        $fields = '';

        foreach (array_keys($content) as $key => $f) {
            if ($f == 'id') {
                continue;
            }

            if (empty($columns)) {
                $columns .= $f;
                $fields .= ":{$f}";
                continue;
            }

            $columns .= ", {$f}";
            $fields .= ", :{$f}";
        }

        $statement = $this->pdo->prepare($this->query->getPrepareInsertSQL(
            $this->record->getTable(),
            $columns,
            $fields
        ));

        $statement->execute($content);
    }
}