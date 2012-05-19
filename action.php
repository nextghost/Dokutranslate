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

class action_plugin_dokutranslate extends DokuWiki_Action_Plugin {

	public function register(Doku_Event_Handler &$controller) {
		$this->setupLocale();
		$controller->register_hook('HTML_EDITFORM_OUTPUT', 'BEFORE', $this, 'handle_html_editform_output');
	}

	public function handle_html_editform_output(Doku_Event &$event, $param) {
		#FIXME: Check for permission to begin translation

		# No submit button => preview, don't modify the form
		if(!$event->data->findElementByAttribute('type', 'submit')) {
			return;
		}

		# Place the checkbox after minor edit checkbox or summary
		# text box if minor edit checkbox is not present
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

// vim:ts=4:sw=4:et:
