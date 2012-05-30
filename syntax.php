<?php
/**
 * DokuWiki Plugin dokutranslate (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Martin Doucha <next_ghost@quick.cz>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'syntax.php';

# Nesting counter, patterns disabled when non-zero
$DOKUTRANSLATE_NEST = 0;

class syntax_plugin_dokutranslate extends DokuWiki_Syntax_Plugin {
	private $origIns = NULL;

	public function getType() {
		return 'container';
	}

	public function getPType() {
		return 'stack';
	}

	public function getSort() {
		return 100;
	}

	public function getAllowedTypes() {
		return array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
	}

	public function isSingleton() {
		return true;
	}

	public function connectTo($mode) {
		global $ID;

		# Disable patterns when the page is not being translated or
		# we're building instructions for original page
		if (!@file_exists(metaFN($ID, '.translate')) || $DOKUTRANSLATE_NEST > 0) {
			return;
		}

		$this->Lexer->addEntryPattern('~~DOKUTRANSLATE_START~~(?=.*~~DOKUTRANSLATE_END~~)',$mode,'plugin_dokutranslate');
		$this->Lexer->addSpecialPattern('~~DOKUTRANSLATE_PARAGRAPH~~','plugin_dokutranslate','plugin_dokutranslate');
	}

	public function postConnect() {
		global $ID;

		# Disable patterns when the page is not being translated or
		# we're building instructions for original page
		if (!@file_exists(metaFN($ID, '.translate')) || $DOKUTRANSLATE_NEST > 0) {
			return;
		}

		$this->Lexer->addExitPattern('~~DOKUTRANSLATE_END~~','plugin_dokutranslate');
	}

	public function handle($match, $state, $pos, &$handler){
		switch ($state) {
		case DOKU_LEXER_ENTER:
		case DOKU_LEXER_EXIT:
		case DOKU_LEXER_SPECIAL:
			return array($state, $pos, $pos + strlen($match));
		}

		return array($state, $match);
	}

	public function render($mode, &$renderer, $data) {
		global $ID;

		if($mode != 'xhtml') return false;

		# Load instructions for original text on first call
		if (is_null($this->origIns)) {
			$DOKUTRANSLATE_NEST++;
			$this->origIns = getCleanInstructions(dataPath($ID) . '/orig.txt');
			$DOKUTRANSLATE_NEST--;
		}

		switch ($data[0]) {
		# Open the table
		case DOKU_LEXER_ENTER:
			$renderer->doc .= '<table width="100%"><tbody><tr><td width="50%">';
			break;

		# Dump original text and close the row
		case DOKU_LEXER_SPECIAL:
			$renderer->doc .= '</td><td>';

			# If this condition fails, somebody's been messing
			# with the data
			if (current($this->origIns) !== FALSE) {
				$renderer->nest(current($this->origIns));
				next($this->origIns);
			}

			$renderer->doc .= '</td></tr><tr><td>';
			break;

		# Dump the rest of the original text and close the table
		case DOKU_LEXER_EXIT:
			$renderer->doc .= '</td><td>';

			# Loop to make sure all remaining text gets dumped
			# (external edit safety)
			while (current($this->origIns) !== FALSE) {
				$renderer->nest(current($this->origIns));
				next($this->origIns);
			}

			$renderer->doc .= '</td></tr></tbody></table>';
			break;

		# Just sanitize and dump the text
		default:
			$renderer->doc .= $renderer->_xmlEntities($data[1]);
			break;
		}

		return true;
	}
}

// vim:ts=4:sw=4:et:
