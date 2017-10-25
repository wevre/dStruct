<?php
namespace dStruct;
/**
* Generates an HTML page for viewing dStruct objects.
*
* @copyright 2014-2015 Mike Weaver
*
* @license http://www.opensource.org/licenses/mit-license.html
*
* @author Mike Weaver
* @created 2014-02-12
*
* @version 1.1
*
*/

define('KEY_DOMAIN', 'd');
define('KEY_GNAME', 'g');
define('KEY_IDEE', 'i');

function fetchDomains($mysqli) {
	if (!($result = $mysqli->query('SELECT domain, dname FROM def_domain ORDER BY domain FOR UPDATE', MYSQLI_STORE_RESULT))) { throw new\Exception("Execute Error: {$mysqli->error}", $mysqli->errno); }
	while (list($domain, $dname) = $result->fetch_row()) { $data[$domain] = $dname; }
	$result->close();
	return $data;
}

function fetchCategories($mysqli) {
	if (!($result = $mysqli->query('SELECT category, gname FROM def_category ORDER BY category FOR UPDATE', MYSQLI_STORE_RESULT))) { throw new\Exception("Execute Error: {$mysqli->error}", $mysqli->errno); }
	while (list($category, $gname) = $result->fetch_row()) { $data[$category] = $gname; }
	$result->close();
	return $data;
}

function fetchCodesForCategory($mysqli, $category) {
	if (!($stmt = $mysqli->prepare('SELECT code, fname, `table` FROM def_code WHERE category = ? ORDER BY category, code FOR UPDATE'))) { throw new\Exception("Statement Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->bind_param('i', $category)) { throw new\Exception("Bind Param Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->execute()) { throw new\Exception("Execute Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->bind_result($code, $fname, $table)) { throw new\Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
	while ($stmt->fetch()) { $data[$code] = array('code'=>$code, 'fname'=>$fname, 'table'=>$table, ); }
	if ($stmt->error) { throw new\Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
	$stmt->close();
	return $data;
}

function fetchStructsForCategory($mysqli, $domain, $category) {
	// Fetch the idees from meta_idees.
	if (!($stmt = $mysqli->prepare('SELECT idee FROM meta_idees WHERE domain = ? AND category = ? ORDER BY idee FOR UPDATE'))) { throw new\Exception("Statement Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->bind_param('ii', $domain, $category)) { throw new\Exception("Bind Param Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->execute()) { throw new\Exception("Execute Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->bind_result($idee)) { throw new\Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
	while ($stmt->fetch()) { $data[$idee] = array('idee'=>$idee, ); }
	if ($stmt->error) { throw new\Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
	$stmt->close();
	// Then check for keys in meta_keys.
	if (!($stmt = $mysqli->prepare('SELECT idee, `key` FROM meta_keys WHERE domain = ? AND category = ? FOR UPDATE'))) { throw new\Exception("Statement Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->bind_param('ii', $domain, $category)) { throw new\Exception("Bind Param Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->execute()) { throw new\Exception("Execute Error: {$stmt->error}", $stmt->errno); }
	if (!$stmt->bind_result($idee, $key)) { throw new\Exception("Bind Result Error: {$stmt->error}", $stmt->errno); }
	while ($stmt->fetch()) { $data[$idee]['key'] = $key; }
	if ($stmt->error) { throw new\Exception("Fetch Error: {$stmt->error}", $stmt->errno); }
	$stmt->close();
	return $data;
}

function getValueDisplay($struct, $fname, $value) {
	global $link_pre, $base_name, $domain;
	if ($struct->fnameIsRef($fname)) {
		$ref_gname = $struct->gnameForRef($fname);
		return "<a href=\"{$link_pre}/{$base_name}?" . KEY_DOMAIN. "={$domain}&" . KEY_GNAME . "={$ref_gname}&" . KEY_IDEE . "={$value}\">{$ref_gname}({$value})</a>";
	} else {
		return htmlentities($value);
	}
}

function getStructValues($struct) {
	global $cnxn;
	$gname = get_class($struct);
	foreach ($struct->getValues() as $fname=>$value) {
		$code = $cnxn->codeForFname($gname, $fname);
		$data[$code] = array('code'=>$code, 'fname'=>$fname, 'value'=>$value, );
		if (is_array($value)) {
			$data[$code]['display'] = '<ul>' . implode('', array_map(function ($s,$v) use ($struct, $fname) { return "<li><p>[{$s}]=>" . getValueDisplay($struct, $fname, $v) . '</p></li>'; }, array_keys($value), $value)) . '</ul>';
		} else {
			$data[$code]['display'] = getValueDisplay($struct, $fname, $value);
		}
	}
	return $data;
}

//
// !Display dStruct objects from the database.
//

//NOTE: This script expects the caller to set up four important global variables:
//    $link_pre, which will precede all URL's;
//    $cnxn, which will be a dConnection;
//    $mysqli, a connection to the database; and,
//    $base_name, which will be the starting path of all URL's (after the $link_pre).
// Based on the above, links will all be {$link_pre}/{$base_name}/{$domain}/{$gname}/{$idee}.

$path = getURLPath();
$domain = $_GET[KEY_DOMAIN];
$gname = $_GET[KEY_GNAME];
$idee = $_GET[KEY_IDEE];

do try {
	if ($idee) { // Show the struct.
		if ($domain != $cnxn->getDomain()) { $cnxn = new dConnection($mysqli, $domain); }
		if (!($struct = $cnxn->fetchStructForIdee($gname, $idee))) { break; }
		$guts .= '<h1>' . "{$gname}({$idee}" . ( $struct->getKey() ? "&mdash;{$struct->getKey()}" : '' ) . ')</h1><ul>';
		foreach (getStructValues($struct) as $code=>$details) {
			$guts .= "<li><p>{$code}-{$details['fname']}: {$details['display']}</p></li>";
		}
		$guts .= '</ul>';
	}
	else if ($gname) { // Show a list of fnames and then a list of idees/keys.
		$category = $cnxn->categoryForGname($gname);
		$guts .= '<h1>' . $gname . '</h1>';
		$guts .= '<h2>Codes</h2><ul>';
		foreach (fetchCodesForCategory($mysqli, $category) as $code=>$details) { $guts .= "<li><p>{$code}-{$details['fname']} ({$details['table']})</p></li>"; }
		$guts .= '</ul>';
		$guts .= '<h2>Objects</h2><ul>';
		foreach (fetchStructsForCategory($mysqli, $domain, $category) as $idee=>$details) { $guts .= "<li><p><a href=\"{$link_pre}/{$base_name}?" . KEY_DOMAIN. "={$domain}&" . KEY_GNAME . "={$gname}&" . KEY_IDEE . "={$idee}\">{$gname}({$idee}" . ( $details['key'] ? "&mdash;{$details['key']}" : '' ) . ")</a></p></li>"; }
		$guts .= '</ul>';
	}
	else if ($domain) { // Show a list of gnames.
		$guts .= '<h1>Categories</h1><ul>';
		foreach (fetchCategories($mysqli) as $category=>$gname) { $guts .= "<li><p><a href=\"{$link_pre}/{$base_name}?" . KEY_DOMAIN. "={$domain}&" . KEY_GNAME . "={$gname}\">{$category}-{$gname}</a></p></li>"; }
		$guts .= '</ul>';
	}
	else { // Show a list of domains.
		$guts .= '<h1>Domains</h1><ul>';
		foreach (fetchDomains($mysqli) as $domain=>$dname) { $guts .= "<li><p><a href=\"{$link_pre}/{$base_name}?" . KEY_DOMAIN. "={$domain}\">{$domain}-{$dname}</a></p></li>"; }
		$guts .= '</ul>';
	}
	echo '<html><head><title>dView</title></head><body>' . $guts . '</body></html>';
	exit;
} catch \Exception $e) {
	header('Content-type: text-plain; charset=utf-8');
	echo "Error {$e->getCode()}: (line: {$e->getline()} of {$e->getfile()})\n{$e->getMessage()}\n{$e->getTraceAsString()}\n";
	exit;
} while (0);
echo '<html><head><title>dView</title></head><body><p>Nothing to show</p></body></html>';
exit;

?>
