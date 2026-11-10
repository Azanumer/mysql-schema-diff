# MySQL Schema Diff

Compare two MySQL/MariaDB schemas and emit the `ALTER`/`CREATE` statements that bring the target in line with the source. Built for the deploy workflow every WordPress host knows: staging drifted from production, or a plugin update changed a table on one server and not the other.

## Usage

```bash
php schema-diff.php \
  --source-dsn="mysql:host=127.0.0.1;dbname=app_prod" --source-user=root \
  --target-dsn="mysql:host=127.0.0.1;dbname=app_stage" --target-user=root
```

## What it compares

- **Tables** — missing on the target get a full `CREATE TABLE`; extra tables are reported, not dropped, unless `--allow-drops` is passed.
- **Columns** — type, nullability, default and extras (`AUTO_INCREMENT`, `ON UPDATE CURRENT_TIMESTAMP`). Missing columns are added, changed ones are modified, extra ones are dropped only with `--allow-drops`.
- **Indexes** — including `PRIMARY KEY`, `UNIQUE`, `FULLTEXT` and `SPATIAL`. A changed index is dropped and re-added (with `--allow-drops`, except `PRIMARY KEY`, which is always safe to replace).
- **Engine and collation** — emitted as `ALTER TABLE ... ENGINE = ...` and `CONVERT TO CHARACTER SET ... COLLATE ...`.

## What it deliberately ignores

- The `AUTO_INCREMENT` counter — a busy table would otherwise produce a false diff on every run.
- Views — only base tables are compared.
- Data — this is a schema tool. Rows are never touched.

## Options

| Option | Description |
|---|---|
| `--source-dsn`, `--source-user`, `--source-pass` | Reference schema (the one that is right). |
| `--target-dsn`, `--target-user`, `--target-pass` | Schema to fix. |
| `--allow-drops` | Also emit `DROP TABLE` / `DROP COLUMN` / `DROP INDEX`. Without it, drops are reported as comments/notes only. |
| `--format=sql\|text` | `sql` (default) emits runnable statements; `text` emits a human-readable summary. |
| `--output=FILE` | Write to a file instead of stdout. |

## Exit codes

| Code | Meaning |
|---|---|
| 0 | Schemas are identical. |
| 1 | Differences found (and emitted). |
| 2 | Usage error or connection failure. |

## Safety

Drops are opt-in (`--allow-drops`), and the SQL output starts with a "Review before applying" comment. The intended workflow is: run it, read the diff, then pipe it into `mysql` yourself — the tool never applies anything on its own.

## License

MIT — see [LICENSE](LICENSE).
