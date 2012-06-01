<?php
/**
 * DokuWiki Plugin dokutranslate (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Martin Doucha <next_ghost@quick.cz>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'action.php';
# Needed for lexer state constants used in syntax plugin instructions
require_once DOKU_INC.'inc/parser/lexer.php';
require_once 'utils.php';

function allRevisions($id) {
	$ret = array();
	$lines = @file(metaFN($id, '.changes'));

	if (!$lines) {
		return $ret;
	}

	foreach ($lines as $line) {
		$tmp = parseChangelogLine($line);
		$ret[] = $tmp['date'];
	}

	return $ret;
}

function genTranslateFile($ins) {
	$ret = "~~DOKUTRANSLATE_START~~\n\n";
	$par = "~~DOKUTRANSLATE_PARAGRAPH~~\n\n";

	for ($i = 0; $i < count($ins) - 1; $i++) {
		$ret .= $par;
	}

	$ret .= "~~DOKUTRANSLATE_END~~";

	return $ret;
}

function genMeta($lineCount) {
	$ret = array();

	# Generate paragraph info
	for ($i = 0; $i < $lineCount; $i++) {
		$ret[$i]['changed'] = '';
		$ret[$i]['ip'] = clientIP(true);
		$ret[$i]['user'] = $_SERVER['REMOTE_USER'];
		$ret[$i]['reviews'] = array();
	}

	return $ret;
}

class action_plugin_dokutranslate extends DokuWiki_Action_Plugin {

	public function register(Doku_Event_Handler &$controller) {
		$this->setupLocale();
		$controller->register_hook('HTML_EDITFORM_OUTPUT', 'BEFORE', $this, 'handle_html_editform_output');
		$controller->register_hook('HTML_SECEDIT_BUTTON', 'BEFORE', $this, 'handle_disabled');
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_preprocess');
		$controller->register_hook('PARSER_HANDLER_DONE', 'BEFORE', $this, 'handle_parser_handler_done');
		$controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handle_tpl_act_render');
		$controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'handle_tpl_content_display');
	}

	public function handle_html_editform_output(Doku_Event &$event, $param) {
		global $ID;

		if (!@file_exists(metaFN($ID, '.translate'))) {
			# FIXME: Check for permission to begin translation

			# No submit button => preview, don't modify the form
			if(!$event->data->findElementByAttribute('type', 'submit')) {
				return;
			}

			# Place the checkbox after minor edit checkbox or
			# summary text box if minor edit checkbox is not present
			$pos = $event->data->findElementByAttribute('name', 'minor');

			if (!$pos) {
				$pos = $event->data->findElementByAttribute('name', 'summary');
			}

			# Create the checkbox
			$p = array('tabindex' => 4);

			if (!empty($_REQUEST['translate'])) {
				$p['checked'] = 'checked';
			}

			$elem = form_makeCheckboxField('translate', '1', $this->lang['translate_begin'], 'translate_begin', 'nowrap', $p);

			# Insert checkbox into the form
			$event->data->insertElement(++$pos, $elem);
		} else {
			# Translation in progress, add paragraph ID to the form
			$event->data->addHidden('parid', strval(getParID()));
		}
	}

	public function handle_action_act_preprocess(Doku_Event &$event, $param) {
		global $ID;
		global $TEXT;
		global $ACT;
		global $SUM;
		global $RANGE;

		# FIXME: Handle edits and reverts
		$act = act_clean($event->data);
		$act = act_permcheck($act);

		if ($act == 'save') {
			# Take over save action if translation is in progress
			# or we're starting it
			if (!@file_exists(metaFN($ID, '.translate')) && empty($_REQUEST['translate'])) {
				return;
			}

			if (!checkSecurityToken()) {
				return;
			}

			# We're starting a translation
			if (!@file_exists(metaFN($ID, '.translate')) && !empty($_REQUEST['translate'])) {
				# Take the event over
				$event->stopPropagation();
				$event->preventDefault();

				# Save the data but exit if it fails
				$ACT = act_save($act);

				if ($ACT != 'show') {
					return;
				}

				# Page was deleted, exit
				if (!@file_exists(wikiFN($ID))) {
					return;
				}

				# Prepare data path
				$datapath = dataPath($ID);
				io_mkdir_p($datapath, 0755, true);

				# Backup the original page
				io_rename(wikiFN($ID), $datapath . '/orig.txt');

				# Backup old revisions
				$revisions = allRevisions($ID);

				foreach ($revisions as $rev) {
					$tmp = wikiFN($ID, $rev);
					io_rename($tmp, $datapath . '/' . basename($tmp));
				}

				# Backup meta files
				$metas = metaFiles($ID);

				foreach ($metas as $f) {
					io_rename($f, $datapath . '/' . basename($f));
				}

				# Generate empty page to hold translated text
				$data = getCleanInstructions($datapath . '/orig.txt');
				saveWikiText($ID, genTranslateFile($data), $SUM, $_REQUEST['minor']);

				$translateMeta = genMeta(count($data));
				# create meta file for current translation state
				io_saveFile(metaFN($ID, '.translate'), serialize($translateMeta));
				# create separate meta file for translation history
				io_saveFile(metaFN($ID, '.translateHistory'), serialize(array('current' => $translateMeta)));
			}
		} else if (in_array($act, array('edit', 'preview'))) {
			if (!@file_exists(metaFN($ID, '.translate')) || isset($TEXT)) {
				return;
			}

			$parid = getParID();
			$instructions = p_cached_instructions(wikiFN($ID));
			$separators = array();

			# Build array of paragraph separators
			foreach ($instructions as $ins) {
				if ($ins[0] == 'plugin' && $ins[1][0] == 'dokutranslate' && in_array($ins[1][1][0], array(DOKU_LEXER_ENTER, DOKU_LEXER_SPECIAL, DOKU_LEXER_EXIT))) {
					$separators[] = $ins[1][1];
				}
			}

			# Validate paragraph ID
			if ($parid >= count($separators) - 1) {
				$parid = 0;
			}

			# Build range for paragraph
			$RANGE = strval($separators[$parid][2] + 1) . '-' . strval($separators[$parid + 1][1] - 1);
		}
	}

	public function handle_parser_handler_done(Doku_Event &$event, $param) {
		global $ID;
		$erase = array('section_open', 'section_close');

		# Exit if the page is not being translated
		if (!@file_exists(metaFN($ID, '.translate'))) {
			return;
		}

		$length = count($event->data->calls);

		# Erase section instructions from the instruction list
		for ($i = 0; $i < $length; $i++) {
			if (in_array($event->data->calls[$i][0], $erase)) {
				unset($event->data->calls[$i]);
			}
		}
	}

	# Hijack edit page rendering
	public function handle_tpl_act_render(Doku_Event &$event, $param) {
		global $ID;
		global $INFO;
		global $DOKUTRANSLATE_EDITFORM;

		if (!@file_exists(metaFN($ID, '.translate'))) {
			return;
		}

		# Disable TOC on translated pages
		$INFO['prependTOC'] = false;

		if (in_array($event->data, array('edit', 'preview'))) {
			# Take the event over
			$event->preventDefault();

			# Save the edit form for later
			html_edit();
			$DOKUTRANSLATE_EDITFORM = ob_get_clean();
			ob_start();

			# Render the page (renderer inserts saved edit form
			# and preview in the right cell)
			echo p_render('xhtml', p_cached_instructions(wikiFN($ID)), $INFO);
		}
	}

	# Erase content replaced by edit form
	public function handle_tpl_content_display(Doku_Event &$event, $param) {
		global $ID;

		if (!@file_exists(metaFN($ID, '.translate'))) {
			return;
		}

		# Erase everything between markers
		$event->data = preg_replace("/<!-- DOKUTRANSLATE ERASE START -->.*<!-- DOKUTRANSLATE ERASE STOP -->/sm", '', $event->data);
	}

	# Generic event eater
	public function handle_disabled(Doku_Event &$event, $param) {
		global $ID;

		# Translation in progress, eat the event
		if (@file_exists(metaFN($ID, '.translate'))) {
			$event->preventDefault();
		}

		return;
	}
}

// vim:ts=4:sw=4:et:
