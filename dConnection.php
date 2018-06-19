<?php
namespace dStruct;
/**
* Defines the dConnection class that manages the connection to the database.
*
* @copyright 2009-2015 Mike Weaver
*
* @license http://www.opensource.org/licenses/mit-license.html
*
* @author Mike Weaver
* @created 2009-03-17
* @revised 2015-02-09
*
*/

//
// !dConnection class that manages the connection to the database.
//

function zombie_error_log($msg) { /*error_log("[zombie] {$msg}");*/ }

function cnxn_error_log($msg) { error_log("[cnxn] {$msg}"); }

class dConnection {

	const dBOOL = 'bool';
	const dCHAR03 = 'char03';
	const dDATETIME = 'datetime';
	const dDECIMAL = 'decimal';
	const dINT = 'int';
	const dFLOAT = 'float';
	const dTEXT = 'text';
	const dREF = 'ref';
	const dKEY = 'key';
	const dCONCAT = 'concat';

	const SEQ_CONCAT = -2;
	const SEQ_SINGLE = -1;

	const CONCAT_LENGTH = 90; // Must match the length of the varchar field defined in the database.

	protected $mysqli;
	protected $domain;
	// Caches of executable statements and bound parameters.
	protected $stmtCache = array();
	protected $paramCache = array();
	// For cross referencing fnames and codes and tables and categories.
	private $codes; // gname+fname => code
	private $fnames; // code => fname
	private $tables; // code => table, also gname=>array of tables used for this gname
	private $categories; // gname => category
	private $gnames; // category => gname
	// Max values.
	private $lastCategory;
	private $lastCode;
	private $minIdee;
	private $maxIdee;
	// Cache of structs already fetched.
	private $structCache;
	// Managing transactions.
	private $inTransaction;
	private $commitQueue; // Objects that have been changed and will be committed when the transaction completes.
	// Mode to recycle deleted objects.
	private $zombieWarn;
	private $zombieMode;
	private $zombiePool;
	// Filtering fields.
	private $filter = array();

	static protected $shared_cnxn;

	//
	// !Contructors and preparing the connection for reading or writing.
	//

	function __construct($mysqli, $domain) {
		$this->mysqli = $mysqli;
		$this->domain = $domain;
		$this->fetchDefs();
		self::registerSharedConnection($this);
	}

	function __destruct() {
		/*cnxn_error_log('closing cached statements');*/
		if ($this->stmtCache) foreach ($this->stmtCache as $stmt) { $stmt->close(); }
	}

	function printDefs() {
		echo("lookup tables:\n");
		echo("===codes:\n");
		print_r($this->codes);
		echo("===fnames:\n");
		print_r($this->fnames);
		echo("===tables:\n");
		print_r($this->tables);
		echo("===categories:\n");
		print_r($this->categories);
		echo("===gnames:\n");
		print_r($this->gnames);
	}

	function fetchDefs() {
		// Read in the def_code and def_category tables and construct the cross reference structures. Note that codes and categories are not domain-specific.
		if (!($result = $this->mysqli->query('SELECT g.category, c.code, c.fname, c.`table`, g.gname FROM def_code AS c, def_category AS g WHERE c.category = g.category ORDER BY category, code FOR UPDATE', MYSQLI_STORE_RESULT))) { throw new \Exception("Execute Error: {$this->mysqli->error}", $this->mysqli->errno); }
		while (list($category, $code, $fname, $table, $gname) = $result->fetch_row()) {
			$this->codes[$gname][$fname] = $code;
			$this->fnames[$code] = $fname;
			$this->tables[$code] = $table;
			if (!in_array($table, (array)$this->tables[$gname])) { $this->tables[$gname][] = $table; }
			if (!$this->categories[$gname]) {
				$this->categories[$gname] = $category;
				$this->gnames[$category] = $gname;
			}
		}
		$result->close();
	}

	function resetDomain($domain) {
		$this->lastCategory = null;
		$this->lastCode = null;
		$this->minIdee = null;
		$this->maxIdee = null;
		$this->structCache = null;
		$this->domain = $domain;
	}

	function resetDefs() {
		unset($this->codes);
		unset($this->fnames);
		unset($this->tables);
		unset($this->categories);
		unset($this->gnames);
	}

	//
	// !Transactions.
	//


	function startTransaction() {
		if (0 == $this->inTransaction) {
			$this->lastCategory = null;
			$this->lastCode = null;
			$this->minIdee = null;
			$this->maxIdee = null;
			if (!$this->mysqli->query('START TRANSACTION')) { throw new \Exception('Error starting transaction'); }
		}
		$this->inTransaction++;
	}

	function commitTransaction() {
		if (0 == $this->inTransaction) { throw new \Exception('Error commitTransaction when not yet started'); }
		if (0 == $this->inTransaction-1) { foreach ((array)$this->commitQueue as $struct) { $struct->commitStruct(); } $this->commitQueue = null; } // Commit everybody in the queue if we are about to end the transaction.
		$this->inTransaction--;
		if (0 == $this->inTransaction) { if (!$this->mysqli->query('COMMIT')) { throw new \Exception('Error committing transaction'); } }
	}

	function rollbackTransaction() {
		if (0 < $this->inTransaction) { if (!$this->mysqli->query('ROLLBACK')) { throw new \Exception('Error rolling back transaction'); } }
		$this->inTransaction = 0;
		foreach ((array)$this->commitQueue as $struct) { $struct->rollback(); } // Restore fields to their original values.
		$this->commitQueue = null; // Clear out the commit queue.
	}

	function confirmTransaction($caller) { if (!$this->inTransaction) { throw new \Exception("{$caller}: not in a transaction"); } }

	function queueForCommit($struct) { if (!in_array($struct, (array)$this->commitQueue, true)) { $this->commitQueue[] = $struct; } }

	//
	// !Accessors.
	//

	function getMysqli() { return $this->mysqli; }
	function getDomain() { return $this->domain; }

	//
	// !Shared connection
	//

	static function sharedConnection() {
		return self::$shared_cnxn;
	}

	static function registerSharedConnection($cnxn) {
		self::$shared_cnxn = $cnxn;
	}

	//
	// !Access the cross-reference tables.
	//

	function codeForFname($gname, $fname, $refresh=false) { // This method, and categoryForGname below, are called when confirming a struct. If the code or category can't be found, we pass $refresh=true to give ourselves a chance to retrieve it before assigning a new one -- this is to cover a situation where a second connection to the database has generated a new code or category that we didn't know about from our initial pull of the defs.
		if ($refresh && !$this->codes[$gname][$fname]) { $this->fetchDefs(); }
		return $this->codes[$gname][$fname];
	}

	function fnameForCode($code) { return $this->fnames[$code]; }

	function categoryForGname($gname, $refresh=false) {
		if ($refresh && !$this->categories[$gname]) { $this->fetchDefs(); }
		return $this->categories[$gname];
	}

	function gnameForCategory($category) { return $this->gnames[$category]; }

	function tableForCode($code) { return $this->tables[$code]; }

	function tablesForGname($gname) { return $this->tables[$gname]; }

	function gnames() { return $this->gnames; }

	//
	// !Utility for setting up and binding preparing statements.
	//

	// Prepares and caches a statement if it doesn't exist, binding parameters to elements within the $paramCache array, then sets values in the $paramCache array and returns the statement ready to execute.
	function getStatement($cKey, $sqlCallback, $types, $params) {
		// Lazily prepare and cache the statement.
		if (!$this->stmtCache[$cKey]) {
			if (!($stmt = $this->mysqli->prepare( $sqlCallback instanceof \Closure ? $sqlCallback() : $sqlCallback ))) { throw new \Exception("Statement Error: {$this->mysqli->error}", $this->mysqli->errno); }
			$reflectMethod = new \ReflectionMethod('mysqli_stmt', 'bind_param');
			$args[] = $types;
			foreach (array_keys((array)$params) as $name) { $args[] = &$this->paramCache[$cKey][$name]; }
			if (!($reflectMethod->invokeArgs($stmt, $args))) { throw new \Exception("Bind Param Error: {$stmt->error}", $stmt->errno); }
			$this->stmtCache[$cKey] = $stmt;
		}
		foreach ((array)$params as $name=>$value) { $this->paramCache[$cKey][$name] = $value; }
		return $this->stmtCache[$cKey];
	}

	function closeStatement($cKey) {
		if ($this->stmtCache[$cKey]) {
			$this->stmtCache[$cKey]->close();
			unset($this->stmtCache[$cKey]);
		}
	}

	//
	// !Fetch a single struct by idee.
	//

	/** Returns a struct of type $gname for the given $idee. Structs know themselves if they have a key, and this method will retrieve it. But there are situations where the key is already known, provided by the $key parameter, and in those cases this method will simply set the key as provided and not fetch it. */
	function fetchStructForIdee($gname, $idee, $key=null) {
		$category = $this->categoryForGname($gname);
		// Return from the internal cache if present.
		if ($this->structCache[$category][$idee]) { return $this->structCache[$category][$idee]; }
		foreach ($this->tablesForGname($gname) as $table) {
			if (is_null($filterKey = $this->getFilterKey($gname, $table))) { continue; }
			$cKey = 'fetchIdee' . $table . $filterKey;
			$cnxn = $this;
			// Pass the SQL as a callback so that if a filter is in place, we don't have to process the filter logic each time this method is called, but only the first time.
			$callback = function () use($cnxn, $gname, $table) { return 'SELECT t.code, t.seq, t.value, c.fname FROM tbl_' . $table . ' AS t, def_code AS c, def_category AS g WHERE t.domain = ? AND t.code = c.code AND c.category = g.category AND g.gname = ? AND idee = ?' . $cnxn->getFilterSQL($gname, $table) . ' ORDER BY t.code, t.seq FOR UPDATE'; };
			$stmt = $this->getStatement($cKey, $callback, 'isi', array('domain'=>$this->domain, 'gname'=>$gname, 'idee'=>$idee, ));
			if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
			if (!$stmt->bind_result($code, $seq, $value, $fname)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
			while ($stmt->fetch()) {
				if ($concat[$fname]) { $values[$fname] .= $value; }
				else if (self::SEQ_CONCAT == $seq) { $values[$fname] = $value; $concat[$fname] = true; }
				else if (self::SEQ_SINGLE == $seq) { $values[$fname] = $value; }
				else { $values[$fname][] = $value; }
			}
			if ($stmt->error) { throw new \Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
		}
		if (!$values) { cnxn_error_log('no values fetched for ' . $gname . '(' . $idee . ')'); if (!$idee) { throw new \Exception("Fetch Error: no values fetched for {$gname} and idee is null: `{$idee}`"); } return null; }
		$result = new $gname($this, $idee, $values);
		if ($key) { $result->key = $key; } // Set the key without triggering a register.
		else if ($gname::shouldFetchKey()) { $result->fetchKey(); }
		$this->structCache[$category][$idee] = $result;
		return $result;
	}

	//
	// !Fetch all structs in a category.
	//

	function fetchStructsForGname($gname) {
		$cKey = 'fetchStructs';
		$stmt = $this->getStatement($cKey, 'SELECT t.idee, t.category FROM meta_idees AS t, def_category AS g WHERE t.domain = ? AND t.category = g.category AND g.gname = ? ORDER BY t.idee FOR UPDATE', 'is', array('domain'=>$this->domain, 'gname'=>$gname, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (!$stmt->bind_result($idee, $category)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
		$idees = array();
		while ($stmt->fetch()) { $idees[] = $idee; }
		if ($stmt->error) { throw new \Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
		$structs = array();
		foreach ($idees as $idee) { $structs[$idee] = $this->fetchStructForIdee($gname, $idee); }
		return $structs;
	}

	//
	// !Fetch all values of a specific code.
	//

	function fetchValuesForFname($gname, $fname) {
		$code = $this->codeForFname($gname, $fname);
		$table = $this->tableForCode($code);
		$cKey = 'fetchValues' . $table;
		$stmt = $this->getStatement($cKey, 'SELECT t.idee, t.code, t.seq, t.value FROM tbl_' . $table . ' AS t, def_code AS c, def_category AS g WHERE t.domain = ? AND t.code = c.code AND c.category = g.category AND g.gname = ? AND t.code = ? ORDER BY t.idee, t.seq FOR UPDATE', 'isi', array('domain'=>$this->domain, 'gname'=>$gname, 'code'=>$code, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (!$stmt->bind_result($idee, $code, $seq, $value)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
		$result = array();
		while ($stmt->fetch()) { $result[] = array('idee'=>$idee, 'code'=>$code, 'seq'=>$seq, 'value'=>$value, ); }
		if ($stmt->error) { throw new \Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
		return $result;
	}

	//
	// !Fetch and store structs by keys.
	//

	function registerStructWithKey($struct, $key) {
		$gname = get_class($struct);
		$cKey = 'registerKey';
		$stmt = $this->getStatement($cKey, 'INSERT INTO meta_keys (domain, category, `key`, idee) VALUES ( ?, ?, ?, ? )', 'iisi', array('domain'=>$this->domain, 'category'=>$this->categoryForGname($gname), 'key'=>$key, 'idee'=>$struct->idee, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (1 != $stmt->affected_rows) { throw new \Exception('Invalid: number of affected rows after key insert was ' . $stmt->affected_rows . ' not 1'); }
	}

	function unregisterStructWithKey($struct, $key) {
		$gname = get_class($struct);
		$cKey = 'unregisterKey';
		$stmt = $this->getStatement($cKey, 'DELETE FROM meta_keys WHERE domain = ? AND category = ? AND `key` = ?', 'iis', array('domain'=>$this->domain, 'category'=>$this->categoryForGname($gname), 'key'=>$key, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (1 != $stmt->affected_rows) { throw new \Exception('Invalid: number of affected rows after key delete was ' . $stmt->affected_rows . ' not 1'); }
		$struct->key = null;
		return;
	}

	function fetchStructForKey($gname, $key) {
		$cKey = 'fetchStructForKey';
		$stmt = $this->getStatement($cKey, 'SELECT idee FROM meta_keys WHERE domain = ? AND category = ? AND `key` = ? FOR UPDATE', 'iis', array('domain'=>$this->domain, 'category'=>$this->categoryForGname($gname), 'key'=>$key, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (!$stmt->bind_result($idee)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
		while ($stmt->fetch()) { $result = $idee; }
		if ($result) {
			$struct = $this->fetchStructForIdee($gname, $result, $key); //Note: We know the key already.
			return $struct;
		} else { return null; }
	}

	function fetchKeyedStructs($gname) {
		$cKey = 'fetchKeyedStructs';
		$stmt = $this->getStatement($cKey, 'SELECT idee, `key` FROM meta_keys WHERE domain = ? AND category = ? FOR UPDATE', 'ii', array('domain'=>$this->domain, 'category'=>$this->categoryForGname($gname), ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (!$stmt->bind_result($idee, $key)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
		$idees = array();
		while ($stmt->fetch()) { $idees[$idee] = $key; }
		if ($stmt->error) { throw new \Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
		$structs = array();
		//NOTE: We know the key already, so we provide it to `fetchStructForIdee`.
		foreach ($idees as $idee=>$key) { $structs[$key] = $this->fetchStructForIdee($gname, $idee, $key); }
		return $structs;
	}

	function fetchKeyForStruct($struct) {
		$cKey = 'fetchKeyForStruct';
		$stmt = $this->getStatement($cKey, 'SELECT `key` FROM meta_keys WHERE domain = ? AND category = ? AND idee = ? FOR UPDATE', 'iii', array('domain'=>$this->domain, 'category'=>$this->categoryForGname(get_class($struct)), 'idee'=>$struct->idee, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (!$stmt->bind_result($key)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
		while ($stmt->fetch()) { $result = $key; }
		return $result;
	}

	function isUniqueKey($gname, $key) { return is_null($this->fetchStructForKey($gname, $key)); }

	//
	// !Insert, update and delete fields from tables.
	//

	function insertField($struct, $fname, $value) {
		$gname = get_class($struct);
		$code = $this->codeForFname($gname, $fname);
		$table = $this->tableForCode($code);
		$cKey = 'insert' . $table;
		if (is_array($value)) { $seq = 0; }
		else if ($struct->fnameIsConcat($fname)) { $seq = self::SEQ_CONCAT; $value = str_split($value, self::CONCAT_LENGTH); }
		else { $seq = self::SEQ_SINGLE; $value = (array)$value; }
		foreach ($value as $item) {
			$stmt = $this->getStatement($cKey, 'INSERT INTO tbl_' . $table . ' (domain, idee, code, seq, value) VALUES ( ?, ?, ?, ?, ? )', 'iiiis', array('domain'=>$this->domain, 'idee'=>$struct->idee, 'code'=>$code, 'seq'=>$seq, 'value'=>$item, ));
			try {
			if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
			} catch (\Exception $e) { error_log("exception is $e"); }
			if (1 != $stmt->affected_rows) { throw new \Exception('Invalid: number of affected rows after insert was ' . $stmt->affected_rows . ' not 1'); }
			$seq++;
		}
	}

	function updateField($struct, $fname, $value) {
		$this->deleteField($struct, $fname);
		$this->insertField($struct, $fname, $value);
	}

	function deleteField($struct, $fname) {
		$gname = get_class($struct);
		$code = $this->codeForFname($gname, $fname);
		$table = $this->tableForCode($code);
		$cKey = 'delete' . $table;
		$stmt = $this->getStatement($cKey, 'DELETE FROM tbl_' . $table . ' WHERE domain = ? AND idee = ? AND code = ?', 'iii', array('domain'=>$this->domain, 'idee'=>$struct->idee, 'code'=>$code, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
	}

	//
	// !Delete a struct for a given idee.
	//

	function deleteIdeeFromTable($table, $gname, $idee) {
		$cKey = 'deleteIdee' . $table;
		$stmt = $this->getStatement($cKey, 'DELETE t FROM tbl_' . $table . ' AS t, def_code AS c, def_category AS g WHERE t.domain = ? AND t.code = c.code AND c.category = g.category AND g.gname = ? AND idee = ?', 'isi', array('domain'=>$this->domain, 'gname'=>$gname, 'idee'=>$idee, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
	}

	function deleteStructWithIdee($gname, $idee) {
		cnxn_error_log('deleting ' . $gname . "({$idee})");
		$tables = $this->tablesForGname($gname);
		foreach ($tables as $table) { $this->deleteIdeeFromTable($table, $gname, $idee); }
		$cKey = 'deleteIdee';
		$stmt = $this->getStatement($cKey, 'DELETE FROM meta_idees WHERE domain = ? AND category = ? AND idee = ?', 'iii', array('domain'=>$this->domain, 'category'=>$this->categoryForGname($gname), 'idee'=>$idee, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (1 != $stmt->affected_rows) { throw new \Exception('Invalid: number of affected rows after delete was ' . $stmt->affected_rows . ' not 1'); }
	}

	//
	// !Add a struct to the meta_idees table.
	//

	function addStructIdee($struct) {
		$gname = get_class($struct);
		$category = $this->categoryForGname($gname);
		$cKey = 'insertIdee';
		$stmt = $this->getStatement($cKey, 'INSERT INTO meta_idees (domain, category, idee) VALUES ( ?, ?, ? )', 'iii', array('domain'=>$this->domain, 'category'=>$category, 'idee'=>$struct->idee, ));
		if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
		if (1 != $stmt->affected_rows) { throw new \Exception('Invalid: number of affected rows after idee insert was ' . $stmt->affected_rows . ' not 1'); }
	}

	//
	// !Find the next category, code, idee.
	//

	function getNextCategory() {
		if (is_null($this->lastCategory)) {
			$result = $this->mysqli->query('SELECT MAX(category) FROM def_category FOR UPDATE', MYSQLI_STORE_RESULT);
			while ($max = $result->fetch_row()) { $this->lastCategory = $max[0]; }
			$result->close();
		}
		return ++$this->lastCategory;
	}

	function getNextCode() {
		if (is_null($this->lastCode)) {
			$result = $this->mysqli->query('SELECT MAX(code) FROM def_code FOR UPDATE', MYSQLI_STORE_RESULT);
			while ($max = $result->fetch_row()) { $this->lastCode = $max[0]; }
			$result->close();
		}
		return ++$this->lastCode;
	}

	function getNextIdee($category) {
		if (is_null($this->maxIdee[$category]) || is_null($this->minIdee[$category])) {
			$cKey = 'fetchIdee';
			$stmt = $this->getStatement($cKey, 'SELECT MAX(idee), MIN(idee) FROM meta_idees WHERE domain = ? AND category = ? GROUP BY category FOR UPDATE', 'ii', array('domain'=>$this->domain, 'category'=>$category, ));
			if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
			if (!$stmt->bind_result($maxIdee, $minIdee)) { throw new \Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
			$result = array();
			while ($stmt->fetch()) { $this->maxIdee[$category] = $maxIdee; $this->minIdee[$category] = $minIdee; }
			if ($stmt->error) { throw new \Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
		}
		if ($this->minIdee[$category]>1) { return --$this->minIdee[$category]; }
		else { return ++$this->maxIdee[$category]; }
	}

	//
	// !Confirm a struct. Make sure a category is defined for the gname, and codes defined for the field names.
	//

	function confirmStruct($struct) {
		$this->confirmTransaction('confirmStruct'); // This function should be called within an object's commitStruct() or createNewWithConnection() method, so we should be inside a transaction.
		$gname = get_class($struct);
		/*cnxn_error_log('confirming struct ' . $struct);*/
		// Confirm that we have a category for this gname.
		if (!($category = $this->categoryForGname($gname, true))) {
			$category = $this->getNextCategory();
			/*cnxn_error_log('no category for gname ' . $gname . ' fetched next category: ' . $category);*/
			$cKey = 'insertCategory';
			$stmt = $this->getStatement($cKey, 'INSERT INTO def_category (category, gname) VALUES ( ?, ? )', 'is', array('category'=>$category, 'gname'=>$gname, ));
			if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
			if (1 != $stmt->affected_rows) { throw new \Exception("Invalid: number of affected rows after category insert was {$stmt->affected_rows} not 1"); }
			// Update the internal xref tables and store the variable for use in the rest of this function.
			$this->categories[$gname] = $category;
			$this->gnames[$category] = $gname;
		}
		// Confirm that all the fields have a code.
		$fieldDefs = $gname::fieldDefs();
		foreach ($fieldDefs as $fname=>$table) {
			if (!($code = $this->codeForFname($gname, $fname, true))) {
				$code = $this->getNextCode();
				/*cnxn_error_log('no code for fname ' . $fname . ' fetched next code: ' . $code);*/
				$cKey = 'insertCode';
				$stmt = $this->getStatement($cKey, 'INSERT INTO def_code (category, code, fname, `table`) VALUES ( ?, ?, ?, ? )', 'iiss', array('category'=>$category, 'code'=>$code, 'fname'=>$fname, 'table'=>$table, ));
				if (!$stmt->execute()) { throw new \Exception("Execute Error: {$stmt->error}", $stmt->errno); }
				if (1 != $stmt->affected_rows) { throw new \Exception("Invalid: number of affected rows after category insert was {$stmt->affected_rows} not 1"); }
				// Update the internal xref tables.
				$this->codes[$gname][$fname] = $code;
				$this->fnames[$code] = $fname;
				$this->tables[$code] = $table;
				if (!in_array($table, (array)$this->tables[$gname])) { $this->tables[$gname][] = $table; }
			}
			if ($table != $this->tableForCode($this->codeForFname($gname, $fname))) { throw new \Exception("Invalid: for gname [{$gname}], table returned by object [{$table}] does not match table stored in database [" . $this->tableForCode($this->codeForFname($gname, $fname)) . '].'); }
		}
		// Confirm that the struct has an idee.
		if (!$struct->idee) {
			$struct->idee = $this->getNextIdee($category);
			$this->addStructIdee($struct);
		}
		// Cache the struct so (if it is brand new) subsequent calls to fetchStructXXX, structFromRefField, etc. will return it.
		$this->structCache[$category][$struct->idee] = $struct;
	}

	//
	// !Zombies. Place a struct that is on the chopping block into a pool for possible recycling.
	//

	function pauseZombieWarnings() {
		$this->zombieWarn++;
	}

	function resumeZombieWarnings() {
		if (0 == $this->zombieWarn) { throw new \Exception('Error resumeZombieWarnings when not yet paused'); }
		$this->zombieWarn--;
	}

	function shouldWarnZombie() { return 0==$this->zombieWarn; }

	function startZombieMode() {
		$this->zombieMode++;
	}

	function stopZombieMode() {
		if (0 == $this->zombieMode) { throw new \Exception('Error stopZombieMode when not yet started'); }
		$this->zombieMode--;
		if (0 == $this->zombieMode) {
			foreach ((array)$this->zombiePool as $gname=>$pool) { foreach ((array)$pool as $item) { zombie_error_log("stopZombieMode: killing zombie {$item}"); $item->deleteStruct(); } }
			$this->zombiePool = array();
		}
	}

	function inZombieMode() { return 0<$this->zombieMode; }

	function pushZombie($struct) {
		if (!$this->inZombieMode()) { throw new \Exception('Error pushZombie when not in zombie mode'); }
		$gname = get_class($struct);
		zombie_error_log("pushZombie: $struct");
		$struct->nullify(); // Set all the struct's fields to null, any owned refs wil be pushed into the zombie pool as well.
		if (is_null($this->zombiePool[$gname])) { $this->zombiePool[$gname] = array(); }
		array_unshift($this->zombiePool[$gname], $struct);
	}

	function popZombie($gname) {
		if ($this->zombiePool[$gname] && ($item = array_pop($this->zombiePool[$gname]))) {
			zombie_error_log("popZombie: $item");
			return $item;
		}
		return null;
	}

	//
	// !Filters. The user can put a filter on a gname so only specific fields are pulled.
	//

	function setFilter($gname, $fieldList) {
		// Scan through the field lists and make a dictionary of the gname and the tables and the codes for each table.
		unset($this->filter[$gname]);
		foreach ($fieldList as $fname) {
			$code = $this->codeForFname($gname, $fname);
			$table = $this->tableForCode($code);
			$this->filter[$gname][$table][] = $code;
		}
		// Clear out any past cached fetchIdee statements so they will be rebuilt with the filter in place.
		unset($this->stmtCache['fetchIdee' . $table . '-_filter_-' . $gname]);
	}

	function clearFilter($gname) {
		// Clear out filter dictionary and cached fetchIdee statement.
		unset($this->filter[$gname]);
		unset($this->stmtCache['fetchIdee' . $table . '-_filter_-' . $gname]);
		unset($this->structCache[$this->categoryForGname($gname)]);
	}

	function getFilterKey($gname, $table) {
		// Return a key that will be appended to the normal $cKey and used to cache the statement. This method will be called for every table holding the fields of the struct, but if no filtered fields are in the requested table, it returns null. If there is no filter in place, returns a blank string so $cKey will be unaffected
		if ($this->filter[$gname]) {
			if ($this->filter[$gname][$table]) { return '-_filter_-' . $gname; }
			else { return null; } // Skip this table because none of the filtered fields are in it.
		} else {
			return ''; // No filter in place so the key will be blank.
		}
	}

	function getFilterSQL($gname, $table) {
		// Return a snippet of SQL code that will limit codes to those in the filtered list. This method will be called for every table holding the fields of the struct, but if no filtered fields are in the requested table, it returns null. If there is no filter in place, return a blank string so the original SQL will be unaffected.
		if ($this->filter[$gname]) {
			if ($this->filter[$gname][$table]) { return ' AND t.code IN (' . implode(', ', $this->filter[$gname][$table]) . ')'; }
			else { return null; } // Skip this table because none of the filtered fields are in it.
		} else {
			return ''; // No filter in place so the SQL will be blank.
		}
	}

} // class dConnection

?>
