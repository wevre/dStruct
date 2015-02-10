<?php
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
* @version 1.1
*
*/

/** Autoloader for dObjects (subclasses of dStruct). */

function dStructAutoload($classname) { if ('ds' == substr($classname, 0, 2)) { include $classname . '.php'; } }

spl_autoload_register('dStructAutoload');

require_once 'dStruct/dconnection.php';

/** Constant used to construct URL queries. */

define('KEY_IDEE', 'i');

//**  Represents an object stored in the database. */

class dStruct {

	public $cnxn;
	public $idee;
	public $key;
	// pools of fields that need to be modified at next commit
	protected $values;
	protected $origValues;
	protected $insertQueue;
	protected $deleteQueue;
	protected $updateQueue;

	static function createNew($cnxn, $values=array()) {
		$cnxn->confirmTransaction('createNew');
		$gname = get_called_class();
		//cnxn_error_log('creating new object of type ' . $gname);
		if (!($item = $cnxn->popZombie($gname))) { $item = new $gname($cnxn, null, null); } // if we have a zombie, use it, otherwise create new
		foreach ($values as $fname=>$value) { $item->__set($fname, $value); } // setting the values here marks them all for insertion
		$cnxn->confirmStruct($item); // confirm the struct -- make sure the database knows about gnames and fnames and we have an idee
		return $item;
	}

	function __construct($cnxn, $idee, $values) { // this constructor creates a dStruct object from values read in from the database; no fields are marked for edits
		$this->cnxn = $cnxn;
		$this->idee = $idee;
		$this->values = $values;
	}

	function copyStruct() { // does a deep copy, specifically it makes new copies of any fields that are owned refs so that we don't end up with two objects each claiming ownership of the same owned ref
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
		$item = $gname::createNew($this->cnxn, $values_array);
		//cnxn_error_log("copied $this into $item");
		return $item;
	}

	function nullify() { foreach (array_keys($this->values) as $fname) { $this->__set($fname, null); } }

	function __toString() { return get_class($this) . '(' . $this->idee . ')'; }

	// -----------------------------
	//! Field Definitions -- for subclasses to override

	function dStructFieldDefs() {
		// returns the fields defined by this object, as key value pairs, such as fname=>value, with field name (the key) mapped to the field type (the value); note that field type is equivalent to the mysql table that stores the field
		// subclasses should override this method to provide their distinct fields
		// note the implementation as an instance method, and not as a static method -- this means we can only query field defs on existing objects, and not on the class; that should be okay, because for existing data, the known code and category definitions are already captured in the def_ tables; even if a new field had popped up in the class definition, no data for that field would yet exist in the database
		// note also that we have not provided any mechanism for class inheritance, but subclass heirarchies are free to do so
		return array();
	}

	function ownsRef($fname) {
		// returns true if the called object "owns" the referenced object. Owned objects will be deleted along with the object
		// subclasses need to return true or false if the passed in fname is one of their fields, otherwise call super which will throw this exception
		throw new Exception('Invalid fname ' . $fname . ' called for ownsRef');
	}

	function gnameForRefField($fname) {
		// returns the gname of the object pointed at in this object's ref field
		// subclasses need to return a proper fname for recognized fields, and call super otherwise, which will throw an exception
		throw new Exception('Invalid fname ' . $fname . ' called for gnameForRefField');
	}

	function gnameForKeyField($fname) {
		// returns the gname of the object pointed at in this object's key field
		// subclasses need to return a proper fname for recognized fields, and call super otherwise, which will throw an exception
		throw new Exception('Invalid fname ' . $fname . ' called for gnameForKeyField');
	}

	function getIdeeLink() { return KEY_IDEE . '=' . $this->idee; }

	function getFragment() { return substr_replace(get_class($this), '', 0, 2) . '_' . $this->idee; }

	// -----------------------------
	//! Methods for determining field type of a given fname

	function fnameIsRef($fname) { $fields = $this->dStructFieldDefs(); return (dREF == $fields[$fname]); }

	function fnameIsConcat($fname) { $fields = $this->dStructFieldDefs(); return (dCONCAT == $fields[$fname]); }

	// -----------------------------
	//! Getting and setting the key for an object

	function fetchKey() { if (!$this->key && ($key = $this->cnxn->fetchKeyForStruct($this))) { $this->key = $key; } }

	function shouldFetchKey() { return false; } // subclasses can override to indicate the connection should look for a key when the struct is fetched

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

	// -----------------------------
	//! Accessors

	function __get($fname) {
		if (!array_key_exists($fname, $this->values)) { return null; }
		return $this->values[$fname];
	}

	function __set($fname, $value) {
		$this->cnxn->confirmTransaction('__set');
		// determine the original value (since last commit)
		if (!array_key_exists($fname, (array)$this->origValues)) { $this->origValues[$fname] = $this->values[$fname]; }
		$origValue = $this->origValues[$fname];
		// check for zombies
		if (!is_null($this->values[$fname]) && $this->fnameIsRef($fname) && $this->ownsRef($fname)) { // test here is: are we about to blow away an owned ref and leave it stranded as a zombie in the database?
			if ($this->cnxn->inZombieMode()) { zombie_error_log("dstruct::__set: pushing zombies for {$this}->{$fname}"); foreach ((array)$this->structsFromRefArray($fname) as $struct) { $this->cnxn->pushZombie($struct); } }
			else if ($this->cnxn->shouldWarnZombie()) { throw new Exception("ZOMBIE fatal: {$this}->{$fname} being set to " . ( $value ? $value : 'null' ) . " is an owned reference to {$this->gnameForRefField($fname)}."); }
		}
		// determine what action to take based on old and new value
		unset($this->insertQueue[$fname]);
		unset($this->updateQueue[$fname]);
		unset($this->deleteQueue[$fname]);
		if (is_null($origValue) && !is_null($value)) { $this->insertQueue[$fname] = true; $msg = 'INSERT'; }
		else if (!is_null($origValue) && is_null($value)) { $this->deleteQueue[$fname] = true; $msg = 'DELETE'; }
		else if ($origValue != $value) { $this->updateQueue[$fname] = true; $msg = 'UPDATE'; }
		else { $msg = 'NO CHANGE'; }
		// queue this struct up for autocommit
		$this->cnxn->queueForCommit($this);
		// set the value
		$this->values[$fname] = $value;
		//cnxn_error_log("{$this}->$fname <= [" . ( is_null($value) ? 'NULL' : $value ) . '] was [' . ( is_null($origValue) ? 'NULL' : $origValue ) . '] :: ' . $msg);
	}

	function __isset($fname) { return array_key_exists($fname, $this->values); }

	function getValues() { return $this->values; }

	function rollback() { foreach ((array)$this->origValues as $fname=>$value) { $this->values[$fname] = $value; } } // restore fields to their original values in case of a database ROLLBACK

	// -----------------------------
	//! Committing queued transactions

	function commitStruct() {
		$this->cnxn->confirmTransaction('commitStruct');
		$this->cnxn->confirmStruct($this); // confirms we have category, codes, and idee assigned
		//cnxn_error_log("committing $this");
		foreach ((array)$this->insertQueue as $fname=>$test) { if ($test) { $this->cnxn->insertField($this, $fname, $this->values[$fname]); } }
		foreach ((array)$this->updateQueue as $fname=>$test) { if ($test) { $this->cnxn->updateField($this, $fname, $this->values[$fname]); } }
		foreach ((array)$this->deleteQueue as $fname=>$test) { if ($test) { $this->cnxn->deleteField($this, $fname); } }
		// clear out the queues and original values //NOTE: we don't use unset() here on object properties, or they behave strangely afterward
		$this->insertQueue = null;
		$this->deleteQueue = null;
		$this->updateQueue = null;
		$this->origValues = null;
	}

	// -----------------------------
	//! Returning a struct stored as a ref

	function structFromRefField($fname) { return $this->cnxn->fetchStructForIdee($this->gnameForRefField($fname), $this->values[$fname]); }

	// -----------------------------
	//! Returning structs stored in a ref array

	function structsFromRefArray($fname) {
		$gname = $this->gnameForRefField($fname);
		$cnxn = $this->cnxn;
		return array_map(function($idee) use($cnxn, $gname) { return $cnxn->fetchStructForIdee($gname, $idee); }, (array)$this->values[$fname]);
	}

	// -----------------------------
	//! Returning a struct stored as a key

	function structFromKeyField($fname) { return $this->cnxn->fetchStructForKey($this->gnameForKeyField($fname), $this->values[$fname]); }

	// -----------------------------
	//! Returning structs stored in a key array

	function structsFromKeyArray($fname) {
		$gname = $this->gnameForRefField($fname);
		$cnxn = $this->cnxn;
		return array_map(function($key) use($cnxn, $gname) { return $cnxn->fetchStructForKey($gname, $key); }, (array)$this->values[$fname]);
	}

	// -----------------------------
	//! Delete this struct and any structs it references and owns, also unregisters a key

	function deleteStruct() { //NOTE: deleting removes the struct from the database, but the fields and idee are still available within the object
		$this->cnxn->confirmTransaction('deleteStruct');
		$this->fetchKey(); // make sure we know about the struct's key (if any) before deleting
		$this->setKey(null); // causes an unregister if key c’è
		foreach ($this->values as $fname=>$value) {
			if ($this->fnameIsRef($fname) && $this->ownsRef($fname)) {
				foreach ((array)$value as $idee) {
					if ($item = $this->cnxn->fetchStructForIdee($this->gnameForRefField($fname), $idee)) { $item->deleteStruct(); }
				}
			}
		}
		$this->cnxn->deleteStructWithIdee(get_class($this), $this->idee);
	}

	// -----------------------------
	//! Handle adding and removing single elements to/from owned ref arrays

	function addToRefArray($fname, $children, $sort_callback=null) {
		if ($this->cnxn->inZombieMode()) { throw new Exception('Error addToRefArray while in zombie mode'); }
		if (!is_array($children)) { throw new Exception('addToRefArray children must be an array'); }
		$this->cnxn->confirmTransaction('addToRefArray');
		if (!$this->fnameIsRef($fname) || !$this->ownsRef($fname)) { throw new Exception("Error: addToRefArray fname $fname is not a ref or is not owned by $this."); }
		$this->cnxn->pauseZombieWarnings();
		$newKids = array_merge((array)$this->values[$fname], array_map(function($c) { return $c->idee; }, $children));
		if ($sort_callback) { usort($newKids, $sort_callback); }
		$this->__set($fname, $newKids);
		$this->cnxn->resumeZombieWarnings();
	}

	function removeFromRefArray($fname, $children) { // deletes struct and removes from parent's array
		if ($this->cnxn->inZombieMode()) { throw new Exception('Error removeFromRefArray while in zombie mode'); }
		if (!is_array($children)) { throw new Exception('removeFromRefArray children must be an array'); }
		$this->cnxn->confirmTransaction('removeFromRefArray');
		if (!$this->fnameIsRef($fname) || !$this->ownsRef($fname)) { throw new Exception("Error: removeFromRefArray fname $fname is not a ref or is not owned by $this."); }
		array_walk($children, function($c) { $c->deleteStruct(); });
		$this->cnxn->pauseZombieWarnings();
		$this->__set($fname, array_diff((array)$this->values[$fname], array_map(function($c) { return $c->idee; }, $children)));
		$this->cnxn->resumeZombieWarnings();
	}

} // class dStruct

?>
