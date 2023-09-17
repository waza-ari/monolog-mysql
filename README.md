monolog-mysql
=============

MySQL Handler for Monolog, which allows to store log messages in a MySQL Table.
It can log text messages to a specific table, and creates the table automatically if it does not exist.
The class further allows to dynamically add extra attributes, which are stored in a separate database field, and can be
used for later analyzing and sorting.

# Installation

monolog-mysql is available via composer. Just add the following line to your required section in composer.json and do
a `php composer.phar update`.

```
"wazaari/monolog-mysql": "^1.0.0"
```

# Usage

Just use it as any other Monolog Handler, push it to the stack of your Monolog Logger instance. The Handler however
needs some parameters:

- **$pdo** PDO Instance of your database. Pass along the PDO instantiation of your database connection with your
  database selected.
- **$table** The table name where the logs should be stored
- **$additionalFields** simple array of additional database fields, which should be stored in the database. The columns
  are created automatically, and the fields can later be used in the extra context section of a record. See examples
  below. _Defaults to an empty array()_
- **$level** can be any of the standard Monolog logging levels. Use Monologs statically defined contexts. _Defaults to
  Logger::DEBUG_
- **$bubble** _Defaults to true_
- **$skipDatabaseModifications** Defines whether we should skip any attempts to sync current database state with what's
  requested by the code (includes creating the table and adding / dropping fields). _Defaults to false_

If $skipDatabaseModifications is set to true, please use the following query as a template to create the log table (with
additional fields, if necessary)

```mysql
CREATE TABLE `log`
(
    id      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(255),
    level   INTEGER,
    message LONGTEXT,
    time    INTEGER UNSIGNED,
    INDEX (channel) USING HASH,
    INDEX (level) USING HASH,
    INDEX (time) USING BTREE
)
```

# Examples

Given that `$pdo` is your database instance, you could use the class as follows:

```php
//Import class
use MySQLHandler\MySQLHandler;

//Create MysqlHandler
$mySQLHandler = new MySQLHandler($pdo, "log", array('username', 'userid'), \Monolog\Logger::DEBUG);

//Create logger
$logger = new \Monolog\Logger($context);
$logger->pushHandler($mySQLHandler);

//Now you can use the logger, and further attach additional information
$logger->addWarning("This is a great message, woohoo!", array('username'  => 'John Doe', 'userid'  => 245));
```

# Test

This extension is covered by phpunit tests for all supported PHP versions. To run the tests you can use the
`docker-compose.yml` to spin up a test environment and run the tests:

```
docker-compose up -d
docker exec -it workspace-php81 sh -c "vendor/bin/phpunit"
docker exec -it workspace-php82 sh -c "vendor/bin/phpunit"
```

# License

This tool is free software and is distributed under the MIT license. Please have a look at the LICENSE file for further
information.
