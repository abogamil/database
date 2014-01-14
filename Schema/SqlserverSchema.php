<?php
/**
 * PHP Version 5.4
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 3.0.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace Cake\Database\Schema;

use Cake\Database\Exception;
use Cake\Database\Schema\Table;

/**
 * Schema management/reflection features for Postgres.
 */
class SqlserverSchema extends BaseSchema {

/**
 * {@inheritdoc}
 *
 */
	public function listTablesSql($config) {
		return ['SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_NAME', []];
	}

/**
 * {@inheritdoc}
 *
 */
	public function describeTableSql($name, $config) {
		$sql =
		"SELECT DISTINCT TABLE_SCHEMA AS [schema], COLUMN_NAME AS [name], DATA_TYPE AS [type],
			IS_NULLABLE AS [null], COLUMN_DEFAULT AS [default],
			CHARACTER_MAXIMUM_LENGTH AS [char_length],
			'' AS [comment], ORDINAL_POSITION AS [ordinal_position]
		FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_NAME = ?
		ORDER BY ordinal_position";

		return [$sql, [$name]];
	}

/**
 * Convert a column definition to the abstract types.
 *
 * The returned type will be a type that
 * Cake\Database\Type can handle.
 *
 * @param string $column The column type + length
 * @throws Cake\Database\Exception when column cannot be parsed.
 * @return array Array of column information.
 * @link http://technet.microsoft.com/en-us/library/ms187752.aspx
 */
	protected function _convertColumn($column) {
		preg_match('/([a-z\s]+)(?:\(([0-9,]+)\))?/i', $column, $matches);
		if (empty($matches)) {
			throw new Exception(__d('cake_dev', 'Unable to parse column type from "%s"', $column));
		}

		$col = strtolower($matches[1]);
		$length = null;
		if (isset($matches[2])) {
			$length = (int)$matches[2];
		}

		if (in_array($col, array('date', 'time'))) {
			return ['type' => $col, 'length' => null];
		}
		if (strpos($col, 'datetime') !== false) {
			return ['type' => 'datetime', 'length' => null];
		}

		if ($col === 'int' || $col === 'integer') {
			return ['type' => 'integer', 'length' => 10];
		}
		if ($col === 'bigint') {
			return ['type' => 'biginteger', 'length' => 20];
		}
		if ($col === 'smallint') {
			return ['type' => 'integer', 'length' => 5];
		}
		if ($col === 'tinyint') {
			return ['type' => 'integer', 'length' => 3];
		}
		if ($col === 'bit') {
			return ['type' => 'boolean', 'length' => null];
		}
		if (
			strpos($col, 'numeric') !== false ||
			strpos($col, 'money') !== false ||
			strpos($col, 'decimal') !== false
		) {
			return ['type' => 'decimal', 'length' => null];
		}
		if ($col === 'real' || $col === 'float') {
			return ['type' => 'float', 'length' => null];
		}

		if (strpos($col, 'varchar') !== false) {
			return ['type' => 'string', 'length' => $length];
		}
		if (strpos($col, 'char') !== false) {
			return ['type' => 'string', 'fixed' => true, 'length' => $length];
		}
		if (strpos($col, 'text') !== false) {
			return ['type' => 'text', 'length' => null];
		}

		if ($col === 'image' || strpos($col, 'binary')) {
			return ['type' => 'binary', 'length' => null];
		}

		if ($col === 'uniqueidentifier') {
			return ['type' => 'string', 'fixed' => true, 'length' => 36];
		}
		// @todo cursor, hierarchyid, sql_variant, table, xml

		return ['type' => 'text', 'length' => null];
	}

/**
 * {@inheritdoc}
 *
 */
	public function convertFieldDescription(Table $table, $row) {
		$field = $this->_convertColumn($row['type']);
		if (!empty($row['default'])) {
			$row['default'] = trim($row['default'], '()');
		}

		if ($field['type'] === 'boolean') {
			$row['default'] = (int)$row['default'];
		}

		$field += [
			'null' => $row['null'] === 'YES' ? true : false,
			'default' => $row['default'],
		];
		$field['length'] = $row['char_length'] ?: $field['length'];
		$table->addColumn($row['name'], $field);
	}

/**
 * {@inheritdoc}
 *
 */
	public function describeIndexSql($table, $config) {
		$sql = "
			SELECT  
				I.[name] AS [index_name],
				IC.[index_column_id] AS [index_order],
				AC.[name] AS [column_name],  
				I.[is_unique], I.[is_primary_key], 
				I.[is_unique_constraint]
			FROM sys.[tables] AS T  
			INNER JOIN sys.[indexes] I ON T.[object_id] = I.[object_id]  
			INNER JOIN sys.[index_columns] IC ON I.[object_id] = IC.[object_id] AND I.[index_id] = IC.[index_id]
			INNER JOIN sys.[all_columns] AC ON T.[object_id] = AC.[object_id] AND IC.[column_id] = AC.[column_id] 
			WHERE T.[is_ms_shipped] = 0 AND I.[type_desc] <> 'HEAP' AND T.[name] = ?
			ORDER BY I.[index_id], IC.[index_column_id]
		";

		return [$sql, [$table]];
	}

/**
 * {@inheritdoc}
 *
 */
	public function convertIndexDescription(Table $table, $row) {
		$type = Table::INDEX_INDEX;
		$name = $row['index_name'];
		if ($row['is_primary_key']) {
			$name = $type = Table::CONSTRAINT_PRIMARY;
		}
		if ($row['is_unique_constraint'] && $type === Table::INDEX_INDEX) {
			$type = Table::CONSTRAINT_UNIQUE;
		}

		if ($type === Table::INDEX_INDEX) {
			$existing = $table->index($name);
		} else {
			$existing = $table->constraint($name);
		}

		$columns = [$row['column_name']];
		if (!empty($existing)) {
			$columns = array_merge($existing['columns'], $columns);
		}

		if ($type === Table::CONSTRAINT_PRIMARY || $type === Table::CONSTRAINT_UNIQUE) {
			$table->addConstraint($name, [
				'type' => $type,
				'columns' => $columns
			]);
			return;
		}
		$table->addIndex($name, [
			'type' => $type,
			'columns' => $columns
		]);
	}

/**
 * {@inheritdoc}
 *
 */
	public function describeForeignKeySql($table, $config) {
		$sql = "
			SELECT FK.[name] AS [foreign_key_name], FK.[delete_referential_action_desc] AS [delete_type],
				FK.[update_referential_action_desc] AS [update_type], C.name AS [column], RT.name AS [reference_table],
				RC.name AS [reference_column]
			FROM sys.foreign_keys FK
			INNER JOIN sys.foreign_key_columns FKC ON FKC.constraint_object_id = FK.object_id
			INNER JOIN sys.tables T ON T.object_id = FKC.parent_object_id
			INNER JOIN sys.tables RT ON RT.object_id = FKC.referenced_object_id
			INNER JOIN sys.columns C ON C.column_id = FKC.parent_column_id AND C.object_id = FKC.parent_object_id
			INNER JOIN sys.columns RC ON RC.column_id = FKC.referenced_column_id AND RC.object_id = FKC.referenced_object_id
			WHERE FK.is_ms_shipped = 0 AND T.name = ?
		";

		return [$sql, [$table]];
	}

/**
 * {@inheritdoc}
 *
 */
	public function convertForeignKeyDescription(Table $table, $row) {
		$data = [
			'type' => Table::CONSTRAINT_FOREIGN,
			'columns' => [$row['column']],
			'references' => [$row['reference_table'], $row['reference_column']],
			'update' => $this->_convertOnClause($row['update_type']),
			'delete' => $this->_convertOnClause($row['delete_type']),
		];
		$name = $row['foreign_key_name'];
		$table->addConstraint($name, $data);
	}

/**
 * {@inheritdoc}
 *
 */
	protected function _foreignOnClause($on) {
		$parent = parent::_foreignOnClause($on);
		return $parent === 'RESTRICT' ? parent::_foreignOnClause(Table::ACTION_SET_NULL) : $parent;
	}

/**
 * {@inheritdoc}
 *
 */
	protected function _convertOnClause($clause) {
		switch ($clause) {
			case 'NO_ACTION':
				return Table::ACTION_NO_ACTION;
			case 'CASCADE':
				return Table::ACTION_CASCADE;
			case 'SET_NULL':
				return Table::ACTION_SET_NULL;
			case 'SET_DEFAULT':
				return Table::ACTION_SET_DEFAULT;
		}
		return Table::ACTION_SET_NULL;
	}

/**
 * {@inheritdoc}
 *
 */
	public function columnSql(Table $table, $name) {
		$data = $table->column($name);
		$out = $this->_driver->quoteIdentifier($name);
		$typeMap = [
			'biginteger' => ' BIGINT',
			'boolean' => ' BIT',
			'binary' => ' BINARY',
			'float' => ' FLOAT',
			'decimal' => ' DECIMAL',
			'text' => ' TEXT',
			'date' => ' DATE',
			'time' => ' TIME',
			'datetime' => ' DATETIME',
			'timestamp' => ' DATETIME',
		];

		if (isset($typeMap[$data['type']])) {
			$out .= $typeMap[$data['type']];
		}

		if ($data['type'] === 'integer') {
			$type = ' INTEGER';
			$out .= $type;
		}

		if ($data['type'] === 'string') {
			$isFixed = !empty($data['fixed']);
			$type = ' VARCHAR';
			if ($isFixed) {
				$type = ' CHAR';
			}
			if ($isFixed && isset($data['length']) && $data['length'] == 36) {
				$type = ' UNIQUEIDENTIFIER';
			}
			$out .= $type;
			if (isset($data['length']) && $data['length'] != 36) {
				$out .= '(' . (int)$data['length'] . ')';
			}
		}

		if ($data['type'] === 'float' && isset($data['precision'])) {
			$out .= '(' . (int)$data['precision'] . ')';
		}

		if ($data['type'] === 'decimal' &&
			(isset($data['length']) || isset($data['precision']))
		) {
			$out .= '(' . (int)$data['length'] . ',' . (int)$data['precision'] . ')';
		}

		if (isset($data['null']) && $data['null'] === false) {
			$out .= ' NOT NULL';
		}
		if (isset($data['null']) && $data['null'] === true) {
			$out .= ' DEFAULT NULL';
			unset($data['default']);
		}
		if (isset($data['default']) && $data['type'] !== 'datetime') {
			$default = is_bool($data['default']) ? (int)$data['default'] : $this->_driver->schemaValue($data['default']);
			$out .= ' DEFAULT ' . $default;
		}
		return $out;
	}

/**
 * {@inheritdoc}
 *
 */
	public function indexSql(Table $table, $name) {
		$data = $table->index($name);
		$columns = array_map(
			[$this->_driver, 'quoteIdentifier'],
			$data['columns']
		);
		return sprintf('CREATE INDEX %s ON %s (%s)',
			$this->_driver->quoteIdentifier($name),
			$this->_driver->quoteIdentifier($table->name()),
			implode(', ', $columns)
		);
	}

/**
 * {@inheritdoc}
 *
 */
	public function constraintSql(Table $table, $name) {
		$data = $table->constraint($name);
		$out = 'CONSTRAINT ' . $this->_driver->quoteIdentifier($name);
		if ($data['type'] === Table::CONSTRAINT_PRIMARY) {
			$out = 'PRIMARY KEY';
		}
		if ($data['type'] === Table::CONSTRAINT_UNIQUE) {
			$out .= ' UNIQUE';
		}
		return $this->_keySql($out, $data);
	}

/**
 * Helper method for generating key SQL snippets.
 *
 * @param string $prefix The key prefix
 * @param array $data Key data.
 * @return string
 */
	protected function _keySql($prefix, $data) {
		$columns = array_map(
			[$this->_driver, 'quoteIdentifier'],
			$data['columns']
		);
		if ($data['type'] === Table::CONSTRAINT_FOREIGN) {
			return $prefix . sprintf(
				' FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s',
				implode(', ', $columns),
				$this->_driver->quoteIdentifier($data['references'][0]),
				$this->_driver->quoteIdentifier($data['references'][1]),
				$this->_foreignOnClause($data['update']),
				$this->_foreignOnClause($data['delete'])
			);
		}
		return $prefix . ' (' . implode(', ', $columns) . ')';
	}

/**
 * {@inheritdoc}
 *
 */
	public function createTableSql(Table $table, $columns, $constraints, $indexes) {
		$content = array_merge($columns, $constraints);
		$content = implode(",\n", array_filter($content));
		$tableName = $this->_driver->quoteIdentifier($table->name());
		$out = [];
		$out[] = sprintf("CREATE TABLE %s (\n%s\n)", $tableName, $content);
		foreach ($indexes as $index) {
			$out[] = $index;
		}
		return $out;
	}

/**
 * {@inheritdoc}
 *
 */
	public function truncateTableSql(Table $table) {
		$name = $this->_driver->quoteIdentifier($table->name());
		return [
			sprintf('TRUNCATE TABLE %s', $name)
		];
	}

}
