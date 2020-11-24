<?php

declare(strict_types=1);

namespace Tests;

use MySQLHandler\MySQLExecutor;
use MySQLHandler\MySQLRecord;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Class MySQLExecutorTest
 * @package Tests
 */
class MySQLExecutorTest extends TestCase
{
    /**
     * @test
     * @return void
     */
    public function create_if_not_exists_log_table(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('exec');

        $record = $this->createMock(MySQLRecord::class);
        $record->expects($this->once())
            ->method('getTable');

        $executor = new MySQLExecutor($pdo, $record);
        $executor->createIfNotExistsLogTable();
    }


}