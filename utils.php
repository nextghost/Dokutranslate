<?php
/**
 * DokuWiki Plugin dokutranslate (Misc functions)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Martin Doucha <next_ghost@quick.cz>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

define('DOKUTRANSLATE_MODLIST', DOKU_INC . 'conf/dokutranslate.modlist.conf');

$DOKUTRANSLATE_EDITFORM = '';

# Read cleaned instructions for file and group them by paragraphs
function getCleanInstructions($file) {
	$instructions = p_cached_instructions($file);
	$ret = array();
	$i = 0;

	foreach ($instructions as $ins) {
		switch ($ins[0]) {
		# Filter out sections and document start/end instructions
		case 'document_start':
		case 'document_end':
		case 'section_open':
		case 'section_close':
			break;

		# Start new block of instructions on paragraph end
		case 'p_close':
			$ret[$i++][] = $ins;
			break;

		# Add the instruction to current block
		default:
			$ret[$i][] = $ins;
			break;
		}
	}

	return $ret;
}

function dataPath($id) {
	return dirname(wikiFN($id)) . '/_' . noNS($id);
}

function getParID() {
	return isset($_REQUEST['parid']) ? intval($_REQUEST['parid']) : 0;
}

# Read the modlist file and return array of lines
function loadModlist() {
	$ret = @file(DOKUTRANSLATE_MODLIST);

	return $ret === false ? array() : $ret;
}

# Parse array of modlist lines and return array(ns => modgroup)
function parseModlist($lines) {
	$ret = array();

	foreach ($lines as $line) {
		$line = trim(preg_replace('/#.*$/', '', $line)); //ignore comments
		if (!$line) {
			continue;
		}

		$entry = preg_split('/\s+/', $line);
		$entry[1] = rawurldecode($entry[1]);
		$ret[$entry[0]] = $entry[1];
	}

	return $ret;
}

# Check if current user has moderator privileges for given page ID
function isModerator($id) {
	global $USERINFO;
	static $modlist = NULL;

	# Not logged in
	if (empty($_SERVER['REMOTE_USER'])) {
		return false;
	}

	if (is_null($modlist)) {
		$modlist = parseModlist(loadModlist());
	}

	# Check nearest non-root parent namespace
	for ($ns = getNS($id); $ns; $ns = getNS($ns)) {
		$wildcard = $ns . ':*';

		if (!empty($modlist[$wildcard])) {
			return in_array($modlist[$wildcard], $USERINFO['grps']);
		}
	}

	# Check root namespace
	if (!empty($modlist['*'])) {
		return in_array($modlist['*'], $USERINFO['grps']);
	}

	# No moderator group set for any of parent namespaces
	return false;
}

function canReview($id, $meta, $parid) {
	return isModerator($id) && $meta[$parid]['user'] != $_SERVER['REMOTE_USER'] && $meta[$parid]['ip'] != clientIP(true);

}

// vim:ts=4:sw=4:et:
