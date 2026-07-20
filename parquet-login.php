<?php
namespace Adminer;

/**
 * Login-form helper for the Parquet driver.
 * Copyright © 2026+ Daniel Duris, dusoft@staznosti.sk
 *
 * Parquet is file-based like SQLite: the "Server" field is really a filesystem
 * path (a directory of *.parquet files, or a single .parquet file) and
 * username/password are ignored. Adminer hides the Server row only for the
 * hard-coded `sqlite` driver in its COMPILED JavaScript:
 *
 *     // adminer/static/editing.js
 *     function loginDriver(driver) {
 *         const disabled = /sqlite/.test(selectValue(driver));  // only "sqlite"
 *         alterClass(trs[1], 'hidden', disabled);               // hides Server row
 *     }
 *
 * "parquet" doesn't match `/sqlite/`, and no PHP hook can change that client-side
 * behaviour, so this plugin injects a small script that relabels the Server field
 * to "Parquet file or directory" and disables Username/Password when the Parquet
 * driver is selected. It reuses Adminer's own login form structure.
 *
 * Must extend Adminer\Plugin: Adminer only auto-instantiates a plugin whose
 * unqualified class name starts with "Adminer" OR which subclasses Adminer\Plugin.
 * This file is namespaced `Adminer`, so the subclass check is what registers us.
 *
 * Drop this file next to `parquet-driver.php` in `adminer-plugins/`.
 */
class AdminerParquetLogin extends Plugin {
	/** Runs inside <head> on every page, including the login page. */
	function head($dark = null) {
		echo "<script" . nonce() . ">
(function () {
	function parquetLogin() {
		var sel = document.querySelector('select[name=\"auth[driver]\"]');
		if (!sel) return;
		var table = sel.closest('table');
		if (!table) return;
		var isParquet = sel.value === 'parquet';

		var rows = table.rows;
		// Row order in Adminer's login form: 0 System, 1 Server, 2 Username, 3 Password, 4 Database.
		var serverRow = rows[1], userRow = rows[2], passRow = rows[3];

		if (isParquet) {
			var th = serverRow.cells[0];
			th.textContent = 'Parquet path';
			var input = serverRow.getElementsByTagName('input')[0];
			if (input) {
				input.placeholder = '/path/to/warehouse/  or  /path/to/data.parquet';
				input.title = 'Path to a directory of .parquet files (searched recursively) or a single .parquet file. Username and password are ignored.';
				input.disabled = false;
			}
			// Username/Password are meaningless for Parquet: disable them.
			[userRow, passRow].forEach(function (r) {
				if (!r) return;
				var i = r.getElementsByTagName('input')[0];
				if (i) { i.disabled = true; i.placeholder = 'not used by Parquet'; }
			});
		} else {
			// Restore defaults when switching away from Parquet.
			if (serverRow.cells[0].textContent === 'Parquet path') {
				serverRow.cells[0].textContent = 'Server';
				var si = serverRow.getElementsByTagName('input')[0];
				if (si) { si.placeholder = 'localhost'; si.title = ''; }
			}
			[userRow, passRow].forEach(function (r) {
				if (!r) return;
				var i = r.getElementsByTagName('input')[0];
				if (i) { i.disabled = false; i.placeholder = ''; }
			});
		}
	}

	function bind() {
		var sel = document.querySelector('select[name=\"auth[driver]\"]');
		if (sel) {
			sel.addEventListener('change', parquetLogin);
			parquetLogin(); // apply on initial load
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
</script>\n";
		return false; // don't suppress Adminer's own head output
	}

	/**
	 * Permit passwordless login for Parquet.
	 *
	 * Parquet is file-based and ignores username/password, so the Server field
	 * carries a filesystem path and the password is blank. Adminer otherwise
	 * refuses an empty password ("does not support accessing a database without a
	 * password"). Returning true here authorises the login — but only for the
	 * parquet driver, so other drivers keep Adminer's normal password requirement.
	 */
	function login($login, $password) {
		$driver = $_POST["auth"]["driver"] ?? (defined("Adminer\\DRIVER") ? DRIVER : "");
		if ($driver === "parquet") {
			return true;
		}
		return null; // defer to Adminer's default handling for other drivers
	}
}
