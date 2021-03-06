<?php
declare(strict_types=1);
namespace TYPO3\CMS\Core\Database\Schema;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handling schema migrations per connection.
 *
 * @internal
 */
class ConnectionMigrator
{
    /**
     * @var string Prefix of deleted tables
     */
    protected $deletedPrefix = 'zzz_deleted_';

    /**
     * @var array
     */
    protected $tableAndFieldMaxNameLengthsPerDbPlatform = [
        'default' => [
            'tables' => 30,
            'columns' => 30
        ],
        'mysql' => [
            'tables' => 64,
            'columns' => 64
        ],
        'drizzle_pdo_mysql' => 'mysql',
        'mysqli' => 'mysql',
        'pdo_mysql' => 'mysql',
        'pdo_sqlite' => 'mysql',
        'postgresql' => [
            'tables' => 63,
            'columns' => 63
        ],
        'sqlserver' => [
            'tables' => 128,
            'columns' => 128
        ],
        'pdo_sqlsrv' => 'sqlserver',
        'sqlsrv' => 'sqlserver',
        'ibm' => [
            'tables' => 30,
            'columns' => 30
        ],
        'ibm_db2' => 'ibm',
        'pdo_ibm' => 'ibm',
        'oci8' => [
            'tables' => 30,
            'columns' => 30
        ],
        'sqlanywhere' => [
            'tables' => 128,
            'columns' => 128
        ]
    ];

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $connectionName;

    /**
     * @var Table[]
     */
    protected $tables;

    /**
     * @param string $connectionName
     * @param Table[] $tables
     */
    public function __construct(string $connectionName, array $tables)
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->connection = $connectionPool->getConnectionByName($connectionName);
        $this->connectionName = $connectionName;
        $this->tables = $tables;
    }

    /**
     * @param string $connectionName
     * @param Table[] $tables
     * @return ConnectionMigrator
     */
    public static function create(string $connectionName, array $tables)
    {
        return GeneralUtility::makeInstance(
            static::class,
            $connectionName,
            $tables
        );
    }

    /**
     * Return the raw Doctrine SchemaDiff object for the current connection.
     * This diff contains all changes without any pre-processing.
     *
     * @return SchemaDiff
     */
    public function getSchemaDiff(): SchemaDiff
    {
        return $this->buildSchemaDiff(false);
    }

    /**
     * Compare current and expected schema definitions and provide updates
     * suggestions in the form of SQL statements.
     *
     * @param bool $remove
     * @return array
     */
    public function getUpdateSuggestions(bool $remove = false): array
    {
        $schemaDiff = $this->buildSchemaDiff();

        if ($remove === false) {
            return array_merge_recursive(
                ['add' => [], 'create_table' => [], 'change' => [], 'change_currentValue' => []],
                $this->getNewFieldUpdateSuggestions($schemaDiff),
                $this->getNewTableUpdateSuggestions($schemaDiff),
                $this->getChangedFieldUpdateSuggestions($schemaDiff),
                $this->getChangedTableOptions($schemaDiff)
            );
        } else {
            return array_merge_recursive(
                ['change' => [], 'change_table' => [], 'drop' => [], 'drop_table' => [], 'tables_count' => []],
                $this->getUnusedFieldUpdateSuggestions($schemaDiff),
                $this->getUnusedTableUpdateSuggestions($schemaDiff),
                $this->getDropTableUpdateSuggestions($schemaDiff),
                $this->getDropFieldUpdateSuggestions($schemaDiff)
            );
        }
    }

    /**
     * Perform add/change/create operations on tables and fields in an
     * optimized, non-interactive, mode using the original doctrine
     * SchemaManager ->toSaveSql() method.
     *
     * @param bool $createOnly
     * @return array
     */
    public function install(bool $createOnly = false): array
    {
        $result = [];
        $schemaDiff = $this->buildSchemaDiff(false);

        $schemaDiff->removedTables = [];
        foreach ($schemaDiff->changedTables as $key => $changedTable) {
            $schemaDiff->changedTables[$key]->removedColumns = [];
            $schemaDiff->changedTables[$key]->removedIndexes = [];

            // With partial ext_tables.sql files the SchemaManager is detecting
            // existing columns as false positives for a column rename. In this
            // context every rename is actually a new column.
            foreach ($changedTable->renamedColumns as $columnName => $renamedColumn) {
                $changedTable->addedColumns[$renamedColumn->getName()] = GeneralUtility::makeInstance(
                    Column::class,
                    $renamedColumn->getName(),
                    $renamedColumn->getType(),
                    array_diff_key($renamedColumn->toArray(), ['name', 'type'])
                );
                unset($changedTable->renamedColumns[$columnName]);
            }

            if ($createOnly) {
                $schemaDiff->changedTables[$key]->changedColumns = [];
                $schemaDiff->changedTables[$key]->renamedIndexes = [];
            }
        }

        $statements = $schemaDiff->toSaveSql(
            $this->connection->getDatabasePlatform()
        );

        foreach ($statements as $statement) {
            try {
                $this->connection->executeUpdate($statement);
                $result[$statement] = '';
            } catch (DBALException $e) {
                $result[$statement] = $e->getPrevious()->getMessage();
            }
        }

        return $result;
    }

    /**
     * If the schema is not for the Default connection remove all tables from the schema
     * that have no mapping in the TYPO3 configuration. This avoids update suggestions
     * for tables that are in the database but have no direct relation to the TYPO3 instance.
     *
     * @param bool $renameUnused
     * @throws \Doctrine\DBAL\DBALException
     * @return \Doctrine\DBAL\Schema\SchemaDiff
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function buildSchemaDiff(bool $renameUnused = true): SchemaDiff
    {
        // Build the schema definitions
        $fromSchema = $this->connection->getSchemaManager()->createSchema();
        $toSchema = $this->buildExpectedSchemaDefinitions($this->connectionName);

        // Add current table options to the fromSchema
        $tableOptions = $this->getTableOptions($fromSchema->getTableNames());
        foreach ($fromSchema->getTables() as $table) {
            $tableName = $table->getName();
            if (!array_key_exists($tableName, $tableOptions)) {
                continue;
            }
            foreach ($tableOptions[$tableName] as $optionName => $optionValue) {
                $table->addOption($optionName, $optionValue);
            }
        }

        // Build SchemaDiff and handle renames of tables and colums
        $comparator = GeneralUtility::makeInstance(Comparator::class, $this->connection->getDatabasePlatform());
        $schemaDiff = $comparator->compare($fromSchema, $toSchema);
        $schemaDiff = $this->migrateColumnRenamesToDistinctActions($schemaDiff);

        if ($renameUnused) {
            $schemaDiff = $this->migrateUnprefixedRemovedTablesToRenames($schemaDiff);
            $schemaDiff = $this->migrateUnprefixedRemovedFieldsToRenames($schemaDiff);
        }

        // All tables in the default connection are managed by TYPO3
        if ($this->connectionName === ConnectionPool::DEFAULT_CONNECTION_NAME) {
            return $schemaDiff;
        }

        // If there are no mapped tables return a SchemaDiff without any changes
        // to avoid update suggestions for tables not related to TYPO3.
        if (empty($GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'])
            || !is_array($GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'])
        ) {
            /** @var SchemaDiff $schemaDiff */
            $schemaDiff = GeneralUtility::makeInstance(SchemaDiff::class, [], [], [], $fromSchema);

            return $schemaDiff;
        }

        // Collect the table names that have been mapped to this connection.
        $connectionName = $this->connectionName;
        $tablesForConnection = array_keys(
            array_filter(
                $GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'],
                function ($tableConnectionName) use ($connectionName) {
                    return $tableConnectionName === $connectionName;
                }
            )
        );

        // Remove all tables that are not assigned to this connection from the diff
        $schemaDiff->newTables = $this->removeUnrelatedTables($schemaDiff->newTables, $tablesForConnection);
        $schemaDiff->changedTables = $this->removeUnrelatedTables($schemaDiff->changedTables, $tablesForConnection);
        $schemaDiff->removedTables = $this->removeUnrelatedTables($schemaDiff->removedTables, $tablesForConnection);

        return $schemaDiff;
    }

    /**
     * Build the expected schema definitons from raw SQL statements.
     *
     * @param string $connectionName
     * @return \Doctrine\DBAL\Schema\Schema
     * @throws \Doctrine\DBAL\DBALException
     * @throws \InvalidArgumentException
     */
    protected function buildExpectedSchemaDefinitions(string $connectionName): Schema
    {
        /** @var Table[] $tablesForConnection */
        $tablesForConnection = [];
        foreach ($this->tables as $table) {
            $tableName = $table->getName();

            // Skip tables for a different connection
            if ($connectionName !== $this->getConnectionNameForTable($tableName)) {
                continue;
            }

            if (!array_key_exists($tableName, $tablesForConnection)) {
                $tablesForConnection[$tableName] = $table;
                continue;
            }

            // Merge multiple table definitions. Later definitions overrule identical
            // columns, indexes and foreign_keys. Order of definitions is based on
            // extension load order.
            $currentTableDefinition = $tablesForConnection[$tableName];
            $tablesForConnection[$tableName] = GeneralUtility::makeInstance(
                Table::class,
                $tableName,
                array_merge($currentTableDefinition->getColumns(), $table->getColumns()),
                array_merge($currentTableDefinition->getIndexes(), $table->getIndexes()),
                array_merge($currentTableDefinition->getForeignKeys(), $table->getForeignKeys()),
                0,
                array_merge($currentTableDefinition->getOptions(), $table->getOptions())
            );
        }

        $tablesForConnection = $this->transformTablesForDatabasePlatform($tablesForConnection, $this->connection);

        $schemaConfig = GeneralUtility::makeInstance(SchemaConfig::class);
        $schemaConfig->setName($this->connection->getDatabase());

        return GeneralUtility::makeInstance(Schema::class, $tablesForConnection, [], $schemaConfig);
    }

    /**
     * Extract the update suggestions (SQL statements) for newly added tables
     * from the complete schema diff.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function getNewTableUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        // Build a new schema diff that only contains added tables
        $addTableSchemaDiff = GeneralUtility::makeInstance(
            SchemaDiff::class,
            $schemaDiff->newTables,
            [],
            [],
            $schemaDiff->fromSchema
        );

        $statements = $addTableSchemaDiff->toSql($this->connection->getDatabasePlatform());

        return ['create_table' => $this->calculateUpdateSuggestionsHashes($statements)];
    }

    /**
     * Extract the update suggestions (SQL statements) for newly added fields
     * from the complete schema diff.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getNewFieldUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        $changedTables = [];

        foreach ($schemaDiff->changedTables as $index => $changedTable) {
            if (count($changedTable->addedColumns) !== 0) {
                // Treat each added column with a new diff to get a dedicated suggestions
                // just for this single column.
                foreach ($changedTable->addedColumns as $addedColumn) {
                    $changedTables[$index . ':tbl_' . $addedColumn->getName()] = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [$addedColumn],
                        [],
                        [],
                        [],
                        [],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );
                }
            }

            if (count($changedTable->addedIndexes) !== 0) {
                // Treat each added index with a new diff to get a dedicated suggestions
                // just for this index.
                foreach ($changedTable->addedIndexes as $addedIndex) {
                    $changedTables[$index . ':idx_' . $addedIndex->getName()] = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [],
                        [],
                        [$addedIndex],
                        [],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );
                }
            }

            if (count($changedTable->addedForeignKeys) !== 0) {
                // Treat each added foreign key with a new diff to get a dedicated suggestions
                // just for this foreign key.
                foreach ($changedTable->addedForeignKeys as $addedForeignKey) {
                    $fkIndex = $index . ':fk_' . $addedForeignKey->getName();
                    $changedTables[$fkIndex] = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [],
                        [],
                        [],
                        [],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );
                    $changedTables[$fkIndex]->addedForeignKeys = [$addedForeignKey];
                }
            }
        }

        // Build a new schema diff that only contains added fields
        $addFieldSchemaDiff = GeneralUtility::makeInstance(
            SchemaDiff::class,
            [],
            $changedTables,
            [],
            $schemaDiff->fromSchema
        );

        $statements = $addFieldSchemaDiff->toSql($this->connection->getDatabasePlatform());

        return ['add' => $this->calculateUpdateSuggestionsHashes($statements)];
    }

    /**
     * Extract update suggestions (SQL statements) for changed options
     * (like ENGINE) from the complete schema diff.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getChangedTableOptions(SchemaDiff $schemaDiff): array
    {
        $updateSuggestions = [];

        foreach ($schemaDiff->changedTables as $tableDiff) {
            // Skip processing if this is the base TableDiff class or has no table options set.
            if (!$tableDiff instanceof TableDiff || count($tableDiff->getTableOptions()) === 0) {
                continue;
            }

            $tableOptions = $tableDiff->getTableOptions();
            $tableOptionsDiff = GeneralUtility::makeInstance(
                TableDiff::class,
                $tableDiff->name,
                [],
                [],
                [],
                [],
                [],
                [],
                $tableDiff->fromTable
            );
            $tableOptionsDiff->setTableOptions($tableOptions);

            $tableOptionsSchemaDiff = GeneralUtility::makeInstance(
                SchemaDiff::class,
                [],
                [$tableOptionsDiff],
                [],
                $schemaDiff->fromSchema
            );

            $statements = $tableOptionsSchemaDiff->toSaveSql($this->connection->getDatabasePlatform());
            foreach ($statements as $statement) {
                $updateSuggestions['change'][md5($statement)] = $statement;
            }
        }

        return $updateSuggestions;
    }

    /**
     * Extract update suggestions (SQL statements) for changed fields
     * from the complete schema diff.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getChangedFieldUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        $databasePlatform = $this->connection->getDatabasePlatform();
        $updateSuggestions = [];

        foreach ($schemaDiff->changedTables as $index => $changedTable) {
            if (count($changedTable->changedColumns) !== 0) {
                // Treat each changed column with a new diff to get a dedicated suggestions
                // just for this single column.
                $fromTable = $schemaDiff->fromSchema->getTable($changedTable->name);
                foreach ($changedTable->changedColumns as $changedColumn) {
                    // Field has been renamed and will be handled separately
                    if ($changedColumn->getOldColumnName()->getName() !== $changedColumn->column->getName()) {
                        continue;
                    }

                    // Get the current SQL declaration for the column
                    $currentColumn = $fromTable->getColumn($changedColumn->getOldColumnName()->getName());
                    $currentDeclaration = $databasePlatform->getColumnDeclarationSQL(
                        $currentColumn->getQuotedName($this->connection->getDatabasePlatform()),
                        $currentColumn->toArray()
                    );

                    // Build a dedicated diff just for the current column
                    $tableDiff = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [$changedColumn],
                        [],
                        [],
                        [],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );

                    $temporarySchemaDiff = GeneralUtility::makeInstance(
                        SchemaDiff::class,
                        [],
                        [$tableDiff],
                        [],
                        $schemaDiff->fromSchema
                    );

                    $statements = $temporarySchemaDiff->toSql($databasePlatform);
                    foreach ($statements as $statement) {
                        $updateSuggestions['change'][md5($statement)] = $statement;
                        $updateSuggestions['change_currentValue'][md5($statement)] = $currentDeclaration;
                    }
                }
            }

            // Treat each changed index with a new diff to get a dedicated suggestions
            // just for this index.
            if (count($changedTable->changedIndexes) !== 0) {
                foreach ($changedTable->renamedIndexes as $key => $changedIndex) {
                    $indexDiff = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [],
                        [],
                        [],
                        [$changedIndex],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );

                    $temporarySchemaDiff = GeneralUtility::makeInstance(
                        SchemaDiff::class,
                        [],
                        [$indexDiff],
                        [],
                        $schemaDiff->fromSchema
                    );

                    $statements = $temporarySchemaDiff->toSql($databasePlatform);
                    foreach ($statements as $statement) {
                        $updateSuggestions['change'][md5($statement)] = $statement;
                    }
                }
            }

            // Treat renamed indexes as a field change as it's a simple rename operation
            if (count($changedTable->renamedIndexes) !== 0) {
                // Create a base table diff without any changes, there's no constructor
                // argument to pass in renamed indexes.
                $tableDiff = GeneralUtility::makeInstance(
                    TableDiff::class,
                    $changedTable->getName($this->connection->getDatabasePlatform()),
                    [],
                    [],
                    [],
                    [],
                    [],
                    [],
                    $schemaDiff->fromSchema->getTable($changedTable->name)
                );

                // Treat each renamed index with a new diff to get a dedicated suggestions
                // just for this index.
                foreach ($changedTable->renamedIndexes as $key => $renamedIndex) {
                    $indexDiff = clone $tableDiff;
                    $indexDiff->renamedIndexes = [$key => $renamedIndex];

                    $temporarySchemaDiff = GeneralUtility::makeInstance(
                        SchemaDiff::class,
                        [],
                        [$indexDiff],
                        [],
                        $schemaDiff->fromSchema
                    );

                    $statements = $temporarySchemaDiff->toSql($databasePlatform);
                    foreach ($statements as $statement) {
                        $updateSuggestions['change'][md5($statement)] = $statement;
                    }
                }
            }

            // Treat each changed foreign key with a new diff to get a dedicated suggestions
            // just for this foreign key.
            if (count($changedTable->changedForeignKeys) !== 0) {
                $tableDiff = GeneralUtility::makeInstance(
                    TableDiff::class,
                    $changedTable->getName($this->connection->getDatabasePlatform()),
                    [],
                    [],
                    [],
                    [],
                    [],
                    [],
                    $schemaDiff->fromSchema->getTable($changedTable->name)
                );

                foreach ($changedTable->changedForeignKeys as $changedForeignKey) {
                    $foreignKeyDiff = clone $tableDiff;
                    $foreignKeyDiff->changedForeignKeys = [$changedForeignKey];

                    $temporarySchemaDiff = GeneralUtility::makeInstance(
                        SchemaDiff::class,
                        [],
                        [$foreignKeyDiff],
                        [],
                        $schemaDiff->fromSchema
                    );

                    $statements = $temporarySchemaDiff->toSql($databasePlatform);
                    foreach ($statements as $statement) {
                        $updateSuggestions['change'][md5($statement)] = $statement;
                    }
                }
            }
        }

        return $updateSuggestions;
    }

    /**
     * Extract update suggestions (SQL statements) for tables that are
     * no longer present in the expected schema from the schema diff.
     * In this case the update suggestions are renames of the tables
     * with a prefix to mark them for deletion in a second sweep.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getUnusedTableUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        $updateSuggestions = [];
        foreach ($schemaDiff->changedTables as $tableDiff) {
            // Skip tables that are not being renamed or where the new name isn't prefixed
            // with the deletion marker.
            if ($tableDiff->getNewName() === false
                || strpos($tableDiff->getNewName()->getName(), $this->deletedPrefix) !== 0
            ) {
                continue;
            }
            // Build a new schema diff that only contains this table
            $changedFieldDiff = GeneralUtility::makeInstance(
                SchemaDiff::class,
                [],
                [$tableDiff],
                [],
                $schemaDiff->fromSchema
            );

            $statements = $changedFieldDiff->toSql($this->connection->getDatabasePlatform());

            foreach ($statements as $statement) {
                $updateSuggestions['change_table'][md5($statement)] = $statement;
            }
            $updateSuggestions['tables_count'][md5($statements[0])] = $this->getTableRecordCount($tableDiff->name);
        }

        return $updateSuggestions;
    }

    /**
     * Extract update suggestions (SQL statements) for fields that are
     * no longer present in the expected schema from the schema diff.
     * In this case the update suggestions are renames of the fields
     * with a prefix to mark them for deletion in a second sweep.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getUnusedFieldUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        $changedTables = [];

        foreach ($schemaDiff->changedTables as $index => $changedTable) {
            if (count($changedTable->changedColumns) === 0) {
                continue;
            }

            // Treat each changed column with a new diff to get a dedicated suggestions
            // just for this single column.
            foreach ($changedTable->changedColumns as $changedColumn) {
                // Field has not been renamed
                if ($changedColumn->getOldColumnName()->getName() === $changedColumn->column->getName()) {
                    continue;
                }

                $changedTables[$index . ':' . $changedColumn->column->getName()] = GeneralUtility::makeInstance(
                    TableDiff::class,
                    $changedTable->getName($this->connection->getDatabasePlatform()),
                    [],
                    [$changedColumn],
                    [],
                    [],
                    [],
                    [],
                    $schemaDiff->fromSchema->getTable($changedTable->name)
                );
            }
        }

        // Build a new schema diff that only contains unused fields
        $changedFieldDiff = GeneralUtility::makeInstance(
            SchemaDiff::class,
            [],
            $changedTables,
            [],
            $schemaDiff->fromSchema
        );

        $statements = $changedFieldDiff->toSql($this->connection->getDatabasePlatform());

        return ['change' => $this->calculateUpdateSuggestionsHashes($statements)];
    }

    /**
     * Extract update suggestions (SQL statements) for fields that can
     * be removed from the complete schema diff.
     * Fields that can be removed have been prefixed in a previous run
     * of the schema migration.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getDropFieldUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        $changedTables = [];

        foreach ($schemaDiff->changedTables as $index => $changedTable) {
            if (count($changedTable->removedColumns) !== 0) {
                // Treat each changed column with a new diff to get a dedicated suggestions
                // just for this single column.
                foreach ($changedTable->removedColumns as $removedColumn) {
                    $changedTables[$index . ':tbl_' . $removedColumn->getName()] = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [],
                        [$removedColumn],
                        [],
                        [],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );
                }
            }

            if (count($changedTable->removedIndexes) !== 0) {
                // Treat each removed index with a new diff to get a dedicated suggestions
                // just for this index.
                foreach ($changedTable->removedIndexes as $removedIndex) {
                    $changedTables[$index . ':idx_' . $removedIndex->getName()] = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [],
                        [],
                        [],
                        [],
                        [$removedIndex],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );
                }
            }

            if (count($changedTable->removedForeignKeys) !== 0) {
                // Treat each removed foreign key with a new diff to get a dedicated suggestions
                // just for this foreign key.
                foreach ($changedTable->removedForeignKeys as $removedForeignKey) {
                    $fkIndex = $index . ':fk_' . $removedForeignKey->getName();
                    $changedTables[$fkIndex] = GeneralUtility::makeInstance(
                        TableDiff::class,
                        $changedTable->getName($this->connection->getDatabasePlatform()),
                        [],
                        [],
                        [],
                        [],
                        [],
                        [],
                        $schemaDiff->fromSchema->getTable($changedTable->name)
                    );
                    $changedTables[$fkIndex]->removedForeignKeys = [$removedForeignKey];
                }
            }
        }

        // Build a new schema diff that only contains removable fields
        $removedFieldDiff = GeneralUtility::makeInstance(
            SchemaDiff::class,
            [],
            $changedTables,
            [],
            $schemaDiff->fromSchema
        );

        $statements = $removedFieldDiff->toSql($this->connection->getDatabasePlatform());

        return ['drop' => $this->calculateUpdateSuggestionsHashes($statements)];
    }

    /**
     * Extract update suggestions (SQL statements) for tables that can
     * be removed from the complete schema diff.
     * Tables that can be removed have been prefixed in a previous run
     * of the schema migration.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return array
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function getDropTableUpdateSuggestions(SchemaDiff $schemaDiff): array
    {
        $updateSuggestions = [];
        foreach ($schemaDiff->removedTables as $removedTable) {
            // Build a new schema diff that only contains this table
            $tableDiff = GeneralUtility::makeInstance(
                SchemaDiff::class,
                [],
                [],
                [$removedTable],
                $schemaDiff->fromSchema
            );

            $statements = $tableDiff->toSql($this->connection->getDatabasePlatform());
            foreach ($statements as $statement) {
                $updateSuggestions['drop_table'][md5($statement)] = $statement;
            }

            // Only store the record count for this table for the first statement,
            // assuming that this is the actual DROP TABLE statement.
            $updateSuggestions['tables_count'][md5($statements[0])] = $this->getTableRecordCount(
                $removedTable->getName()
            );
        }

        return $updateSuggestions;
    }

    /**
     * Move tables to be removed that are not prefixed with the deleted prefix to the list
     * of changed tables and set a new prefixed name.
     * Without this help the Doctrine SchemaDiff has no idea if a table has been renamed and
     * performs a drop of the old table and creates a new table, which leads to all data in
     * the old table being lost.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return \Doctrine\DBAL\Schema\SchemaDiff
     * @throws \InvalidArgumentException
     */
    protected function migrateUnprefixedRemovedTablesToRenames(SchemaDiff $schemaDiff): SchemaDiff
    {
        foreach ($schemaDiff->removedTables as $index => $removedTable) {
            if (strpos($removedTable->getName(), $this->deletedPrefix) === 0) {
                continue;
            }
            $tableDiff = GeneralUtility::makeInstance(
                TableDiff::class,
                $removedTable->getQuotedName($this->connection->getDatabasePlatform()),
                $addedColumns = [],
                $changedColumns = [],
                $removedColumns = [],
                $addedIndexes = [],
                $changedIndexes = [],
                $removedIndexes = [],
                $fromTable = $removedTable
            );

            $tableDiff->newName = substr(
                $this->deletedPrefix . $removedTable->getName(),
                0,
                $this->getMaxTableNameLength()
            );
            $schemaDiff->changedTables[$index] = $tableDiff;
            unset($schemaDiff->removedTables[$index]);
        }

        return $schemaDiff;
    }

    /**
     * Scan the list of changed tables for fields that are going to be dropped. If
     * the name of the field does not start with the deleted prefix mark the column
     * for a rename instead of a drop operation.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return \Doctrine\DBAL\Schema\SchemaDiff
     * @throws \InvalidArgumentException
     */
    protected function migrateUnprefixedRemovedFieldsToRenames(SchemaDiff $schemaDiff): SchemaDiff
    {
        foreach ($schemaDiff->changedTables as $tableIndex => $changedTable) {
            if (count($changedTable->removedColumns) === 0) {
                continue;
            }

            foreach ($changedTable->removedColumns as $columnIndex => $removedColumn) {
                if (strpos($removedColumn->getName(), $this->deletedPrefix) === 0) {
                    continue;
                }

                // Build a new column object with the same properties as the removed column
                $renamedColumnName = substr(
                    $this->deletedPrefix . $removedColumn->getName(),
                    0,
                    $this->getMaxColumnNameLength()
                );
                $renamedColumn = new Column(
                    $this->connection->quoteIdentifier($renamedColumnName),
                    $removedColumn->getType(),
                    array_diff_key($removedColumn->toArray(), ['name', 'type'])
                );

                // Build the diff object for the column to rename
                $columnDiff = GeneralUtility::makeInstance(
                    ColumnDiff::class,
                    $removedColumn->getQuotedName($this->connection->getDatabasePlatform()),
                    $renamedColumn,
                    $changedProperties = [],
                    $removedColumn
                );

                // Add the column with the required rename information to the changed column list
                $schemaDiff->changedTables[$tableIndex]->changedColumns[$columnIndex] = $columnDiff;

                // Remove the column from the list of columns to be dropped
                unset($schemaDiff->changedTables[$tableIndex]->removedColumns[$columnIndex]);
            }
        }

        return $schemaDiff;
    }

    /**
     * Revert the automatic rename optimization that Doctrine performs when it detects
     * a column being added and a column being dropped that only differ by name.
     *
     * @param \Doctrine\DBAL\Schema\SchemaDiff $schemaDiff
     * @return SchemaDiff
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     */
    protected function migrateColumnRenamesToDistinctActions(SchemaDiff $schemaDiff): SchemaDiff
    {
        foreach ($schemaDiff->changedTables as $index => $changedTable) {
            if (count($changedTable->renamedColumns) === 0) {
                continue;
            }

            // Treat each renamed column with a new diff to get a dedicated
            // suggestion just for this single column.
            foreach ($changedTable->renamedColumns as $originalColumnName => $renamedColumn) {
                $columnOptions = array_diff_key($renamedColumn->toArray(), ['name', 'type']);

                $changedTable->addedColumns[$renamedColumn->getName()] = GeneralUtility::makeInstance(
                    Column::class,
                    $renamedColumn->getName(),
                    $renamedColumn->getType(),
                    $columnOptions
                );
                $changedTable->removedColumns[$originalColumnName] = GeneralUtility::makeInstance(
                    Column::class,
                    $originalColumnName,
                    $renamedColumn->getType(),
                    $columnOptions
                );

                unset($changedTable->renamedColumns[$originalColumnName]);
            }
        }

        return $schemaDiff;
    }

    /**
     * Retrieve the database platform-specific limitations on column and schema name sizes as
     * defined in the tableAndFieldMaxNameLengthsPerDbPlatform property.
     *
     * @param string $databasePlatform
     * @return array
     */
    protected function getTableAndFieldNameMaxLengths(string $databasePlatform = '')
    {
        if ($databasePlatform === '') {
            $databasePlatform = $this->connection->getDatabasePlatform()->getName();
        }
        $databasePlatform = strtolower($databasePlatform);

        if (isset($this->tableAndFieldMaxNameLengthsPerDbPlatform[$databasePlatform])) {
            $nameLengthRestrictions = $this->tableAndFieldMaxNameLengthsPerDbPlatform[$databasePlatform];
        } else {
            $nameLengthRestrictions = $this->tableAndFieldMaxNameLengthsPerDbPlatform['default'];
        }

        if (is_string($nameLengthRestrictions)) {
            return $this->getTableAndFieldNameMaxLengths($nameLengthRestrictions);
        } else {
            return $nameLengthRestrictions;
        }
    }

    /**
     * Get the maximum table name length possible for the given DB platform.
     *
     * @param string $databasePlatform
     * @return string
     */
    protected function getMaxTableNameLength(string $databasePlatform = '')
    {
        $nameLengthRestrictions = $this->getTableAndFieldNameMaxLengths($databasePlatform);
        return $nameLengthRestrictions['tables'];
    }

    /**
     * Get the maximum column name length possible for the given DB platform.
     *
     * @param string $databasePlatform
     * @return string
     */
    protected function getMaxColumnNameLength(string $databasePlatform = '')
    {
        $nameLengthRestrictions = $this->getTableAndFieldNameMaxLengths($databasePlatform);
        return $nameLengthRestrictions['columns'];
    }

    /**
     * Return the amount of records in the given table.
     *
     * @param string $tableName
     * @return int
     * @throws \InvalidArgumentException
     */
    protected function getTableRecordCount(string $tableName): int
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($tableName)
            ->count('*', $tableName, []);
    }

    /**
     * Determine the connection name for a table
     *
     * @param string $tableName
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function getConnectionNameForTable(string $tableName): string
    {
        $connectionNames = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionNames();

        if (array_key_exists($tableName, (array)$GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'])) {
            return in_array($GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'][$tableName], $connectionNames, true)
                ? $GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'][$tableName]
                : ConnectionPool::DEFAULT_CONNECTION_NAME;
        }

        return ConnectionPool::DEFAULT_CONNECTION_NAME;
    }

    /**
     * Replace the array keys with a md5 sum of the actual SQL statement
     *
     * @param string[] $statements
     * @return string[]
     */
    protected function calculateUpdateSuggestionsHashes(array $statements): array
    {
        return array_combine(array_map('md5', $statements), $statements);
    }

    /**
     * Helper for buildSchemaDiff to filter an array of TableDiffs against a list of valid table names.
     *
     * @param TableDiff[]|Table[] $tableDiffs
     * @param string[] $validTableNames
     * @return TableDiff[]
     * @throws \InvalidArgumentException
     */
    protected function removeUnrelatedTables(array $tableDiffs, array $validTableNames): array
    {
        return array_filter(
            $tableDiffs,
            function ($table) use ($validTableNames) {
                if ($table instanceof Table) {
                    $tableName = $table->getName();
                } else {
                    $tableName = $table->newName ?: $table->name;
                }

                // If the tablename has a deleted prefix strip it of before comparing
                // it against the list of valid table names so that drop operations
                // don't get removed.
                if (strpos($tableName, $this->deletedPrefix) === 0) {
                    $tableName = substr($tableName, strlen($this->deletedPrefix));
                }
                return in_array($tableName, $validTableNames, true)
                    || in_array($this->deletedPrefix . $tableName, $validTableNames, true);
            }
        );
    }

    /**
     * Transform the table information to conform to specific
     * requirements of different database platforms like removing
     * the index substring length for Non-MySQL Platforms.
     *
     * @param Table[] $tables
     * @param \TYPO3\CMS\Core\Database\Connection $connection
     * @return Table[]
     * @throws \InvalidArgumentException
     */
    protected function transformTablesForDatabasePlatform(array $tables, Connection $connection): array
    {
        foreach ($tables as &$table) {
            $indexes = [];
            foreach ($table->getIndexes() as $key => $index) {
                $indexName = $index->getName();
                // PostgreSQL requires index names to be unique per database/schema.
                if ($connection->getDatabasePlatform() instanceof PostgreSqlPlatform) {
                    $indexName = $indexName . '_' . hash('crc32b', $table->getName() . '_' . $indexName);
                }

                // Remove the length information from column names for indexes if required.
                $cleanedColumnNames = array_map(
                    function (string $columnName) use ($connection) {
                        if ($connection->getDatabasePlatform() instanceof MySqlPlatform) {
                            // Returning the unquoted, unmodified version of the column name since
                            // it can include the length information for BLOB/TEXT columns which
                            // may not be quoted.
                            return $columnName;
                        }

                        return $connection->quoteIdentifier(preg_replace('/\(\d+\)$/', '', $columnName));
                    },
                    $index->getUnquotedColumns()
                );

                $indexes[$key] = GeneralUtility::makeInstance(
                    Index::class,
                    $connection->quoteIdentifier($indexName),
                    $cleanedColumnNames,
                    $index->isUnique(),
                    $index->isPrimary(),
                    $index->getFlags(),
                    $index->getOptions()
                );
            }

            $table = GeneralUtility::makeInstance(
                Table::class,
                $table->getQuotedName($connection->getDatabasePlatform()),
                $table->getColumns(),
                $indexes,
                $table->getForeignKeys(),
                0,
                $table->getOptions()
            );
        }

        return $tables;
    }

    /**
     * Get COLLATION, ROW_FORMAT, COMMENT and ENGINE table options on MySQL connections.
     *
     * @param string[] $tableNames
     * @return array[]
     * @throws \InvalidArgumentException
     */
    protected function getTableOptions(array $tableNames): array
    {
        $tableOptions = [];
        if (strpos($this->connection->getServerVersion(), 'MySQL') !== 0) {
            foreach ($tableNames as $tableName) {
                $tableOptions[$tableName] = [];
            }

            return $tableOptions;
        }

        $queryBuilder = $this->connection->createQueryBuilder();
        $result = $queryBuilder
            ->select(
                'TABLE_NAME AS table',
                'ENGINE AS engine',
                'ROW_FORMAT AS row_format',
                'TABLE_COLLATION AS collate',
                'TABLE_COMMENT AS comment'
            )
            ->from('information_schema.TABLES')
            ->where(
                $queryBuilder->expr()->eq(
                    'TABLE_TYPE',
                    $queryBuilder->createNamedParameter('BASE TABLE', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'TABLE_SCHEMA',
                    $queryBuilder->createNamedParameter($this->connection->getDatabase(), \PDO::PARAM_STR)
                )
            )
            ->execute();

        while ($row = $result->fetch()) {
            $index = $row['table'];
            unset($row['table']);
            $tableOptions[$index] = $row;
        }

        return $tableOptions;
    }
}
