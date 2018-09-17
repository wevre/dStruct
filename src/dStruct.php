<?php
namespace dStruct;
/**
* Defines the dStruct class that represents an object stored in a database.
*
* @copyright 2009-2015 Mike Weaver
*
* @license http://www.opensource.org/licenses/mit-license.html
*
* @author Mike Weaver
* @created 2009-03-17
* @revised 2015-01-20
*
* @version 1.0.1
*/

//
// !Autoloader for dObjects (subclasses of dStruct).
//

spl_autoload_register(function ($class) {
	$substr = substr($class, 0, 2);
	if ('ds'==$substr || 'dt'==$substr) { include $class . '.php'; }
} );

//
// !Represents an object stored in the database.
//

class dStruct {

	const KEY_IDEE = 'i';
	const OWNS = 'owns';
	const GNAME = 'gname';

	public $cnxn;
	public $idee;
	public $key;
	// Pools of fields that need to be modified at next commit.
	protected $values;
	protected $origValues;
	protected $insertQueue;
	protected $deleteQueue;
	protected $updateQueue;
	static protected $fieldDefCache = [];
	static protected $refDefCache = [];

	static function createNew($values=[], $cnxnKey=null) {
		$cnxn = dConnection::shared($cnxnKey);
		return self::createNewWithConnection($cnxn, $values);
	}

	static function createNewWithConnection($cnxn, $values=[]) {
		$cnxn->confirmTransaction('createNew');
		$gname = get_called_class();
		/*cnxn_error_log('creating new object of type ' . $gname);*/
		if (!($item = $cnxn->popZombie($gname))) { $item = new $gname($cnxn, null, null); } // If we have a zombie, use it, otherwise create new.
		foreach ($values as $fname=>$value) { $item->__set($fname, $value); } // Setting the values here marks them all for insertion.
		$cnxn->confirmStruct($item); // Confirm the struct -- make sure the database knows about gnames and fnames and we have an idee.
		return $item;
	}

	function __construct($cnxn, $idee, $values) { // Create a dStruct object from values read in from the database. No fields are marked for edits.
		$this->cnxn = $cnxn;
		$this->idee = $idee;
		$this->values = $values;
	}

	function copyStruct() { // Perform a deep copy. Make new copies of any fields that are owned refs so that we don't end up with two objects each claiming ownership of the same owned ref.
		$this->cnxn->confirmTransaction('copyStruct');
		$gname = get_class($this);
		$values_array = array();
		foreach ($this->values as $fname=>$value) {
			if ($this->fnameIsRef($fname) && $this->ownsRef($fname)) {
				if (is_array($value)) { $value = array_map(function($s) { return $s->copyStruct()->idee; }, $this->structsFromRefArray($fname)); }
				else { $value = $this->structFromRefField($fname)->copyStruct()->idee; }
			}
			$values_array[$fname] = $value;
		}
		$item = $gname::createNewWithConnection($this->cnxn, $values_array);
		/*cnxn_error_log("copied $this into $item");*/
		return $item;
	}

	function nullify() { foreach (array_keys($this->values) as $fname) { $this->__set($fname, null); } }

	function __toString() {
		$key = $this->getKey();
		return get_class($this) . '(' . $this->idee . ( $key ? ':' . $key : '' ) . ')';
	}

	// ///
	// 	section : Field definitions.
	//

	// ///
	// 	Returns fields defined by this object, each mapped to a type (which is
	// 	one of \dStruct\dConnection::d<TYPE> and matches the database table
	// 	used to store the field). For example, a sublcass might return
	// 	```
	// 		[ 'name'=>dConnection::dCONCAT, 'address'=>dConnection::dCONCAT, ]
	// 	Subclasses do not call parent, that will be handled by `fieldDefs`.
	static protected function selfFieldDefs($childs=null) { return []; }

	// ///
	// 	Returns array defining ownership and gname for each ref pointed at by
	// 	this class. For example, a subclass might return
	// 	```
	// 		[
	// 			'user'=>[ static::OWNS=>false, static::GNAME=>'dsUser', ],
	// 			'entries'=>[ static::OWNS=>true, static::GNAME=>'dsPosts', ],
	// 		]
	// 	Subclasses do not call parent, that will be handled by `refDefs`.
	static protected function selfRefDefs($childs=null) { return []; }

	// ///
	// 	section : Managing field and ref definitions
	//

	// ///
	// 	Returns fields defined by this object, its traits, and its parents (and
	// 	their traits). Caches results for faster lookup.
	static function fieldDefs($childs=null) {
		$gname = get_called_class();
		$key = $gname . ( $childs ? '::' . $childs : '' );
		if (key_exists($key, static::$fieldDefCache)) { return static::$fieldDefCache[$key]; }
		$defs = static::selfFieldDefs($key);
		foreach (class_uses($gname) as $trait) {
			if ('dt' != substr($trait, 0, 2)) { continue; }
			$traitName = substr($trait, 2);
			$callable = [ $gname, 'fieldDefs' . $traitName, ];
			$defs = array_merge($defs, call_user_func($callable, $key));
		}
		if ($parent = get_parent_class($gname)) {
			$defs = array_merge($defs, $parent::fieldDefs($key));
		}
		static::$fieldDefCache[$key] = $defs;
		return $defs;
	}

	// ///
	// 	Returns array defining ownership and gnames for each `ref` pointed at
	// 	by this class, its traits, and its parents (and their traits). Caches
	// 	results for faster lookup.
	static protected function refDefs($childs=null) {
		$gname = get_called_class();
		$key = $gname . ( $childs ? '::' . $childs : '' );
		if (key_exists($key, static::$refDefCache)) { return static::$refDefCache[$key]; }
		$defs = static::selfRefDefs($key);
		foreach (class_uses($gname) as $trait) {
			if ('dt' != substr($trait, 0, 2)) { continue; }
			$traitName = substr($trait, 2);
			$callable = [ $gname, 'refDefs' . $traitName, ];
			$defs = array_merge($defs, call_user_func($callable, $key));
		}
		if ($parent = get_parent_class($gname)) {
			$defs = array_merge($defs, $parent::refDefs($key));
		}
		static::$refDefCache[$key] = $defs;
		return $defs;
	}

	// ///
	// 	Returns `true` or `false` if `fname` is an owned ref of this class.
	// 	Throws an exception if `fname` is unrecognized.
	static function ownsRef($fname) {
		$refDefs = static::refDefs();
		$owns = $refDefs[$fname][static::OWNS];
		if ($owns === null) {
			throw new \Exception("Invalid fname `$fname` called for ownsRef");
		}
		return $owns;
	}

	// ///
	// 	Returns a `gname` for the ref pointed at by field `fname` of this
	// 	class. Throws an exception if `fname` is unrecognized.
	static function gnameForRef($fname) {
		$refDefs = static::refDefs();
		$gname = $refDefs[$fname][static::GNAME];
		if ($gname === null) {
			throw new \Exception("Invalid fname `$fname` called for gnameForRef");
		}
		return $gname;
	}

	static function gnameForKey($fname) {
		// Return the gname of the object pointed at in this object's key field.
		// Subclasses need to return a proper fname for recognized fields, and call super otherwise, which will throw an exception.
		throw new \Exception('Invalid fname ' . $fname . ' called for gnameForKeyField');
	}

	//
	// !Methods used to construct ULR parameters related to an object.
	//

	function getIdeeLink() { return KEY_IDEE . '=' . $this->idee; }

	function getFragment() { return substr_replace(get_class($this), '', 0, 2) . '_' . $this->idee; }

	//
	// !Methods for determining field type of a given fname.
	//

	function fnameIsRef($fname) { return (dConnection::dREF == static::fieldDefs()[$fname]); }

	function fnameIsConcat($fname) { return (dConnection::dCONCAT == static::fieldDefs()[$fname]); }

	//
	// !Getting and setting the key for an object.
	//

	function fetchKey() { if (!$this->key && ($key = $this->cnxn->fetchKeyForStruct($this))) { $this->key = $key; } }

	static function shouldFetchKey() { return false; } // Subclasses can override to indicate the connection should look for a key when the struct is fetched.

	function setKey($key) {
		$this->cnxn->confirmTransaction('setKey');
		$currKey = $this->key;
		if (!is_null($currKey) && is_null($key)) {
			$this->cnxn->unregisterStructWithKey($this, $currKey);
		} else if (is_null($currKey) && !is_null($key)) {
			$this->cnxn->registerStructWithKey($this, $key);
		} else if ($currKey != $key) {
			$this->cnxn->unregisterStructWithKey($this, $currKey);
			$this->cnxn->registerStructWithKey($this, $key);
		}
		$this->key = $key;
	}

	function getKey() { return $this->key; }

	//
	// !Accessors
	//

	function __get($fname) {
		if (!array_key_exists($fname, $this->values)) { return null; }
		return $this->values[$fname];
	}

/**
* This override allows the dStruct object to capture 'set' actions on a field and record it for later syncing with the backing store. This means a user of a dstruct object can call
* $myStruct->someProperty = 'hello'
* and the new value of `someProperty` will be queued up for insert into the database.
* This works for setting scalars and arrays, but it will not work to append to arrays. In other words, one can't make this call:
* $myStruct->otherPropArray[] = $value;
*/
	function __set($fname, $value) {
		$this->cnxn->confirmTransaction('__set');
		// Determine the original value (since last commit).
		if (!array_key_exists($fname, (array)$this->origValues)) { $this->origValues[$fname] = $this->values[$fname]; }
		$origValue = $this->origValues[$fname];
		// Check for zombies.
		if (!is_null($this->values[$fname]) && $this->fnameIsRef($fname) && $this->ownsRef($fname)) { // Test here is: are we about to blow away an owned ref and leave it stranded as a zombie in the database?.
			if ($this->cnxn->inZombieMode()) { zombie_error_log("dStruct::__set: pushing zombies for {$this}->{$fname}"); foreach ((array)$this->structsFromRefArray($fname) as $struct) { $this->cnxn->pushZombie($struct); } }
			else if ($this->cnxn->shouldWarnZombie()) { throw new \Exception("ZOMBIE fatal: {$this}->{$fname} being set to " . ( $value ? $value : 'null' ) . " is an owned reference to {$this->gnameForRef($fname)}."); }
		}
		// Determine what action to take based on old and new value.
		unset($this->insertQueue[$fname]);
		unset($this->updateQueue[$fname]);
		unset($this->deleteQueue[$fname]);
		if (is_null($origValue) && !is_null($value)) { $this->insertQueue[$fname] = true; $msg = 'INSERT'; }
		else if (!is_null($origValue) && is_null($value)) { $this->deleteQueue[$fname] = true; $msg = 'DELETE'; }
		else if ($origValue != $value) { $this->updateQueue[$fname] = true; $msg = 'UPDATE'; }
		else { $msg = 'NO CHANGE'; }
		// Queue this struct up for autocommit.
		$this->cnxn->queueForCommit($this);
		// Set the value.
		$this->values[$fname] = $value;
		/*cnxn_error_log("{$this}->$fname <= [" . ( is_null($value) ? 'NULL' : $value ) . '] was [' . ( is_null($origValue) ? 'NULL' : $origValue ) . '] :: ' . $msg);*/
	}

	function __isset($fname) { return array_key_exists($fname, $this->values); }

	function getValues() { return $this->values; }

	function rollback() { foreach ((array)$this->origValues as $fname=>$value) { $this->values[$fname] = $value; } } // Restore fields to their original values in case of a database ROLLBACK.

	//
	// !Commit queued transactions.
	//

	function getActions($queue, $action, $gname) {
		$actions = [];
		foreach ((array)$queue as $fname=>$test) {
			if (!$test) { continue; } // ??? When is this ever false?
			$actions[] = [
				'action'=>$action,
				'fname'=>$fname,
				'table'=>$this->cnxn->tableForFname($gname, $fname),
			];
		}
		return $actions;
	}

	function commitStruct() {
		$this->cnxn->confirmTransaction('commitStruct');
		$this->cnxn->confirmStruct($this); // Confirm we have category, codes, and idee assigned.
		$gname = get_class($this);
		// Create a table of combined "actions" that merges inserts, updates, and
		// deletes and lets us sort the results by table.
		$actions = [];
		$actions = array_merge($actions, $this->getActions($this->insertQueue, '1-INS', $gname));
		$actions = array_merge($actions, $this->getActions($this->updateQueue, '2-UPD', $gname));
		$actions = array_merge($actions, $this->getActions($this->deleteQueue, '3-DEL', $gname));
		usort($actions, function($a, $b) {
			$result = strcmp($a['table'], $b['table']) or strcmp($a['fname'], $b['fname']);
			return $result;
		});
		foreach ($actions as $info) {
			$action = $info['action'];
			$fname = $info['fname'];
			error_log('action ' . $action . ' for fname ' . $fname . ' in table ' . $info['table']);
			if ('1-INS' == $action) {
				$this->cnxn->insertField($this, $fname, $this->values[$fname]);
			} else if ('2-UPD' == $action) {
				$this->cnxn->updateField($this, $fname, $this->values[$fname]);
			} else if ('3-DEL' == $action) {
				$this->cnxn->deleteField($this, $fname);
			}
		}
		// Clear out the queues and original values. Note: We don't use unset() here on object properties, or they behave strangely afterward.
		$this->insertQueue = null;
		$this->deleteQueue = null;
		$this->updateQueue = null;
		$this->origValues = null;
	}

	//
	// !Return a struct stored as a ref.
	//

	function structFromRefField($fname) { return $this->cnxn->fetchStructForIdee($this->gnameForRef($fname), $this->values[$fname]); }

	//
	// !Return array of structs stored in a ref array.
	//

	function structsFromRefArray($fname) {
		$gname = $this->gnameForRef($fname);
		$cnxn = $this->cnxn;
		return array_map(function($idee) use($cnxn, $gname) { return $cnxn->fetchStructForIdee($gname, $idee); }, (array)$this->values[$fname]);
	}

	//
	// !Return a struct stored as a key.
	//

	function structFromKeyField($fname) { return $this->cnxn->fetchStructForKey($this->gnameForKeyField($fname), $this->values[$fname]); }

	//
	// !Return array of structs stored in a key array.
	//

	function structsFromKeyArray($fname) {
		$gname = $this->gnameForRef($fname);
		$cnxn = $this->cnxn;
		return array_map(function($key) use($cnxn, $gname) { return $cnxn->fetchStructForKey($gname, $key); }, (array)$this->values[$fname]);
	}

	//
	// !Delete struct and any structs it references and owns, also unregister any key.
	//

	function deleteStruct() { //NOTE: Deleting removes the struct from the database, but the fields and idee are still available within the object.
		$this->cnxn->confirmTransaction('deleteStruct');
		$this->fetchKey(); // Make sure we know about the struct's key (if any) before deleting.
		$this->setKey(null); // Cause an unregister if key exists.
		foreach ($this->values as $fname=>$value) {
			if ($this->fnameIsRef($fname) && $this->ownsRef($fname)) {
				foreach ((array)$value as $idee) {
					if ($item = $this->cnxn->fetchStructForIdee($this->gnameForRef($fname), $idee)) { $item->deleteStruct(); }
				}
			}
		}
		$this->cnxn->deleteStructWithIdee(get_class($this), $this->idee);
	}

	//
	// !Handle adding and removing single elements to/from owned ref arrays.
	//

	function addToRefArray($fname, $children, $sort_callback=null) {
		if ($this->cnxn->inZombieMode()) { throw new \Exception('Error addToRefArray while in zombie mode'); }
		if (!is_array($children)) { throw new \Exception('addToRefArray children must be an array'); }
		$this->cnxn->confirmTransaction('addToRefArray');
		if (!$this->fnameIsRef($fname) || !$this->ownsRef($fname)) { throw new \Exception("Error: addToRefArray fname $fname is not a ref or is not owned by $this."); }
		$this->cnxn->pauseZombieWarnings();
		$newKids = array_merge((array)$this->values[$fname], array_map(function($c) { return $c->idee; }, $children));
		if ($sort_callback) { usort($newKids, $sort_callback); }
		$this->__set($fname, $newKids);
		$this->cnxn->resumeZombieWarnings();
	}

	function removeFromRefArray($fname, $children) { // Delete struct and remove from parent's array.
		if ($this->cnxn->inZombieMode()) { throw new \Exception('Error removeFromRefArray while in zombie mode'); }
		if (!is_array($children)) { throw new \Exception('removeFromRefArray children must be an array'); }
		$this->cnxn->confirmTransaction('removeFromRefArray');
		if (!$this->fnameIsRef($fname) || !$this->ownsRef($fname)) { throw new \Exception("Error: removeFromRefArray fname $fname is not a ref or is not owned by $this."); }
		array_walk($children, function($c) { $c->deleteStruct(); });
		$this->cnxn->pauseZombieWarnings();
		$this->__set($fname, array_diff((array)$this->values[$fname], array_map(function($c) { return $c->idee; }, $children)));
		$this->cnxn->resumeZombieWarnings();
	}

} // class dStruct
