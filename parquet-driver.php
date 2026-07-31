<?php
namespace Adminer;

/**
 * Parquet driver for Adminer 5.x (read-only).
 * Copyright © 2026+ Daniel Duris, dusoft@staznosti.sk
 *
 * Apache Parquet is a columnar on-disk file format, not a database server: there
 * is no SQL engine, no connection, and no notion of INSERT/UPDATE. This driver
 * lets you browse and read Parquet files through the normal Adminer UI by
 * wrapping the pure-PHP reader "flow-php/parquet"
 * (https://github.com/flow-php/parquet). No native library, FFI, or PDO driver
 * is required — it is plain PHP (needs ext-bcmath and ext-zlib).
 *
 * MODEL MAPPING. A Parquet file is a single flat table. Adminer's world is
 * server -> database -> schema -> table, so we map it like this:
 *
 *   - "Server" login field  = a filesystem path: either a DIRECTORY containing
 *                             *.parquet files, or a single .parquet FILE.
 *   - database              = a single implicit database named "parquet".
 *   - schema                = none (Parquet has no schemas).
 *   - table                 = one per *.parquet file; the table name is the file
 *                             name without the .parquet extension.
 *
 * There is no SQL engine behind Parquet, so the usual Adminer flow of "build a
 * SQL string and hand it to the connection" does not apply. Instead Driver::select()
 * is overridden to read rows directly from the file via flow-php and to apply
 * Adminer's WHERE / ORDER BY / LIMIT / paging in PHP. The free-text "SQL command"
 * page is therefore disabled (support('sql') is false).
 *
 * READ-ONLY. Parquet files are treated as immutable analytical data: no INSERT,
 * UPDATE, DELETE, CREATE, ALTER or DROP. Those features are reported unsupported
 * via Driver::support() and the corresponding hooks are stubbed.
 *
 * DROP-IN: place this file in an `adminer-plugins/` directory next to your
 * `adminer.php`. Adminer globs `adminer-plugins/*.php` at startup and includes
 * each one, so the `add_driver()` call below runs and "Parquet" appears in the
 * System dropdown. Also drop `parquet-login.php` next to it so the login form
 * relabels the Server field to "Parquet file or directory".
 */

// The single-file adminer.php has no Composer autoloader, so pull in the
// flow-php/parquet binding ourselves if it was installed with Composer nearby.
(function () {
	foreach (array(
		__DIR__ . '/vendor/autoload.php',        // vendor next to this plugin file
		__DIR__ . '/../vendor/autoload.php',      // vendor next to adminer.php
	) as $autoload) {
		if (is_file($autoload)) {
			require_once $autoload;
			break;
		}
	}
})();

add_driver("parquet", "Parquet");

if (isset($_GET["parquet"])) {
	define('Adminer\DRIVER', "parquet");

	if (class_exists('Flow\Parquet\Reader')) {

		/**
		 * Result wrapper over an already-materialized list of associative rows.
		 *
		 * The flow-php reader yields rows lazily from a forward-only generator, but
		 * Adminer needs random-ish access (num_rows, seek, repeated fetch_field before
		 * fetching). Callers therefore materialize the (LIMIT-bounded) rows into plain
		 * arrays and hand them here together with column name/type metadata.
		 */
		class Result {
			/** @var int */ public $num_rows;
			/** @var list<array<string, mixed>> assoc rows */ private $rows;
			/** @var list<string> column names in order */ private $columns;
			/** @var array<string, string> logical/physical type name per column */ private $types;
			/** @var int */ private $rowOffset = 0;
			/** @var int */ private $fieldOffset = 0;

			/** @param list<array<string, mixed>> $rows
			* @param list<string> $columns
			* @param array<string, string> $types column name => type name
			*/
			function __construct(array $rows, array $columns, array $types = array()) {
				$this->columns = $columns;
				$this->types = $types;
				$this->rows = array();
				foreach ($rows as $row) {
					$assoc = array();
					foreach ($columns as $col) {
						$assoc[$col] = self::normalize($row[$col] ?? null);
					}
					$this->rows[] = $assoc;
				}
				$this->num_rows = count($this->rows);
			}

			/** Convert a value returned by flow-php (scalars, DateTimeInterface,
			* nested list/map arrays, byte strings) into a scalar Adminer can render.
			* @param mixed $val
			* @return ?scalar
			*/
			private static function normalize($val) {
				if ($val === null || is_scalar($val)) {
					return $val;
				}
				if ($val instanceof \DateTimeInterface) {
					return $val->format('Y-m-d H:i:s');
				}
				if (is_array($val)) {
					return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}
				if (is_object($val)) {
					if (method_exists($val, '__toString')) {
						return (string) $val;
					}
					return json_encode($val, JSON_UNESCAPED_UNICODE);
				}
				return $val;
			}

			function fetch_assoc() {
				if ($this->rowOffset >= $this->num_rows) {
					return false;
				}
				return $this->rows[$this->rowOffset++];
			}

			function fetch_row() {
				$row = $this->fetch_assoc();
				return ($row === false ? false : array_values($row));
			}

			function fetch_field(): \stdClass {
				$i = $this->fieldOffset++;
				$name = $this->columns[$i] ?? "";
				$type = $this->types[$name] ?? "";
				if ($type === "") {
					$type = $this->inferType($name);
				}
				$isNumber = (bool) preg_match('~int|dec|numeric|real|double|float~i', $type);
				$isBinary = (bool) preg_match('~byte_array|binary|blob~i', $type) && !preg_match('~string~i', $type);
				return (object) array(
					"name" => $name,
					"orgname" => $name,
					"type" => ($isNumber ? 0 : 15), // 0 numeric, 15 string (mirrors other drivers)
					"charsetnr" => ($isBinary ? 63 : 0), // 63 = binary
				);
			}

			/** Guess a type name from the first non-null value in a column. */
			private function inferType(string $col): string {
				foreach ($this->rows as $row) {
					$v = $row[$col] ?? null;
					if ($v === null) {
						continue;
					}
					if (is_int($v)) {
						return "integer";
					}
					if (is_float($v)) {
						return "double";
					}
					if (is_bool($v)) {
						return "boolean";
					}
					return "varchar";
				}
				return "varchar";
			}

			function seek($offset) {
				$this->rowOffset = max(0, (int) $offset);
			}
		}


		/**
		 * "Connection" wrapper. There is no real connection for Parquet; this simply
		 * validates and remembers the target path (a directory of *.parquet files or a
		 * single .parquet file) and exposes it to the free functions below.
		 */
		class Db extends SqlDb {
			public $extension = "Parquet";
			/** @var string absolute path to a directory or a single .parquet file */ public $path = "";
			/** @var bool whether $path points at one file rather than a directory */ public $singleFile = false;

			function attach($server, $username, $password) {
				$path = trim($server);
				if ($path === "") {
					return 'Enter the path to a .parquet file or a directory of .parquet files.';
				}
				$real = realpath($path);
				if ($real === false || (!is_dir($real) && !is_file($real))) {
					return "Path not found: " . $path;
				}
				if (is_file($real) && !preg_match('~\.parquet$~i', $real)) {
					return "Not a .parquet file: " . $path;
				}
				$this->path = $real;
				$this->singleFile = is_file($real);
				$this->server_info = self::flowVersion();
				return '';
			}

			private static function flowVersion(): string {
				if (class_exists('\Composer\InstalledVersions')) {
					try {
						$v = \Composer\InstalledVersions::getPrettyVersion('flow-php/parquet');
						if ($v) {
							return "flow-php/parquet " . ltrim($v, 'v');
						}
					} catch (\Throwable $e) {
					}
				}
				return "flow-php/parquet";
			}

			function select_db($database) {
				// Only one implicit database exists; nothing to switch.
				return true;
			}

			function quote($string) {
				return "'" . str_replace("'", "''", $string) . "'";
			}

			/** Absolute path of the file backing a given table name. Table names for
			* files in subfolders use "/" separators (sales/2024); map them back to the
			* OS separator and re-append the extension. */
			function tablePath(string $table): string {
				if ($this->singleFile) {
					return $this->path;
				}
				$rel = str_replace('/', DIRECTORY_SEPARATOR, $table);
				foreach (array(".parquet", ".parq", ".pqt") as $ext) {
					$candidate = $this->path . DIRECTORY_SEPARATOR . $rel . $ext;
					if (is_file($candidate)) {
						return $candidate;
					}
				}
				return $this->path . DIRECTORY_SEPARATOR . $rel . ".parquet";
			}

			/** Open a table's Parquet file, or null on failure (recorded in $this->error). */
			function open(string $table) {
				$file = $this->tablePath($table);
				if (!is_file($file)) {
					$this->error = "No such Parquet table: " . $table;
					return null;
				}
				try {
					return \Flow\Parquet\Reader::php()->read($file);
				} catch (\Throwable $e) {
					$this->error = $e->getMessage();
					return null;
				}
			}

			// Parquet has no query language; select() on the Driver reads files directly.
			function query($query, $unbuffered = false) {
				$this->error = "Parquet is read-only and has no SQL engine.";
				return false;
			}
		}


		class Driver extends SqlDriver {
			static $extensions = array("Parquet");
			static $jush = "sqlite"; // closest dialect for JUSH highlighting of identifiers

			protected $types = array(
				array(
					"boolean" => 0,
					"int32" => 10, "int64" => 20, "int96" => 39,
					"float" => 0, "double" => 0, "decimal" => 0,
					"byte_array" => 0, "string" => 0, "uuid" => 36, "json" => 0,
					"date" => 0, "time" => 0, "timestamp" => 0,
				),
			);

			public $operators = array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL");
			public $functions = array();
			public $grouping = array();

			function structuredTypes(): array {
				return array_keys($this->types[0]);
			}

			function types(): array {
				return call_user_func_array('array_merge', array_values($this->types));
			}

			/**
			 * Read rows straight from the Parquet file and apply Adminer's WHERE /
			 * ORDER BY / LIMIT / paging in PHP, since there is no SQL backend.
			 *
			 * @param list<string> $select column expressions (idf_escaped names or "*")
			 * @param list<string> $where conditions like `"col" = 'x'`
			 * @param list<string> $group ignored (no aggregation without SQL)
			 * @param list<string> $order like `"col" DESC`
			 * @return Result|false
			 */
			function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false) {
				$start = microtime(true);
				$conn = $this->conn;
				$file = $conn->open($table);
				if ($file === null) {
					if ($print) {
						echo adminer()->selectQuery("SELECT FROM " . $table, $start, true);
					}
					return false;
				}

				$allColumns = array();
				foreach ($file->schema()->columns() as $col) {
					$allColumns[] = $col->name();
				}

				// Which columns did the user ask for? "*" or empty means all.
				$wantAll = (!$select || in_array("*", $select, true));
				$selected = array();
				if (!$wantAll) {
					foreach ($select as $expr) {
						$name = idf_unescape($expr);
						if (in_array($name, $allColumns, true)) {
							$selected[] = $name;
						}
					}
				}
				if (!$selected) {
					$selected = $allColumns;
				}

				// We must read every column referenced by WHERE / ORDER too.
				$needed = $selected;
				foreach (array_merge($where, $order) as $clause) {
					foreach ($allColumns as $c) {
						if (!in_array($c, $needed, true) && strpos($clause, idf_escape($c)) !== false) {
							$needed[] = $c;
						}
					}
				}

				// Read the whole (projected) file, then filter/sort/paginate in PHP.
				// Parquet files browsed in Adminer are analytical extracts; reading a
				// projection into memory is acceptable and keeps the code simple.
				$rows = array();
				try {
					foreach ($file->values($needed) as $row) {
						$rows[] = $row;
					}
				} catch (\Throwable $e) {
					$conn->error = $e->getMessage();
					if ($print) {
						echo adminer()->selectQuery("SELECT FROM " . table($table), $start, true);
					}
					return false;
				}

				$rows = self::applyWhere($rows, $where);
				$rows = self::applyOrder($rows, $order);

				$offset = ($page ? $limit * $page : 0);
				if ($limit) {
					$rows = array_slice($rows, $offset, $limit);
				} elseif ($offset) {
					$rows = array_slice($rows, $offset);
				}

				// Project down to the selected columns for display.
				$projected = array();
				foreach ($rows as $row) {
					$out = array();
					foreach ($selected as $c) {
						$out[$c] = $row[$c] ?? null;
					}
					$projected[] = $out;
				}

				$types = self::columnTypes($file);
				if ($print) {
					echo adminer()->selectQuery(self::describeQuery($table, $selected, $where, $order, $limit, $offset), $start, false);
				}
				return new Result($projected, $selected, $types);
			}

			/** Human-readable pseudo-query shown in the "SQL command" preview box. */
			private static function describeQuery(string $table, array $cols, array $where, array $order, int $limit, int $offset): string {
				return "-- Parquet (read-only, executed in PHP)\nSELECT " . implode(", ", array_map('Adminer\idf_escape', $cols))
					. "\nFROM " . table($table)
					. ($where ? "\nWHERE " . implode(" AND ", $where) : "")
					. ($order ? "\nORDER BY " . implode(", ", $order) : "")
					. ($limit ? "\nLIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
			}

			/** Map each column to a comparable type-name string for fetch_field(). */
			private static function columnTypes($file): array {
				$types = array();
				foreach ($file->schema()->columns() as $col) {
					$logical = method_exists($col, 'logicalType') && $col->logicalType() ? $col->logicalType()->name() : null;
					$physical = ($col->type() ? $col->type()->name : null);
					$types[$col->name()] = strtolower((string) ($logical ?: $physical ?: "varchar"));
				}
				return $types;
			}

			/** Apply Adminer's WHERE conditions in PHP.
			* @param list<array<string,mixed>> $rows
			* @param list<string> $where
			* @return list<array<string,mixed>>
			*/
			private static function applyWhere(array $rows, array $where): array {
				if (!$where) {
					return $rows;
				}
				foreach ($where as $clause) {
					$pred = self::compileCondition($clause);
					if ($pred === null) {
						continue; // unparseable condition: don't silently drop rows
					}
					$rows = array_values(array_filter($rows, $pred));
				}
				return $rows;
			}

			/** Turn a single `"col" OP value` clause into a PHP predicate, or null. */
			private static function compileCondition(string $clause) {
				// column is an idf_escaped "name"; value is a quoted 'string' or number.
				if (!preg_match('~^\s*"((?:[^"]|"")+)"\s*(=|!=|<>|<=|>=|<|>|LIKE|NOT LIKE|IS NULL|IS NOT NULL|IN|NOT IN)\s*(.*)$~is', $clause, $m)) {
					return null;
				}
				$col = str_replace('""', '"', $m[1]);
				$op = strtoupper($m[2]);
				$rhs = trim($m[3]);

				if ($op === "IS NULL") {
					return fn($r) => ($r[$col] ?? null) === null;
				}
				if ($op === "IS NOT NULL") {
					return fn($r) => ($r[$col] ?? null) !== null;
				}
				if ($op === "IN" || $op === "NOT IN") {
					$set = self::parseInList($rhs);
					$neg = ($op === "NOT IN");
					return function ($r) use ($col, $set, $neg) {
						$v = self::scalar($r[$col] ?? null);
						$in = in_array((string) $v, $set, true);
						return $neg ? !$in : $in;
					};
				}
				$val = self::unquote($rhs);
				if ($op === "LIKE" || $op === "NOT LIKE") {
					$regex = self::likeToRegex($val);
					$neg = ($op === "NOT LIKE");
					return function ($r) use ($col, $regex, $neg) {
						$v = (string) self::scalar($r[$col] ?? null);
						$match = (bool) preg_match($regex, $v);
						return $neg ? !$match : $match;
					};
				}
				return function ($r) use ($col, $op, $val) {
					$v = self::scalar($r[$col] ?? null);
					if ($v === null) {
						return false;
					}
					$cmp = self::compare($v, $val);
					switch ($op) {
						case "=": return $cmp === 0;
						case "!=": case "<>": return $cmp !== 0;
						case "<": return $cmp < 0;
						case ">": return $cmp > 0;
						case "<=": return $cmp <= 0;
						case ">=": return $cmp >= 0;
					}
					return true;
				};
			}

			/** Numeric-aware comparison; falls back to string comparison. Both sides
			* are passed through scalar() first so dates/objects never reach here raw. */
			private static function compare($a, $b): int {
				$a = self::scalar($a);
				$b = self::scalar($b);
				if (is_numeric($a) && is_numeric($b)) {
					return $a <=> $b;
				}
				return strcmp((string) $a, (string) $b);
			}

			/** Reduce a raw flow-php value to a comparable scalar for WHERE/ORDER.
			* flow-php yields DateTimeImmutable for date/time/timestamp columns and
			* arrays/objects for nested LIST/MAP/STRUCT columns, none of which are
			* string-castable on their own. */
			private static function scalar($v) {
				if ($v === null || is_int($v) || is_float($v) || is_string($v)) {
					return $v;
				}
				if (is_bool($v)) {
					return $v ? 1 : 0;
				}
				if ($v instanceof \DateTimeInterface) {
					return $v->format('Y-m-d H:i:s');
				}
				if (is_object($v) && method_exists($v, '__toString')) {
					return (string) $v;
				}
				if (is_array($v) || is_object($v)) {
					return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				}
				return $v;
			}

			private static function unquote(string $s): string {
				$s = trim($s);
				if (strlen($s) >= 2 && $s[0] === "'" && substr($s, -1) === "'") {
					return str_replace("''", "'", substr($s, 1, -1));
				}
				return $s;
			}

			/** @return list<string> */
			private static function parseInList(string $s): array {
				$s = trim($s);
				$s = preg_replace('~^\(|\)$~', '', $s);
				$out = array();
				foreach (preg_split('~,(?![^\']*\'\')~', (string) $s) as $part) {
					$out[] = (string) self::scalar(self::unquote($part));
				}
				return $out;
			}

			private static function likeToRegex(string $like): string {
				$re = preg_quote($like, '~');
				$re = str_replace(array('%', '_'), array('.*', '.'), $re);
				return '~^' . $re . '$~is';
			}

			/** Apply ORDER BY (possibly multi-column) in PHP.
			* @param list<array<string,mixed>> $rows
			* @param list<string> $order
			* @return list<array<string,mixed>>
			*/
			private static function applyOrder(array $rows, array $order): array {
				if (!$order) {
					return $rows;
				}
				$keys = array();
				foreach ($order as $clause) {
					if (preg_match('~^\s*"((?:[^"]|"")+)"\s*(DESC|ASC)?~i', $clause, $m)) {
						$keys[] = array(str_replace('""', '"', $m[1]), (isset($m[2]) && strtoupper($m[2]) === "DESC"));
					}
				}
				if (!$keys) {
					return $rows;
				}
				usort($rows, function ($a, $b) use ($keys) {
					foreach ($keys as [$col, $desc]) {
						$cmp = self::compare(self::scalar($a[$col] ?? null), self::scalar($b[$col] ?? null));
						if ($cmp !== 0) {
							return $desc ? -$cmp : $cmp;
						}
					}
					return 0;
				});
				return $rows;
			}

			function insertUpdate($table, array $rows, array $primary) {
				return false;
			}

			function begin() {
				return false;
			}

			function last_id($result) {
				return 0;
			}

			function found_rows($table_status, $where) {
			}

			function support($feature) {
				// Read-only browsing: schema (columns), table listing, export dump.
				// No sql console, no write DDL/DML, no indexes/foreign keys/triggers.
				return preg_match('~^(columns|table|dump)$~', $feature);
			}
		}



		/** Free-function capability check called by Adminer's global code (distinct
		* from Driver::support()). Read-only Parquet: list tables/columns and export
		* only. No databases, schemas, SQL console, indexes, views, or write DDL/DML. */
		function support($feature) {
			return preg_match('~^(columns|table|dump)$~', $feature);
		}

		function idf_escape($idf) {
			return '"' . str_replace('"', '""', $idf) . '"';
		}

		function table($idf) {
			return idf_escape($idf);
		}

		function get_databases($flush) {
			// A single implicit database groups all Parquet files at the path.
			return array("parquet");
		}

		function limit($query, $where, $limit, $offset = 0, $separator = " ") {
			return " $query$where" . ($limit !== null ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
		}

		function limit1($table, $query, $where, $separator = "\n") {
			return " $query$where";
		}

		function db_collation($db, $collations) {
			return "";
		}

		function logged_user() {
			return get_current_user();
		}

		/** List every *.parquet file under the connected path (recursively) as a table. */
		function tables_list() {
			$conn = connection();
			$return = array();
			if ($conn->singleFile) {
				$return[parquet_table_name($conn->path, $conn->path)] = "table";
				return $return;
			}
			foreach (parquet_files($conn->path) as $file) {
				$return[parquet_table_name($file, $conn->path)] = "table";
			}
			ksort($return);
			return $return;
		}

		/** All *.parquet files under $dir, recursing into every subdirectory.
		* e.g. warehouse/ -> warehouse/a.parquet, warehouse/sales/2024.parquet, ...
		* Subdirectories we may not read (permissions) are skipped, not fatal.
		* @return list<string> absolute file paths
		*/
		function parquet_files(string $dir): array {
			$files = array();
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ($it as $entry) {
				if ($entry->isFile() && preg_match('~\.(parquet|parq|pqt)$~i', $entry->getFilename())) {
					$files[] = $entry->getPathname();
				}
			}
			sort($files);
			return $files;
		}

		/** Table name for a file: its path relative to the connected root, minus the
		* extension, with directory separators shown as "/". Keeps files in different
		* subfolders distinct (warehouse/sales/2024.parquet -> "sales/2024"). For a
		* single-file connection this is just the bare file name. */
		function parquet_table_name(string $file, string $root): string {
			$root = rtrim($root, DIRECTORY_SEPARATOR);
			if (is_file($root)) {
				// Single-file connection: use the bare base name.
				$rel = basename($file);
			} elseif (strpos($file, $root . DIRECTORY_SEPARATOR) === 0) {
				$rel = substr($file, strlen($root) + 1);
			} else {
				$rel = basename($file);
			}
			$rel = preg_replace('~\.(parquet|parq|pqt)$~i', '', $rel);
			return str_replace(DIRECTORY_SEPARATOR, '/', $rel);
		}

		function count_tables($databases) {
			return array();
		}

		function table_status($name = "") {
			$conn = connection();
			$return = array();
			foreach (tables_list() as $tableName => $type) {
				if ($name != "" && $tableName != $name) {
					continue;
				}
				$file = $conn->tablePath($tableName);
				$rows = null;
				$dataLength = null;
				if (is_file($file)) {
					$dataLength = filesize($file);
					try {
						$rows = \Flow\Parquet\Reader::php()->read($file)->metadata()->rowsNumber();
					} catch (\Throwable $e) {
						$rows = null;
					}
				}
				$return[$tableName] = array(
					"Name" => $tableName,
					"Engine" => "Parquet",
					"Oid" => null,
					"Auto_increment" => "",
					"Rows" => $rows,
					"Data_length" => $dataLength,
					"Comment" => "",
					"Collation" => "",
				);
			}
			return $return;
		}

		function is_view($table_status) {
			return false;
		}

		function fk_support($table_status) {
			return false;
		}

		/** Map a flow-php Parquet column to one of the driver's canonical type keys. */
		function normalize_type($physical, $logical) {
			$logical = $logical ? strtoupper($logical) : "";
			static $logicalMap = array(
				"STRING" => "string", "ENUM" => "string", "JSON" => "json", "BSON" => "json",
				"UUID" => "uuid", "DATE" => "date", "TIME" => "time", "TIMESTAMP" => "timestamp",
				"DECIMAL" => "decimal", "INTEGER" => null, // INTEGER logical keeps its physical width
			);
			if ($logical && isset($logicalMap[$logical]) && $logicalMap[$logical] !== null) {
				return $logicalMap[$logical];
			}
			$physical = $physical ? strtoupper($physical) : "";
			static $physicalMap = array(
				"BOOLEAN" => "boolean",
				"INT32" => "int32", "INT64" => "int64", "INT96" => "int96",
				"FLOAT" => "float", "DOUBLE" => "double",
				"BYTE_ARRAY" => "byte_array", "FIXED_LEN_BYTE_ARRAY" => "byte_array",
			);
			return $physicalMap[$physical] ?? "byte_array";
		}

		function fields($table) {
			$conn = connection();
			$file = $conn->open($table);
			$return = array();
			if ($file === null) {
				return $return;
			}
			$privileges = array("select" => 1, "where" => 1, "order" => 1);
			foreach ($file->schema()->columns() as $col) {
				$physical = ($col->type() ? $col->type()->name : null);
				$logical = (method_exists($col, 'logicalType') && $col->logicalType() ? $col->logicalType()->name() : null);
				$fullType = strtolower((string) ($physical ?: "byte_array")) . ($logical ? " (" . strtolower($logical) . ")" : "");
				$nullable = true;
				if (method_exists($col, 'repetition') && $col->repetition()) {
					// REQUIRED repetition means NOT NULL.
					$nullable = (strtoupper($col->repetition()->name) !== "REQUIRED");
				}
				$return[$col->name()] = array(
					"field" => $col->name(),
					"type" => normalize_type($physical, $logical),
					"full_type" => $fullType,
					"length" => (method_exists($col, 'typeLength') ? $col->typeLength() : null),
					"default" => null,
					"null" => $nullable,
					"auto_increment" => false,
					"privileges" => $privileges,
					"primary" => false,
					"comment" => ($col->isList() || $col->isMap() || $col->isStruct() ? "nested" : ""),
				);
			}
			return $return;
		}

		function indexes($table, $connection2 = null) {
			return array();
		}

		function foreign_keys($table) {
			return array();
		}

		function view($name) {
			return array();
		}

		function collations() {
			return array();
		}

		function information_schema($db) {
			return false;
		}

		function error() {
			return h(connection()->error);
		}

		function schemas() {
			return array();
		}

		function get_schema() {
			return "";
		}

		function set_schema($schema, $connection2 = null) {
			return true;
		}

		function create_database($db, $collation) {
			return false;
		}

		function drop_databases($databases) {
			return false;
		}

		function rename_database($name, $collation) {
			return false;
		}

		function auto_increment() {
			return "";
		}

		function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
			return false;
		}

		function alter_indexes($table, $alter) {
			return false;
		}

		function truncate_tables($tables) {
			return false;
		}

		function drop_views($views) {
			return false;
		}

		function drop_tables($tables) {
			return false;
		}

		function move_tables($tables, $views, $target) {
			return false;
		}

		function copy_tables($tables, $views, $target) {
			return false;
		}

		function trigger($name, $table) {
			return array();
		}

		function triggers($table) {
			return array();
		}

		function trigger_options() {
			return array("Timing" => array(), "Event" => array(), "Type" => array());
		}

		function routine($name, $type) {
			return array();
		}

		function routines() {
			return array();
		}

		function routine_languages() {
			return array();
		}

		function routine_id($name, $row) {
			return "";
		}

		function last_id($result) {
			return 0;
		}

		function explain($connection, $query) {
			return false;
		}

		function found_rows($table_status, $where) {
			// Total row count comes straight from Parquet metadata footer.
			$conn = connection();
			$file = $conn->open($conn->singleFile ? parquet_table_name($conn->path) : ($_GET["select"] ?? ""));
			if ($file === null) {
				return false;
			}
			try {
				return $file->metadata()->rowsNumber();
			} catch (\Throwable $e) {
				return false;
			}
		}

		function types() {
			return array();
		}

		function type_values() {
			return array();
		}

		function create_sql($table, $auto_increment, $style) {
			// Reconstruct a CREATE TABLE from the Parquet schema for reference only.
			$fields = array();
			foreach (fields($table) as $field) {
				$fields[] = "  " . idf_escape($field["field"]) . " " . $field["full_type"]
					. ($field["null"] ? "" : " NOT NULL");
			}
			return "-- Parquet file (read-only)\nCREATE TABLE " . table($table) . " (\n" . implode(",\n", $fields) . "\n)";
		}

		function truncate_sql($table) {
			return "";
		}

		function use_sql($database, $style = "") {
			return "";
		}

		function trigger_sql($table) {
			return "";
		}

		function show_variables() {
			return array();
		}

		function show_status() {
			return array();
		}

		function convert_field($field) {
		}

		function unconvert_field($field, $return) {
			return $return;
		}
	}
}
