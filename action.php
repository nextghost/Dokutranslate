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
	$ret = "~~DOKUTRANSLATE_START~~\n";
	$par = "~~DOKUTRANSLATE_PARAGRAPH~~\n";

	for ($i = 0; $i < count($ins) - 1; $i++) {
		$ret .= $par;
	}

	$ret .= "~~DOKUTRANSLATE_END~~\n";

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
		}
	}

	public function handle_action_act_preprocess(Doku_Event &$event, $param) {
		global $ID;

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
				global $ACT;
				global $SUM;

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
				$datapath = dirname(wikiFN($ID)) . '/_' . noNS($ID);
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
