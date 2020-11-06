<?php

namespace TCG\Voyager\Database\Schema;

use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use Illuminate\Support\Facades\DB;
use TCG\Voyager\Database\Types\Type;

abstract class SchemaManager
{
    public static function getConnections()
    {
        return config('voyager.database.connections', ['mysql']);
    }

    public static function __callStatic($method, $args)
    {
        return static::manager()->$method(...$args);
    }

    public static function manager($connection = null)
    {
        return DB::connection($connection)->getDoctrineSchemaManager();
    }

    public static function getDatabaseConnection($connection = null)
    {
        return DB::connection($connection)->getDoctrineConnection();
    }

    public static function tableExists($table)
    {
        if (!is_array($table)) {
            $table = [$table];
        }

        $matches = false;
        foreach(static::getConnections() as $connection) {
            $matches = $matches || static::manager($connection)->tablesExist($table);
        }

        // return static::tablesExist($table);
        return $matches;
    }

    public static function listTables()
    {
        $tables = [];

        foreach (static::listTableNames() as $tableName) {
            $tables[$tableName] = static::listTableDetails($tableName);
        }

        return $tables;
    }

    public static function listTableNames() {
        $tableNames = [];
        foreach(static::getConnections() as $connection) {
            $tableNames[$connection] = static::manager($connection)->listTableNames();
        }
        return $tableNames;
    }

    /**
     * Return the table connection
     *
     * @param $tableName
     *
     * @return mixed
     * @throws \Exception
     */
    public static function getConnectionByTableName($tableName) {
        $tableConnection = null;
        if (strpos($tableName, '__') !== false) {
            [$tableConnection, $tableName] = explode('__', $tableName);
            if (static::manager($tableConnection)->tablesExist([$tableName])) {
                return $tableConnection;
            }
        }

        foreach(static::getConnections() as $connection) {
            if (static::manager($connection)->tablesExist([$tableName])) {
                $tableConnection = $connection;
            }
        }

        if (empty($tableConnection)) {
            throw new \Exception("No acceptable connection for table $tableName");
        }
        return $tableConnection;
    }

    /**
     * Return the table name
     *
     * @param $tableName
     *
     * @return mixed|string
     */
    public static function getTableByTableName($tableName) {
        if (strpos($tableName, '__') === false) {
            return $tableName;
        }

        [$tableConnection, $tableName] = explode('__', $tableName);
        return $tableName;
    }

    /**
     * @param string $tableName
     *
     * @return \TCG\Voyager\Database\Schema\Table
     * @throws \Doctrine\DBAL\Exception
     * @throws \Exception
     */
    public static function listTableDetails($tableName)
    {
        $connection = static::getConnectionByTableName($tableName);
        $tableName = static::getTableByTableName($tableName);
        $columns = static::manager($connection)->listTableColumns($tableName);

        $foreignKeys = [];
        if (static::manager($connection)->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            $foreignKeys = static::manager($connection)->listTableForeignKeys($tableName);
        }

        $indexes = static::manager($connection)->listTableIndexes($tableName);

        return new Table($tableName, $columns, $indexes, $foreignKeys, false, []);
    }

    /**
     * Describes given table.
     *
     * @param string $tableName
     *
     * @return \Illuminate\Support\Collection
     */
    public static function describeTable($tableName)
    {
        Type::registerCustomPlatformTypes();

        $table = static::listTableDetails($tableName);

        return collect($table->columns)->map(function ($column) use ($table) {
            $columnArr = Column::toArray($column);

            $columnArr['field'] = $columnArr['name'];
            $columnArr['type'] = $columnArr['type']['name'];

            // Set the indexes and key
            $columnArr['indexes'] = [];
            $columnArr['key'] = null;
            if ($columnArr['indexes'] = $table->getColumnsIndexes($columnArr['name'], true)) {
                // Convert indexes to Array
                foreach ($columnArr['indexes'] as $name => $index) {
                    $columnArr['indexes'][$name] = Index::toArray($index);
                }

                // If there are multiple indexes for the column
                // the Key will be one with highest priority
                $indexType = array_values($columnArr['indexes'])[0]['type'];
                $columnArr['key'] = substr($indexType, 0, 3);
            }

            return $columnArr;
        });
    }

    public static function listTableColumnNames($tableName)
    {
        $connection = static::getConnectionByTableName($tableName);
        Type::registerCustomPlatformTypes();

        $columnNames = [];

        foreach (static::manager($connection)->listTableColumns($tableName) as $column) {
            $columnNames[] = $column->getName();
        }

        return $columnNames;
    }

    public static function createTable($table)
    {
        if (!($table instanceof DoctrineTable)) {
            $table = Table::make($table);
        }

        static::manager()->createTable($table);
    }

    public static function getDoctrineTable($table)
    {
        $table = trim($table);

        if (!static::tableExists($table)) {
            throw SchemaException::tableDoesNotExist($table);
        }

        return static::manager()->listTableDetails($table);
    }

    public static function getDoctrineColumn($table, $column)
    {
        return static::getDoctrineTable($table)->getColumn($column);
    }
}
