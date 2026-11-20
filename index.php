<?php
include 'DB.php';

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderResults(array $rows): void
{
    if ($rows === []) {
        echo '<p>No records found.</p>';
        return;
    }

    echo '<table><thead><tr>';
    foreach (array_keys($rows[0]) as $column) {
        echo '<th>' . escapeHtml((string) $column) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . ($value === null ? 'NULL' : escapeHtml((string) $value)) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
}

/**
 * Build a readable copy of a prepared statement with its values filled in,
 * so the page can show the SQL that ran. For display only; never execute it.
 */
function formatSqlForDisplay(string $sql, array $values = []): string
{
    $lines = array_map('trim', explode("\n", $sql));
    $sql = implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));

    return preg_replace_callback('/\?/', static function () use (&$values): string {
        $value = array_shift($values);
        if ($value === null) {
            return 'NULL';
        }
        $value = (string) $value;
        return is_numeric($value) ? $value : "'" . str_replace("'", "''", $value) . "'";
    }, $sql);
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function executeWithValues(mysqli $conn, string $sql, array $values): mysqli_stmt
{
    $statement = mysqli_prepare($conn, $sql);
    if ($values !== []) {
        $types = str_repeat('s', count($values));
        $arguments = [$types];
        foreach ($values as &$value) {
            $arguments[] = &$value;
        }
        call_user_func_array([$statement, 'bind_param'], $arguments);
        unset($value);
    }

    mysqli_stmt_execute($statement);
    return $statement;
}

$tasks = [
    'q1' => 'Find employees hired on or after a date',
    'q2' => 'Insert a row',
    'q3' => 'Update rows',
    'q4' => 'Delete rows',
    'qv1' => 'Create or replace sales view',
    'query_builder' => 'Build a query from SalesEmployeeView',
];

$task = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['task'] ?? '')
    : ($_GET['task'] ?? '');
$task = is_string($task) && isset($tasks[$task]) ? $task : '';
$message = null;
$error = null;
$executedSql = [];
$rows = [];
$dateValue = '';
$tablesResult = mysqli_query(
    $conn,
    "SELECT TABLE_NAME
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME"
);
$tables = array_column(mysqli_fetch_all($tablesResult, MYSQLI_ASSOC), 'TABLE_NAME');
$requestedTable = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['table'] ?? '')
    : ($_GET['table'] ?? '');
$selectedTable = is_string($requestedTable) && in_array($requestedTable, $tables, true)
    ? $requestedTable
    : '';
$tableColumns = [];
$primaryColumns = [];
if ($selectedTable !== '') {
    $columnStatement = executeWithValues(
        $conn,
        'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION',
        [$selectedTable]
    );
    $tableColumns = mysqli_fetch_all(mysqli_stmt_get_result($columnStatement), MYSQLI_ASSOC);
    $primaryColumns = array_values(array_filter(
        $tableColumns,
        static fn ($column): bool => $column['COLUMN_KEY'] === 'PRI'
    ));
}
$rowValues = is_array($_POST['values'] ?? null) ? $_POST['values'] : [];
$changedColumn = is_string($_POST['changed_column'] ?? null) ? $_POST['changed_column'] : '';
$changedValue = is_string($_POST['changed_value'] ?? null) ? trim($_POST['changed_value']) : '';
$conditionColumn = is_string($_POST['condition_column'] ?? null) ? $_POST['condition_column'] : '';
$conditionValue = is_string($_POST['condition_value'] ?? null) ? trim($_POST['condition_value']) : '';
$searchColumn = is_string($_POST['search_column'] ?? null) ? $_POST['search_column'] : '';
$searchOperator = is_string($_POST['search_operator'] ?? null) ? $_POST['search_operator'] : '=';
$searchValue = is_string($_POST['search_value'] ?? null) ? trim($_POST['search_value']) : '';
$searchValue2 = is_string($_POST['search_value2'] ?? null) ? trim($_POST['search_value2']) : '';
$viewExistsResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS ViewCount
     FROM INFORMATION_SCHEMA.VIEWS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'SalesEmployeeView'"
);
$viewExists = (int) mysqli_fetch_assoc($viewExistsResult)['ViewCount'] > 0;
$viewColumns = [];
$selectOptions = [];
$aggregateOptions = [];
$querySelection = [];
$groupSelection = [];

if ($viewExists) {
    $viewColumnsResult = mysqli_query(
        $conn,
        "SELECT COLUMN_NAME, DATA_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'SalesEmployeeView'
         ORDER BY ORDINAL_POSITION"
    );
    $viewColumns = mysqli_fetch_all($viewColumnsResult, MYSQLI_ASSOC);

    foreach ($viewColumns as $column) {
        $columnName = $column['COLUMN_NAME'];
        $quotedColumn = '`' . str_replace('`', '``', $columnName) . '`';
        $key = 'column:' . $columnName;
        $selectOptions[$key] = [
            'sql' => $quotedColumn,
            'order' => $quotedColumn,
            'aggregate' => false,
            'label' => $columnName,
        ];

        foreach (['COUNT', 'MIN', 'MAX'] as $function) {
            $alias = $function . '_' . $columnName;
            $aggregateKey = 'aggregate:' . $function . ':' . $columnName;
            $aggregateOptions[$aggregateKey] = [
                'sql' => $function . '(' . $quotedColumn . ') AS `' . $alias . '`',
                'order' => '`' . $alias . '`',
                'aggregate' => true,
                'label' => $function . '(' . $columnName . ')',
            ];
        }

        if (in_array($column['DATA_TYPE'], [
            'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
            'decimal', 'float', 'double', 'real',
        ], true)) {
            foreach (['SUM', 'AVG'] as $function) {
                $alias = $function . '_' . $columnName;
                $aggregateKey = 'aggregate:' . $function . ':' . $columnName;
                $aggregateOptions[$aggregateKey] = [
                    'sql' => $function . '(' . $quotedColumn . ') AS `' . $alias . '`',
                    'order' => '`' . $alias . '`',
                    'aggregate' => true,
                    'label' => $function . '(' . $columnName . ')',
                ];
            }
        }
    }

    $selectOptions += $aggregateOptions;
}

$whereOperators = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'BETWEEN', 'IS NULL', 'IS NOT NULL'];
$builderWhereColumn = '';
$builderWhereOperator = '=';
$builderWhereValue = '';
$builderWhereValue2 = '';
$builderHavingSelection = '';
$builderHavingOperator = '>';
$builderHavingValue = '';
$builderOrderSelection = '';
$builderOrderDirection = 'ASC';
$builderDistinct = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $task === 'query_builder') {
    $postedSelection = $_POST['select_columns'] ?? [];
    if (is_array($postedSelection)) {
        $querySelection = array_values(array_unique(array_filter(
            $postedSelection,
            static fn ($value): bool => is_string($value) && isset($selectOptions[$value])
        )));
    }

    $postedGroups = $_POST['group_by'] ?? [];
    if (is_array($postedGroups)) {
        $validGroupKeys = array_fill_keys(
            array_map(static fn ($column): string => $column['COLUMN_NAME'], $viewColumns),
            true
        );
        $groupSelection = array_values(array_unique(array_filter(
            $postedGroups,
            static fn ($value): bool => is_string($value) && isset($validGroupKeys[$value])
        )));
    }

    $builderWhereColumn = is_string($_POST['where_column'] ?? null) ? $_POST['where_column'] : '';
    $builderWhereOperator = is_string($_POST['where_operator'] ?? null)
        ? $_POST['where_operator']
        : '=';
    $builderWhereValue = is_string($_POST['where_value'] ?? null) ? trim($_POST['where_value']) : '';
    $builderWhereValue2 = is_string($_POST['where_value2'] ?? null) ? trim($_POST['where_value2']) : '';
    $builderHavingSelection = is_string($_POST['having_aggregate'] ?? null)
        ? $_POST['having_aggregate']
        : '';
    $builderHavingOperator = is_string($_POST['having_operator'] ?? null)
        ? $_POST['having_operator']
        : '>';
    $builderHavingValue = is_string($_POST['having_value'] ?? null)
        ? trim($_POST['having_value'])
        : '';
    $builderOrderSelection = is_string($_POST['order_by'] ?? null) ? $_POST['order_by'] : '';
    $builderOrderDirection = is_string($_POST['order_direction'] ?? null)
        ? strtoupper($_POST['order_direction'])
        : 'ASC';
    $builderDistinct = isset($_POST['distinct']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $task !== '') {
    try {
        switch ($task) {
            case 'query_builder':
                if (!$viewExists) {
                    throw new InvalidArgumentException('Create the sales view before building a query.');
                }
                if ($querySelection === []) {
                    throw new InvalidArgumentException('Select at least one column or aggregate.');
                }

                $selectParts = [];
                $hasAggregate = false;
                $selectedPlainColumns = [];
                foreach ($querySelection as $key) {
                    $option = $selectOptions[$key];
                    $selectParts[] = $option['sql'];
                    if ($option['aggregate']) {
                        $hasAggregate = true;
                    } else {
                        $selectedPlainColumns[] = substr($key, strlen('column:'));
                    }
                }

                if ($hasAggregate || $groupSelection !== []) {
                    foreach ($selectedPlainColumns as $columnName) {
                        if (!in_array($columnName, $groupSelection, true)) {
                            throw new InvalidArgumentException(
                                'When using aggregates or GROUP BY, add every selected regular column to GROUP BY.'
                            );
                        }
                    }
                }

                $query = 'SELECT ' . ($builderDistinct ? 'DISTINCT ' : '')
                    . implode(', ', $selectParts)
                    . ' FROM `SalesEmployeeView`';
                $parameters = [];
                $whereParts = [];

                if ($builderWhereColumn !== '') {
                    $allowedColumns = array_column($viewColumns, 'COLUMN_NAME');
                    if (!in_array($builderWhereColumn, $allowedColumns, true)
                        || !in_array($builderWhereOperator, $whereOperators, true)) {
                        throw new InvalidArgumentException('Choose a valid WHERE column and operator.');
                    }

                    $quotedWhereColumn = '`' . str_replace('`', '``', $builderWhereColumn) . '`';
                    if ($builderWhereOperator === 'IS NULL' || $builderWhereOperator === 'IS NOT NULL') {
                        $whereParts[] = $quotedWhereColumn . ' ' . $builderWhereOperator;
                    } elseif ($builderWhereOperator === 'BETWEEN') {
                        if ($builderWhereValue === '' || $builderWhereValue2 === '') {
                            throw new InvalidArgumentException('Enter both values for the BETWEEN filter.');
                        }
                        $whereParts[] = $quotedWhereColumn . ' BETWEEN ? AND ?';
                        $parameters[] = $builderWhereValue;
                        $parameters[] = $builderWhereValue2;
                    } else {
                        if ($builderWhereValue === '') {
                            throw new InvalidArgumentException('Enter a value for the WHERE filter.');
                        }
                        $whereParts[] = $quotedWhereColumn . ' ' . $builderWhereOperator . ' ?';
                        $parameters[] = $builderWhereValue;
                    }
                }

                if ($whereParts !== []) {
                    $query .= ' WHERE ' . implode(' AND ', $whereParts);
                }

                if ($groupSelection !== []) {
                    $quotedGroupColumns = array_map(
                        static fn ($column): string => '`' . str_replace('`', '``', $column) . '`',
                        $groupSelection
                    );
                    $query .= ' GROUP BY ' . implode(', ', $quotedGroupColumns);
                }

                if ($builderHavingSelection !== '') {
                    if (!isset($aggregateOptions[$builderHavingSelection])
                        || !$aggregateOptions[$builderHavingSelection]['aggregate']
                        || !$hasAggregate
                        || !in_array($builderHavingOperator, ['=', '!=', '>', '>=', '<', '<='], true)
                        || $builderHavingValue === '') {
                        throw new InvalidArgumentException(
                            'HAVING needs a selected aggregate, a valid comparison, and a value.'
                        );
                    }

                    $havingExpression = strstr(
                        $aggregateOptions[$builderHavingSelection]['sql'],
                        ' AS ',
                        true
                    );
                    $query .= ' HAVING ' . $havingExpression . ' '
                        . $builderHavingOperator . ' ?';
                    $parameters[] = $builderHavingValue;
                }

                if ($builderOrderSelection !== '') {
                    if (!in_array($builderOrderSelection, $querySelection, true)
                        || !in_array($builderOrderDirection, ['ASC', 'DESC'], true)) {
                        throw new InvalidArgumentException('Choose a selected result column to sort by.');
                    }
                    $query .= ' ORDER BY ' . $selectOptions[$builderOrderSelection]['order']
                        . ' ' . $builderOrderDirection;
                }

                $statement = mysqli_prepare($conn, $query);
                if ($parameters !== []) {
                    $parameterTypes = str_repeat('s', count($parameters));
                    mysqli_stmt_bind_param($statement, $parameterTypes, ...$parameters);
                }
                mysqli_stmt_execute($statement);
                $rows = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
                $executedSql[] = formatSqlForDisplay($query, $parameters);
                $message = 'Query completed successfully.';
                break;

            case 'q1':
                // Q1: employees hired on or after the entered date, joined to their department.
                $dateValue = is_string($_POST['hire_date'] ?? null) ? trim($_POST['hire_date']) : '';
                $parsedDate = DateTime::createFromFormat('!Y-m-d', $dateValue);
                if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $dateValue) {
                    throw new InvalidArgumentException('Enter a valid hire date (YYYY-MM-DD).');
                }

                $q1Sql = 'SELECT e.Emp_ID AS EmployeeID,
                            CONCAT(e.Fname, \' \', e.Lname) AS FullName,
                            e.Hire_Date AS HireDate,
                            d.Dept_Name AS DepartmentName,
                            d.Floor_No AS FloorNumber
                     FROM EMPLOYEE e
                     LEFT JOIN DEPARTMENT d ON d.Dept_ID = e.Dept_ID
                     WHERE e.Hire_Date >= ?
                     ORDER BY e.Hire_Date, e.Emp_ID';
                $statement = executeWithValues($conn, $q1Sql, [$dateValue]);
                $rows = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
                $executedSql[] = formatSqlForDisplay($q1Sql, [$dateValue]);
                $message = count($rows) . ' employee(s) hired on or after ' . $dateValue . '.';
                break;

            case 'q2':
                if ($selectedTable === '') {
                    throw new InvalidArgumentException('Choose a table before inserting a row.');
                }

                $insertColumns = [];
                $insertValues = [];
                foreach ($tableColumns as $column) {
                    $name = $column['COLUMN_NAME'];
                    if (str_contains($column['EXTRA'], 'auto_increment')) {
                        continue;
                    }

                    $value = is_string($rowValues[$name] ?? null) ? trim($rowValues[$name]) : '';
                    if ($value === '') {
                        if ($column['COLUMN_DEFAULT'] !== null) {
                            continue;
                        }
                        if ($column['IS_NULLABLE'] === 'YES') {
                            $value = null;
                        } else {
                            throw new InvalidArgumentException($name . ' is required.');
                        }
                    }

                    $insertColumns[] = quoteIdentifier($name);
                    $insertValues[] = $value;
                }

                if ($insertColumns === []) {
                    $insertSql = 'INSERT INTO ' . quoteIdentifier($selectedTable) . ' () VALUES ()';
                } else {
                    $placeholders = implode(', ', array_fill(0, count($insertValues), '?'));
                    $insertSql = 'INSERT INTO ' . quoteIdentifier($selectedTable)
                        . ' (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';
                }
                $insertStatement = executeWithValues($conn, $insertSql, $insertValues);
                $executedSql[] = formatSqlForDisplay($insertSql, $insertValues);
                $message = 'Row inserted into ' . $selectedTable . '.';

                if ($primaryColumns !== []) {
                    $primaryValues = [];
                    $canFetchInserted = true;
                    foreach ($primaryColumns as $column) {
                        $name = $column['COLUMN_NAME'];
                        if (str_contains($column['EXTRA'], 'auto_increment')) {
                            $primaryValues[] = (string) mysqli_insert_id($conn);
                        } elseif (isset($rowValues[$name]) && trim((string) $rowValues[$name]) !== '') {
                            $primaryValues[] = trim((string) $rowValues[$name]);
                        } else {
                            $canFetchInserted = false;
                            break;
                        }
                    }
                    if ($canFetchInserted) {
                        $where = implode(' AND ', array_map(
                            static fn ($column): string => quoteIdentifier($column['COLUMN_NAME']) . ' = ?',
                            $primaryColumns
                        ));
                        $insertedSelectSql = 'SELECT * FROM ' . quoteIdentifier($selectedTable) . ' WHERE ' . $where;
                        $selectStatement = executeWithValues($conn, $insertedSelectSql, $primaryValues);
                        $rows = mysqli_fetch_all(mysqli_stmt_get_result($selectStatement), MYSQLI_ASSOC);
                        $executedSql[] = formatSqlForDisplay($insertedSelectSql, $primaryValues);
                    }
                }
                break;

            case 'q3':
                if ($selectedTable === '') {
                    throw new InvalidArgumentException('Choose a table before updating rows.');
                }
                if (!in_array($changedColumn, array_column($tableColumns, 'COLUMN_NAME'), true)) {
                    throw new InvalidArgumentException('Choose a column to update.');
                }
                if (!in_array($conditionColumn, array_column($tableColumns, 'COLUMN_NAME'), true)
                    || $conditionValue === '') {
                    throw new InvalidArgumentException('Choose a condition column and enter its matching value.');
                }

                $updateSql = 'UPDATE ' . quoteIdentifier($selectedTable)
                    . ' SET ' . quoteIdentifier($changedColumn) . ' = ?'
                    . ' WHERE ' . quoteIdentifier($conditionColumn) . ' = ?';
                $updateValues = [$changedValue, $conditionValue];
                $updateStatement = executeWithValues($conn, $updateSql, $updateValues);
                $executedSql[] = formatSqlForDisplay($updateSql, $updateValues);
                $affectedRows = mysqli_stmt_affected_rows($updateStatement);
                $message = $affectedRows === 0
                    ? 'No matching rows were changed.'
                    : $affectedRows . ' row(s) updated.';
                $updatedSelectSql = 'SELECT * FROM ' . quoteIdentifier($selectedTable)
                    . ' WHERE ' . quoteIdentifier($conditionColumn) . ' = ?';
                $updatedSelectValues = [$changedColumn === $conditionColumn ? $changedValue : $conditionValue];
                $selectStatement = executeWithValues($conn, $updatedSelectSql, $updatedSelectValues);
                $rows = mysqli_fetch_all(mysqli_stmt_get_result($selectStatement), MYSQLI_ASSOC);
                $executedSql[] = formatSqlForDisplay($updatedSelectSql, $updatedSelectValues);
                break;

            case 'q4':
                if ($selectedTable === '') {
                    throw new InvalidArgumentException('Choose a table before deleting rows.');
                }
                if (!in_array($conditionColumn, array_column($tableColumns, 'COLUMN_NAME'), true)
                    || $conditionValue === '') {
                    throw new InvalidArgumentException('Choose a condition column and enter its matching value.');
                }
                if (($_POST['confirm_delete'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('Confirm the deletion before continuing.');
                }

                $deleteSql = 'DELETE FROM ' . quoteIdentifier($selectedTable)
                    . ' WHERE ' . quoteIdentifier($conditionColumn) . ' = ?';
                $deleteStatement = executeWithValues($conn, $deleteSql, [$conditionValue]);
                $executedSql[] = formatSqlForDisplay($deleteSql, [$conditionValue]);
                $affectedRows = mysqli_stmt_affected_rows($deleteStatement);
                $message = $affectedRows === 0
                    ? 'No matching rows found; nothing was deleted.'
                    : $affectedRows . ' row(s) deleted from ' . $selectedTable . '.';
                break;

            case 'qv1':
                $viewSql = 'CREATE OR REPLACE VIEW SalesEmployeeView AS
                     SELECT e.Emp_ID AS EmpID,
                            CONCAT_WS(\' \', e.Fname, e.Lname) AS EmployeeName,
                            d.Dept_Name AS DepartmentName,
                            sh.Brand AS Brand,
                            sh.Model AS Model,
                            sh.Price AS ShoePrice,
                            CONCAT_WS(\' \', c.Fname, c.Lname) AS CustomerName,
                            sr.Date1 AS SaleDate,
                            sr.SoldQty AS SoldQty
                     FROM SALE_REC sr
                     JOIN EMPLOYEE e ON e.Emp_ID = sr.Emp_ID
                     LEFT JOIN DEPARTMENT d ON d.Dept_ID = e.Dept_ID
                     JOIN SHOE sh ON sh.Shoe_ID = sr.Shoe_ID
                     JOIN CUSTOMER c ON c.Customer_ID = sr.Customer_ID
                     ORDER BY EmployeeName';
                mysqli_query($conn, $viewSql);
                $executedSql[] = formatSqlForDisplay($viewSql);
                $message = 'SalesEmployeeView was created or replaced successfully.';
                break;

        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (mysqli_sql_exception $exception) {
        $error = 'Database operation failed: ' . $exception->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shoe Store Database Tools</title>
    <style>
        body { font-family: sans-serif; margin: 2rem auto; max-width: 1000px; padding: 0 1rem; }
        label { display: block; margin-top: 1rem; }
        input, select, button { font: inherit; margin-top: 0.35rem; padding: 0.45rem; }
        select[multiple] { min-width: 18rem; }
        .query-builder { border: 1px solid #999; margin-top: 1rem; padding: 1rem; }
        table { border-collapse: collapse; margin-top: 1rem; width: 100%; }
        th, td { border: 1px solid #999; padding: 0.5rem; text-align: left; }
        th { background: #eee; }
        .message { color: #155724; }
        .error { color: #9b1c1c; }
        .sql { background: #f6f6f6; border: 1px solid #999; margin-top: 1rem; padding: 0.75rem; white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>Shoe Store Database Tools</h1>

    <form method="get">
        <label for="task">Choose a function</label>
        <select id="task" name="task" onchange="this.form.submit()">
            <option value="">Choose a function</option>
            <?php foreach ($tasks as $taskId => $taskLabel): ?>
                <option value="<?= escapeHtml($taskId) ?>" <?= $task === $taskId ? 'selected' : '' ?>>
                    <?= escapeHtml($taskLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Choose task</button></noscript>
    </form>

    <?php if (in_array($task, ['q2', 'q3', 'q4'], true)): ?>
        <form method="get">
            <input type="hidden" name="task" value="<?= escapeHtml($task) ?>">
            <label for="table">Table</label>
            <select id="table" name="table" onchange="this.form.submit()" required>
                <option value="">Choose a table</option>
                <?php foreach ($tables as $table): ?>
                    <option value="<?= escapeHtml($table) ?>"
                        <?= $selectedTable === $table ? 'selected' : '' ?>>
                        <?= escapeHtml($table) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit">Load columns</button></noscript>
        </form>
    <?php endif; ?>

    <?php if ($task !== ''): ?>
        <form method="post">
            <input type="hidden" name="task" value="<?= escapeHtml($task) ?>">
            <input type="hidden" name="table" value="<?= escapeHtml($selectedTable) ?>">

            <?php if ($task === 'q1'): ?>
                <label for="hire_date">Show employees hired on or after</label>
                <input type="date" id="hire_date" name="hire_date"
                    value="<?= escapeHtml($dateValue) ?>" required>
                <button type="submit">Find employees</button>
            <?php elseif ($task === 'q2'): ?>
                <?php if ($selectedTable === ''): ?>
                    <p>Choose a table above to load its columns.</p>
                <?php else: ?>
                    <p>Enter values for the new row. Leave optional/defaulted fields blank to use their database value.</p>
                    <?php foreach ($tableColumns as $column): ?>
                        <?php if (str_contains($column['EXTRA'], 'auto_increment')): ?>
                            <p><?= escapeHtml($column['COLUMN_NAME']) ?> will be generated automatically.</p>
                        <?php else: ?>
                            <label for="insert_<?= escapeHtml($column['COLUMN_NAME']) ?>">
                                <?= escapeHtml($column['COLUMN_NAME']) ?>
                                <?= $column['IS_NULLABLE'] === 'YES' || $column['COLUMN_DEFAULT'] !== null
                                    ? '(optional)' : '(required)' ?>
                            </label>
                            <input
                                id="insert_<?= escapeHtml($column['COLUMN_NAME']) ?>"
                                name="values[<?= escapeHtml($column['COLUMN_NAME']) ?>]"
                                value="<?= escapeHtml(is_string($rowValues[$column['COLUMN_NAME']] ?? null)
                                    ? $rowValues[$column['COLUMN_NAME']] : '') ?>"
                                <?= $column['IS_NULLABLE'] !== 'YES' && $column['COLUMN_DEFAULT'] === null
                                    ? 'required' : '' ?>
                            >
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <button type="submit">Insert row</button>
                <?php endif; ?>
            <?php elseif ($task === 'q3'): ?>
                <?php if ($selectedTable === ''): ?>
                    <p>Choose a table above to load its columns.</p>
                <?php else: ?>
                    <label for="changed_column">Column to change</label>
                    <select id="changed_column" name="changed_column" required>
                        <?php foreach ($tableColumns as $column): ?>
                            <option value="<?= escapeHtml($column['COLUMN_NAME']) ?>"
                                <?= $changedColumn === $column['COLUMN_NAME'] ? 'selected' : '' ?>>
                                <?= escapeHtml($column['COLUMN_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="changed_value">New value</label>
                    <input id="changed_value" name="changed_value" value="<?= escapeHtml($changedValue) ?>" required>
                    <label for="condition_column">Match rows where this column equals</label>
                    <select id="condition_column" name="condition_column" required>
                        <?php foreach ($tableColumns as $column): ?>
                            <option value="<?= escapeHtml($column['COLUMN_NAME']) ?>"
                                <?= $conditionColumn === $column['COLUMN_NAME'] ? 'selected' : '' ?>>
                                <?= escapeHtml($column['COLUMN_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input name="condition_value" aria-label="Matching value" value="<?= escapeHtml($conditionValue) ?>" required>
                    <button type="submit">Update matching rows</button>
                <?php endif; ?>
            <?php elseif ($task === 'q4'): ?>
                <?php if ($selectedTable === ''): ?>
                    <p>Choose a table above to load its columns.</p>
                <?php else: ?>
                    <p>Only rows matching this exact value will be deleted.</p>
                    <label for="delete_condition_column">Delete rows where this column equals</label>
                    <select id="delete_condition_column" name="condition_column" required>
                        <?php foreach ($tableColumns as $column): ?>
                            <option value="<?= escapeHtml($column['COLUMN_NAME']) ?>"
                                <?= $conditionColumn === $column['COLUMN_NAME'] ? 'selected' : '' ?>>
                                <?= escapeHtml($column['COLUMN_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="delete_condition_value">Matching value</label>
                    <input id="delete_condition_value" name="condition_value"
                        value="<?= escapeHtml($conditionValue) ?>" required>
                    <label>
                    <input type="checkbox" name="confirm_delete" value="yes" required>
                    I confirm deleting all matching rows from <?= escapeHtml($selectedTable) ?>.
                    </label>
                    <button type="submit">Delete matching rows</button>
                <?php endif; ?>
            <?php elseif ($task === 'qv1'): ?>
                <p>Create or replace the SalesEmployeeView database view.</p>
                <button type="submit">Create view</button>
            <?php elseif ($task === 'query_builder'): ?>
                <?php if (!$viewExists): ?>
                    <p>Create the sales view first. Its columns will then appear in the query builder.</p>
                <?php else: ?>
                    <p>Select one or more view columns. Add filters, grouping, aggregate conditions, or sorting as needed.</p>
                    <div class="query-builder">
                        <label for="select_columns">SELECT (choose multiple with Command-click on Mac)</label>
                        <select id="select_columns" name="select_columns[]" multiple size="9" required>
                            <?php foreach ($selectOptions as $key => $option): ?>
                                <option value="<?= escapeHtml($key) ?>"
                                    <?= in_array($key, $querySelection, true) ? 'selected' : '' ?>>
                                    <?= escapeHtml($option['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="from_view">FROM</label>
                        <input id="from_view" type="text" value="SalesEmployeeView" readonly>

                        <label for="where_column">WHERE column (optional)</label>
                        <select id="where_column" name="where_column">
                            <option value="">No filter</option>
                            <?php foreach ($viewColumns as $column): ?>
                                <option value="<?= escapeHtml($column['COLUMN_NAME']) ?>"
                                    <?= $builderWhereColumn === $column['COLUMN_NAME'] ? 'selected' : '' ?>>
                                    <?= escapeHtml($column['COLUMN_NAME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="where_operator">WHERE comparison</label>
                        <select id="where_operator" name="where_operator">
                            <?php foreach ($whereOperators as $operator): ?>
                                <option value="<?= escapeHtml($operator) ?>"
                                    <?= $builderWhereOperator === $operator ? 'selected' : '' ?>>
                                    <?= escapeHtml($operator) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="where_value">WHERE value</label>
                        <input id="where_value" name="where_value" value="<?= escapeHtml($builderWhereValue) ?>">
                        <label for="where_value2">Second value (BETWEEN only)</label>
                        <input id="where_value2" name="where_value2" value="<?= escapeHtml($builderWhereValue2) ?>">

                        <label for="group_by">GROUP BY (optional; choose multiple if needed)</label>
                        <select id="group_by" name="group_by[]" multiple size="5">
                            <?php foreach ($viewColumns as $column): ?>
                                <option value="<?= escapeHtml($column['COLUMN_NAME']) ?>"
                                    <?= in_array($column['COLUMN_NAME'], $groupSelection, true) ? 'selected' : '' ?>>
                                    <?= escapeHtml($column['COLUMN_NAME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="having_aggregate">HAVING aggregate (optional)</label>
                        <select id="having_aggregate" name="having_aggregate">
                            <option value="">No HAVING condition</option>
                            <?php foreach ($aggregateOptions as $key => $option): ?>
                                <option value="<?= escapeHtml($key) ?>"
                                    <?= $builderHavingSelection === $key ? 'selected' : '' ?>>
                                    <?= escapeHtml($option['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="having_operator">HAVING comparison</label>
                        <select id="having_operator" name="having_operator">
                            <?php foreach (['=', '!=', '>', '>=', '<', '<='] as $operator): ?>
                                <option value="<?= escapeHtml($operator) ?>"
                                    <?= $builderHavingOperator === $operator ? 'selected' : '' ?>>
                                    <?= escapeHtml($operator) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="having_value">HAVING value</label>
                        <input id="having_value" name="having_value" value="<?= escapeHtml($builderHavingValue) ?>">

                        <label for="order_by">ORDER BY selected column (optional)</label>
                        <select id="order_by" name="order_by">
                            <option value="">No sorting</option>
                            <?php foreach ($querySelection as $key): ?>
                                <option value="<?= escapeHtml($key) ?>"
                                    <?= $builderOrderSelection === $key ? 'selected' : '' ?>>
                                    <?= escapeHtml($selectOptions[$key]['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="order_direction">Sort direction</label>
                        <select id="order_direction" name="order_direction">
                            <option value="ASC" <?= $builderOrderDirection === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $builderOrderDirection === 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>

                        <label>
                            <input type="checkbox" name="distinct" value="1" <?= $builderDistinct ? 'checked' : '' ?>>
                            Return distinct rows
                        </label>
                    </div>
                    <button type="submit">Run query</button>
                <?php endif; ?>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($message !== null): ?>
        <p class="message" role="status"><?= escapeHtml($message) ?></p>
    <?php endif; ?>
    <?php if ($executedSql !== [] && $error === null): ?>
        <p><strong>SQL executed:</strong></p>
        <pre class="sql"><?= escapeHtml(implode(";\n\n", $executedSql) . ';') ?></pre>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <p class="error" role="alert"><?= escapeHtml($error) ?></p>
    <?php endif; ?>
    <?php if ($rows !== [] || in_array($task, ['q1', 'q2', 'q3'], true)
        && $_SERVER['REQUEST_METHOD'] === 'POST' && $error === null): ?>
        <?php renderResults($rows); ?>
    <?php endif; ?>

    <?php if ($task === 'query_builder' && $viewExists): ?>
        <script>
            const selectColumns = document.getElementById('select_columns');
            const orderBy = document.getElementById('order_by');

            selectColumns.addEventListener('change', () => {
                const previousValue = orderBy.value;
                orderBy.replaceChildren(new Option('No sorting', ''));

                for (const selected of selectColumns.selectedOptions) {
                    orderBy.add(new Option(selected.text, selected.value));
                }

                if ([...orderBy.options].some(option => option.value === previousValue)) {
                    orderBy.value = previousValue;
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
