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

// vim:ts=4:sw=4:et:
