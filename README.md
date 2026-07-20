# Adminer Parquet driver (read-only)

A database driver/plugin that adds [Apache Parquet](https://parquet.apache.org)
support to [Adminer](https://www.adminer.org) 5.x, built against Adminer's
[extension/driver API](https://www.adminer.org/en/extension/).

Parquet is a columnar on-disk file format, not a database server. This driver
lets you **browse and read** Parquet files through the normal Adminer UI: list
files as tables, inspect their schema, and run `SELECT` with search, sort, and
paging. It is **read-only** — Parquet files are treated as immutable analytical
data.

It is backed by the pure-PHP reader [`flow-php/parquet`](https://github.com/flow-php/parquet),
so there is **no native library, FFI, or PDO driver** to install — just PHP with
`ext-bcmath` and `ext-zlib`.

## How it works

Adminer's driver API expects three classes in the `Adminer` namespace plus a set
of free functions (see `docs/reference-driver.inc.php` and
`docs/reference-sqlite.inc.php`, kept locally for reference):

| Class / API      | Role |
|------------------|------|
| `Db`             | "connection" wrapper implementing `SqlDb`; validates and remembers the target path |
| `Result`         | result-set wrapper (`fetch_assoc`, `fetch_row`, `fetch_field`, `seek`, `num_rows`) over materialized rows |
| `Driver`         | extends `SqlDriver`; overrides `select()` to read rows and apply WHERE/ORDER/LIMIT in PHP; declares types via `support()` |
| free functions   | schema introspection: `tables_list`, `fields`, `table_status`, `create_sql`, … |

Because there is no SQL engine behind Parquet, `Driver::select()` does not build a
SQL string. It reads rows directly from the file via `flow-php/parquet`
(`Reader::php()->read($file)->values($columns, $limit, $offset)`) and applies
Adminer's `WHERE` / `ORDER BY` / `LIMIT` / paging in PHP. Total row counts come
straight from the Parquet footer metadata (`->metadata()->rowsNumber()`), so they
are cheap even for large files.

### Model mapping

A Parquet file is a single flat table. Adminer's world is
server → database → schema → table, mapped as:

| Adminer concept   | Parquet |
|-------------------|---------|
| **Server** field  | a filesystem path: a **directory** of `*.parquet` files (searched **recursively**), or a single `.parquet` file |
| database          | one implicit database named `parquet` |
| schema            | none |
| table             | one per `*.parquet` file; a file in a subfolder is named by its path relative to the root, e.g. `warehouse/sales/2024.parquet` → table **`sales/2024`** |

`.parq` and `.pqt` are accepted as alternate extensions.

## Requirements

- PHP 8.3+ with `ext-bcmath` and `ext-zlib`
- The **`zstd` PHP extension** (PECL) — many Parquet files are Zstandard-compressed,
  and `flow-php/parquet` needs `ext-zstd` to read those column chunks. Building it
  requires the PHP development toolchain (`phpize`) and PECL:

  ```bash
  # Debian/Ubuntu — install the build toolchain first:
  sudo apt-get install php-dev php-pear      # provides phpize + pecl

  # then build & install the extension:
  sudo pecl install zstd
  ```

  Then enable it. The simplest way is to add the line directly to your `php.ini`
  (run `php --ini` to find the file(s) in use — note the CLI and web-server SAPIs
  often use different ini files, so add it to both):

  ```ini
  ; php.ini
  extension=zstd.so
  ```

  On Debian/Ubuntu you can instead use the per-extension ini + `phpenmod`, which
  enables it for every SAPI at once:

  ```bash
  echo "extension=zstd.so" | sudo tee /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/mods-available/zstd.ini
  sudo phpenmod zstd
  ```

  Restart your PHP/web server afterwards, then verify with `php -m | grep zstd`
  (and check the web SAPI too, e.g. via a `phpinfo()` page). Snappy- and
  gzip-compressed Parquet work without it, but zstd is the common default from
  Spark/Arrow/DuckDB exports.
- `composer require flow-php/parquet`
- Adminer 5.x

## Install

This is a drop-in driver. Adminer globs `adminer-plugins/*.php` at startup and
includes each file, so you only need to place the files there — the top-level
`add_driver("parquet", "Parquet")` call registers the driver and **Parquet**
appears in the System dropdown. No `adminer_object()` bootstrap required.

Lay it out next to your `adminer.php`:

```
your-adminer-dir/
├── adminer.php
├── adminer-plugins/          # folder name must be exactly this
│   ├── parquet-driver.php     # the driver
│   └── parquet-login.php      # login-form relabeling (optional but recommended)
└── vendor/                   # from: composer require flow-php/parquet
```

Steps:

```bash
# in your-adminer-dir/
composer require flow-php/parquet
mkdir -p adminer-plugins
cp /path/to/parquet-driver.php /path/to/parquet-login.php adminer-plugins/
```

The single-file `adminer.php` has no Composer autoloader of its own, so
`parquet-driver.php` pulls in `vendor/autoload.php` itself — it looks both next to
the plugin (`adminer-plugins/vendor/`) and next to `adminer.php` (`../vendor/`),
so either location for `composer require` works.

Verify the reader independently of Adminer:

```bash
php -r 'require "vendor/autoload.php";
  $f = \Flow\Parquet\Reader::php()->read("data.parquet");
  echo $f->metadata()->rowsNumber()." rows\n";
  foreach ($f->values([], 5) as $r) echo json_encode($r)."\n";'
```

## Usage

On Adminer's login screen:

- **System**: Parquet
- **Parquet path**: a directory of `.parquet` files (e.g. `/var/data/warehouse/`)
  or a single file (e.g. `/var/data/events.parquet`)
- **Username / Password**: ignored

Files under the directory are listed recursively as tables; open one to browse it,
search/sort columns, and page through rows.

## Supported

Browsing the file/table list (recursive), viewing a table's columns and types,
row counts and file sizes, `SELECT` with search (`=`, `<`, `>`, `<=`, `>=`, `!=`,
`LIKE`, `IN`, `IS NULL`, …), multi-column sort, paging, a reference
`CREATE TABLE` reconstruction, and export (dump).

Nested `LIST` / `MAP` / `STRUCT` columns are shown JSON-encoded (marked "nested"
in the field list).

## Not supported

Everything that mutates or requires a SQL engine — the free-text **SQL command**
page, `INSERT`/`UPDATE`/`DELETE`, `CREATE`/`ALTER`/`DROP`, indexes, foreign keys,
triggers, stored routines, transactions, and `EXPLAIN`. Parquet is read-only here
by design; joins and aggregations across files are not available (no SQL engine).

## Layout

```
parquet-driver.php       the driver (self-contained, namespace Adminer) — goes in adminer-plugins/
parquet-login.php        login-form relabeling helper — goes in adminer-plugins/
composer.json            dependencies (flow-php/parquet, ext-bcmath, ext-zlib)
docs/                    local copies of the Adminer API files used as reference
```
