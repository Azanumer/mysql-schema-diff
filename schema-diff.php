<?php
/**
 * mysql-schema-diff — compare two MySQL/MariaDB schemas and emit the
 * ALTER/CREATE statements that bring the target in line with the source.
 *
 * Compares tables, columns, indexes, engine and collation — never the
 * AUTO_INCREMENT counter, so a busy table does not produce a false diff.
 * Drops are NEVER emitted unless --allow-drops is passed.
 *
 * Usage:
 *   php schema-diff.php \
 *     --source-dsn="mysql:host=127.0.0.1;dbname=app_prod" --source-user=root \
 *     --target-dsn="mysql:host=127.0.0.1;dbname=app_stage" --target-user=root \
 *     [--source-pass=...] [--target-pass=...] [--allow-drops]
 *     [--format=sql|text] [--output=diff.sql]
 *
 * Exit codes: 0 = schemas identical, 1 = differences found, 2 = usage/connection error.
 *
 * @author Azan Umer
 * @license MIT
 */

declare(strict_types=1);

function usage(?string $error = null): void
{
    if (null !== $error) {
        fwrite(STDERR, "Error: {$error}\n\n");
    }
    fwrite(STDERR, <<<USAGE
Usage: php schema-diff.php --source-dsn=DSN --source-user=USER --target-dsn=DSN --target-user=USER [options]

Required:
  --source-dsn=DSN     PDO DSN of the reference schema, e.g. mysql:host=127.0.0.1;dbname=app_prod
  --source-user=USER    User for the source connection
  --target-dsn=DSN     PDO DSN of the schema to fix
  --target-user=USER    User for the target connection

Options:
  --source-pass=PASS   Password for the source (default: empty)
  --target-pass=PASS   Password for the target (default: empty)
  --allow-drops        Also emit DROP TABLE / DROP COLUMN / DROP INDEX (default: report only)
  --format=sql|text    sql emits runnable statements (default), text emits a human summary
  --output=FILE        Write output to FILE instead of stdout
  --help               Show this help

USAGE);
    exit(2);
}

/** Parse --key=value / --flag arguments into an array. */
function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ('--help' === $arg || '-h' === $arg) {
            usage();
        }
        if (0 === strpos($arg, '--')) {
            $pair = substr($arg, 2);
            $eq = strpos($pair, '=');
            if (false === $eq) {
                $args[$pair] = true;
            } else {
                $args[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
        } else {
            usage("Unexpected argument: {$arg}");
        }
    }
    return $args;
}

/** Quote an identifier the MySQL way (backticks, doubled backticks inside). */
function ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

$args = parseArgs($argv);

foreach (['source-dsn', 'source-user', 'target-dsn', 'target-user'] as $required) {
    if (empty($args[$required]) || !is_string($args[$required])) {
        usage("Missing required option --{$required}");
    }
}
$format = $args['format'] ?? 'sql';
if (!in_array($format, ['sql', 'text'], true)) {
    usage('--format must be sql or text');
}
$allowDrops = !empty($args['allow-drops']);

try {
    $source = new PDO($args['source-dsn'], $args['source-user'], $args['source-pass'] ?? '');
    $target = new PDO($args['target-dsn'], $args['target-user'], $args['target-pass'] ?? '');
    foreach ([$source, $target] as $pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'Connection failed: ' . $e->getMessage() . "\n");
    exit(2);
}

/**
 * Read a schema: tables with columns, indexes, engine and collation.
 *
 * @return array<string, array{table:string, columns:array, indexes:array, engine:?string, collation:?string}>
 */
function readSchema(PDO $pdo): array
{
    $schema = [];
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table]) {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . ident($table))->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columns[$col['Field']] = [
                'type'    => strtolower((string) $col['Type']),
                'null'    => 'YES' === strtoupper((string) $col['Null']),
                'default' => $col['Default'], // null, string, or numeric string
                'extra'   => strtolower((string) $col['Extra']),
            ];
        }

        $indexes = [];
        foreach ($pdo->query('SHOW INDEX FROM ' . ident($table))->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $name = (string) $idx['Key_name'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'unique' => '0' === (string) $idx['Non_unique'],
                    'type'   => strtoupper((string) $idx['Index_type']),
                    'columns' => [],
                ];
            }
            $indexes[$name]['columns'][(int) $idx['Seq_in_index']] = (string) $idx['Column_name'];
        }
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        $status = $pdo->query('SHOW TABLE STATUS LIKE ' . $pdo->quote($table))->fetch(PDO::FETCH_ASSOC);
        $schema[$table] = [
            'table'     => $table,
            'columns'   => $columns,
            'indexes'   => $indexes,
            'engine'    => isset($status['Engine']) ? strtolower((string) $status['Engine']) : null,
            'collation' => isset($status['Collation']) ? strtolower((string) $status['Collation']) : null,
        ];
    }
    return $schema;
}

/** Are two column definitions the same, ignoring derived/volatile bits? */
function columnsEqual(array $a, array $b): bool
{
    if ($a['type'] !== $b['type'] || $a['null'] !== $b['null']) {
        return false;
    }
    // SHOW COLUMNS reports Default NULL both as SQL NULL and as the string
    // 'NULL' on some servers; treat them as the same.
    $da = null === $a['default'] || 'NULL' === strtoupper((string) $a['default']) ? null : (string) $a['default'];
    $db = null === $b['default'] || 'NULL' === strtoupper((string) $b['default']) ? null : (string) $b['default'];
    if ($da !== $db) {
        return false;
    }
    // Extra can carry 'auto_increment' and/or 'on update CURRENT_TIMESTAMP'.
    // Compare as sorted token sets so ordering never causes a false diff.
    $ea = array_filter(explode(' ', str_replace('on update', 'on_update', $a['extra'])));
    $eb = array_filter(explode(' ', str_replace('on update', 'on_update', $b['extra'])));
    sort($ea);
    sort($eb);
    return $ea === $eb;
}

/** Build a column definition fragment for ADD/MODIFY COLUMN. */
function columnDefinition(PDO $pdo, string $name, array $col): string
{
    $def = ident($name) . ' ' . $col['type'];
    $def .= $col['null'] ? ' NULL' : ' NOT NULL';
    if (null !== $col['default']) {
        $d = (string) $col['default'];
        if (is_numeric($d) || 'CURRENT_TIMESTAMP' === strtoupper($d) || 'NULL' === strtoupper($d)) {
            $def .= ' DEFAULT ' . strtoupper($d);
        } else {
            $def .= ' DEFAULT ' . $pdo->quote($d);
        }
    } elseif ($col['null']) {
        $def .= ' DEFAULT NULL';
    }
    if (false !== strpos($col['extra'], 'auto_increment')) {
        $def .= ' AUTO_INCREMENT';
    }
    if (false !== strpos($col['extra'], 'on update current_timestamp')) {
        $def .= ' ON UPDATE CURRENT_TIMESTAMP';
    }
    return $def;
}

/** Build an index definition fragment for ADD INDEX. */
function indexDefinition(string $name, array $index): string
{
    $cols = implode(', ', array_map('ident', $index['columns']));
    if ('PRIMARY' === $name) {
        return "ADD PRIMARY KEY ({$cols})";
    }
    $kind = '';
    if ($index['unique']) {
        $kind = 'UNIQUE ';
    } elseif ('FULLTEXT' === $index['type']) {
        $kind = 'FULLTEXT ';
    } elseif ('SPATIAL' === $index['type']) {
        $kind = 'SPATIAL ';
    }
    $using = ('BTREE' !== $index['type'] && 'FULLTEXT' !== $index['type'] && 'SPATIAL' !== $index['type'])
        ? ' USING ' . $index['type'] : '';
    return "ADD {$kind}INDEX " . ident($name) . " ({$cols}){$using}";
}

$sourceSchema = readSchema($source);
$targetSchema = readSchema($target);

$statements = []; // Runnable SQL lines (or text lines in text mode).
$summary    = []; // Human-readable change list, always collected.
$diffCount  = 0;

$add = function (string $sql, string $text) use (&$statements, &$summary, &$diffCount, $format): void {
    $diffCount++;
    $summary[] = ['text' => $text, 'sql' => true];
    if ('sql' === $format) {
        $statements[] = $sql;
    }
};

/** Record a difference that has no runnable statement (a skipped drop). */
$note = function (string $text) use (&$summary, &$diffCount): void {
    $diffCount++;
    $summary[] = ['text' => $text, 'sql' => false];
};

// --- Tables only in the source: CREATE them on the target. -----------------
foreach ($sourceSchema as $table => $def) {
    if (isset($targetSchema[$table])) {
        continue;
    }
    $create = $source->query('SHOW CREATE TABLE ' . ident($table))->fetch(PDO::FETCH_ASSOC);
    $sql = rtrim((string) $create['Create Table']) . ';';
    $add($sql, "Table {$table} exists only in source — CREATE it on target.");
}

// --- Tables only in the target: DROP them only when allowed. ---------------
foreach ($targetSchema as $table => $def) {
    if (isset($sourceSchema[$table])) {
        continue;
    }
    if ($allowDrops) {
        $add('DROP TABLE ' . ident($table) . ';', "Table {$table} exists only in target — DROP it.");
    } else {
        $note("Table {$table} exists only in target — skipped (re-run with --allow-drops to drop it).");
    }
}

// --- Tables in both: columns, indexes, engine, collation. ------------------
foreach ($sourceSchema as $table => $sdef) {
    if (!isset($targetSchema[$table])) {
        continue;
    }
    $tdef = $targetSchema[$table];

    // Columns.
    foreach ($sdef['columns'] as $name => $col) {
        if (!isset($tdef['columns'][$name])) {
            $add(
                'ALTER TABLE ' . ident($table) . ' ADD COLUMN ' . columnDefinition($source, $name, $col) . ';',
                "Table {$table}: ADD COLUMN {$name}."
            );
        } elseif (!columnsEqual($col, $tdef['columns'][$name])) {
            $add(
                'ALTER TABLE ' . ident($table) . ' MODIFY COLUMN ' . columnDefinition($source, $name, $col) . ';',
                "Table {$table}: MODIFY COLUMN {$name} (type/null/default/extra differs)."
            );
        }
    }
    foreach ($tdef['columns'] as $name => $col) {
        if (isset($sdef['columns'][$name])) {
            continue;
        }
        if ($allowDrops) {
            $add(
                'ALTER TABLE ' . ident($table) . ' DROP COLUMN ' . ident($name) . ';',
                "Table {$table}: DROP COLUMN {$name}."
            );
        } else {
            $note("Table {$table}: column {$name} exists only in target — skipped (re-run with --allow-drops to drop it).");
        }
    }

    // Indexes (PRIMARY included; Key_name 'PRIMARY').
    foreach ($sdef['indexes'] as $name => $index) {
        if (!isset($tdef['indexes'][$name])) {
            $add(
                'ALTER TABLE ' . ident($table) . ' ' . indexDefinition($name, $index) . ';',
                "Table {$table}: ADD INDEX {$name} (" . implode(', ', $index['columns']) . ').'
            );
        } elseif ($index !== $tdef['indexes'][$name]) {
            // Replace: drop first, then add (PRIMARY needs its own syntax).
            if ($allowDrops || 'PRIMARY' === $name) {
                $drop = 'PRIMARY' === $name
                    ? 'ALTER TABLE ' . ident($table) . ' DROP PRIMARY KEY;'
                    : 'ALTER TABLE ' . ident($table) . ' DROP INDEX ' . ident($name) . ';';
                $add($drop, "Table {$table}: DROP INDEX {$name} (definition differs, will be re-added).");
                $add(
                    'ALTER TABLE ' . ident($table) . ' ' . indexDefinition($name, $index) . ';',
                    "Table {$table}: ADD INDEX {$name} (" . implode(', ', $index['columns']) . ').'
                );
            } else {
                $note("Table {$table}: index {$name} differs — skipped (re-run with --allow-drops to replace it).");
            }
        }
    }
    foreach ($tdef['indexes'] as $name => $index) {
        if (isset($sdef['indexes'][$name])) {
            continue;
        }
        if ($allowDrops) {
            $drop = 'PRIMARY' === $name
                ? 'ALTER TABLE ' . ident($table) . ' DROP PRIMARY KEY;'
                : 'ALTER TABLE ' . ident($table) . ' DROP INDEX ' . ident($name) . ';';
            $add($drop, "Table {$table}: DROP INDEX {$name}.");
        } else {
            $note("Table {$table}: index {$name} exists only in target — skipped (re-run with --allow-drops to drop it).");
        }
    }

    // Engine.
    if (null !== $sdef['engine'] && null !== $tdef['engine'] && $sdef['engine'] !== $tdef['engine']) {
        $add(
            'ALTER TABLE ' . ident($table) . ' ENGINE = ' . $sdef['engine'] . ';',
            "Table {$table}: engine is {$tdef['engine']}, source uses {$sdef['engine']}."
        );
    }

    // Collation (charset derived from the collation name).
    if (null !== $sdef['collation'] && null !== $tdef['collation'] && $sdef['collation'] !== $tdef['collation']) {
        $charset = explode('_', $sdef['collation'])[0];
        $add(
            'ALTER TABLE ' . ident($table) . ' CONVERT TO CHARACTER SET ' . $charset . ' COLLATE ' . $sdef['collation'] . ';',
            "Table {$table}: collation is {$tdef['collation']}, source uses {$sdef['collation']}."
        );
    }
}

// --- Output -----------------------------------------------------------------
$out = fopen($args['output'] ?? 'php://stdout', 'wb');
if (false === $out) {
    fwrite(STDERR, 'Cannot open output file: ' . ($args['output'] ?? '') . "\n");
    exit(2);
}

if (0 === $diffCount) {
    fwrite($out, 'sql' === $format
        ? "-- Schemas are identical.\n"
        : "Schemas are identical.\n");
    fclose($out);
    exit(0);
}

if ('sql' === $format) {
    fwrite($out, '-- mysql-schema-diff: ' . $diffCount . " difference(s). Review before applying.\n");
    fwrite($out, '-- Source: ' . $args['source-dsn'] . "\n");
    fwrite($out, '-- Target: ' . $args['target-dsn'] . "\n\n");
    foreach ($statements as $sql) {
        fwrite($out, $sql . "\n");
    }
    // Non-runnable notes (skipped drops) go in as comments so nothing is lost.
    foreach ($summary as $entry) {
        if (!$entry['sql']) {
            fwrite($out, '-- NOTE: ' . $entry['text'] . "\n");
        }
    }
} else {
    fwrite($out, $diffCount . " difference(s):\n");
    foreach ($summary as $entry) {
        fwrite($out, '- ' . $entry['text'] . "\n");
    }
}
fclose($out);
exit(1);
