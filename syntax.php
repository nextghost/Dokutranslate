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

# Generate edit button for paragraph
function parEditButton($parId) {
	global $ID;
	global $INFO;

	$ret = '';

	$params = array(
		'do' => 'edit',
		'rev' => $INFO['lastmod'],
		'parid' => $parId,
	);

	$ret .= '<div class="secedit editbutton_par' . strval($parId) . '">';
	$ret .= html_btn('secedit', $ID, '', $params, 'post');
	$ret .= '</div>';
	return $ret;
}

function startEditForm(&$renderer, $erase = true) {
	global $DOKUTRANSLATE_EDITFORM;
	global $DOKUTRANSLATE_NEST;
	global $ACT;
	global $TEXT;

	# Insert saved edit form
	$renderer->doc .= '<div class="preview" id="scroll__here">';
	$renderer->doc .= $DOKUTRANSLATE_EDITFORM;

	# Render preview from submitted text (the saved page may look different
	# if dokutranslate markup is present in the text)
	if ($ACT == 'preview') {
		$renderer->doc .= p_locale_xhtml('preview');
		$DOKUTRANSLATE_NEST++;
		$previewIns = p_get_instructions($TEXT);
		$DOKUTRANSLATE_NEST--;
		$renderer->nest($previewIns);
	}

	$renderer->doc .= '</div>';

	if ($erase) {
		# Insert erasure start marker
		$renderer->doc .= '<!-- DOKUTRANSLATE ERASE START -->';
	}
}

function endEditForm(&$renderer) {
	# Insert erasure end marker
	$renderer->doc .= '<!-- DOKUTRANSLATE ERASE STOP -->';
}

function loadTranslationMeta($id) {
	global $REV;

	# Loading meta for current version is simple
	if (empty($REV)) {
		return unserialize(io_readFile(metaFN($id, '.translate'), false));
	}

	# Old revision, do it the hard way...
	$ret = array();
	$meta = unserialize(io_readFile(metaFN($id, '.translateHistory'), false));
	$oldrev = intval($REV);

	for ($i = 0; $i < count($meta[$oldrev]); $i++) {
		$tmp = empty($meta[$oldrev][$i]['changed']) ? $oldrev : $meta[$oldrev][$i]['changed'];
		$ret[$i] = $meta[$tmp][$i];
		$ret[$i]['changed'] = $tmp;
	}

	return $ret;
}

function parReviewClass($meta, $parid) {
	static $classes = array('mistrans', 'reph', 'incaccept', 'accept');

	# No reviews, no class
	if (empty($meta[$parid]['reviews'])) {
		return '';
	}

	# Start with max possible value of $clsid
	$clsid = count($classes) - 1;

	# Find the worst review
	foreach ($meta[$parid]['reviews'] as $line) {
		$tmp = $line['quality'];

		if ($tmp >= count($classes) - 2 && !$line['incomplete']) {
			$tmp++;
		}

		$clsid = $tmp < $clsid ? $tmp : $clsid;
	}

	return empty($classes[$clsid]) ? '' : $classes[$clsid];
}

function needsReview($id, $meta, $parid) {
	return canReview($id, $meta, $parid) && !isset($meta[$parid]['reviews'][$_SERVER['REMOTE_USER']]);
}

class syntax_plugin_dokutranslate extends DokuWiki_Syntax_Plugin {
	private $origIns = NULL;
	private $meta = NULL;
	private $parCounter = 0;

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
		global $DOKUTRANSLATE_NEST;
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
		global $DOKUTRANSLATE_NEST;
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
		global $DOKUTRANSLATE_NEST;
		global $ID;
		global $ACT;
		global $TEXT;
		global $REV;

		# No metadata rendering
		if($mode == 'metadata') {
			return false;
		}

		# Allow exporting the page
		if (substr($ACT, 0, 7) == 'export_') {
			# Ignore plugin-specific markup, just let text through
			if ($data[0] != DOKU_LEXER_UNMATCHED) {
				return true;
			}

			$renderer->cdata($data[1]);
			return true;
		# Not exporting, allow only XHTML
		} else if ($mode != 'xhtml') {
			return false;
		}

		# Load instructions for original text on first call
		if (is_null($this->origIns)) {
			$DOKUTRANSLATE_NEST++;
			$this->origIns = getCleanInstructions(dataPath($ID) . '/orig.txt');
			$this->meta = loadTranslationMeta($ID);
			$this->parCounter = 0;
			$DOKUTRANSLATE_NEST--;
		}

		switch ($data[0]) {
		# Open the table
		case DOKU_LEXER_ENTER:
			$renderer->doc .= '<table width="100%" class="dokutranslate"><tbody><tr>';
			$cls = parReviewClass($this->meta, $this->parCounter);

			# Start the cell with proper review class
			if (empty($cls)) {
				$renderer->doc .= '<td width="50%">';
			} else {
				$renderer->doc .= '<td width="50%" class="' . $cls . '">';
			}

			# Paragraph anchor (yes, empty named anchor is valid)
			$renderer->doc .= "<a name=\"_par$this->parCounter\"></a>\n";

			# Insert edit form if we're editing the first paragraph
			if (in_array($ACT, array('edit', 'preview')) && getParID() == 0) {
				startEditForm($renderer);
			}

			break;

		# Dump original text and close the row
		case DOKU_LEXER_SPECIAL:
			# Generate edit button
			if ($ACT == 'show') {
				if (empty($REV)) {
					$renderer->doc .= parEditButton($this->parCounter);
				}

				$renderer->doc .= $this->_renderReviews($ID, $this->meta, $this->parCounter);
			# Finish erasure if we're editing this paragraph
			} else if (in_array($ACT, array('edit', 'preview')) && getParID() == $this->parCounter) {
				endEditForm($renderer);
			}

			$renderer->doc .= "</td>\n";
			
			if (needsReview($ID, $this->meta, $this->parCounter)) {
				$renderer->doc .= '<td class="reviewme">';
			} else {
				$renderer->doc .= '<td>';
			}

			# If this condition fails, somebody's been messing
			# with the data
			if (current($this->origIns) !== FALSE) {
				$renderer->nest(current($this->origIns));
				next($this->origIns);
			}

			$renderer->doc .= "</td></tr>\n<tr>";
			$this->parCounter++;
			$cls = parReviewClass($this->meta, $this->parCounter);

			# Start the cell with proper review class
			if (empty($cls)) {
				$renderer->doc .= '<td width="50%">';
			} else {
				$renderer->doc .= '<td width="50%" class="' . $cls . '">';
			}

			# Paragraph anchor (yes, empty named anchor is valid)
			$renderer->doc .= "<a name=\"_par$this->parCounter\"></a>\n";

			# Insert edit form if we're editing this paragraph
			if (in_array($ACT, array('edit', 'preview')) && getParID() == $this->parCounter) {
				startEditForm($renderer);
			}

			break;

		# Dump the rest of the original text and close the table
		case DOKU_LEXER_EXIT:
			# Generate edit button
			if ($ACT == 'show') {
				if (empty($REV)) {
					$renderer->doc .= parEditButton($this->parCounter);
				}

				$renderer->doc .= $this->_renderReviews($ID, $this->meta, $this->parCounter);
			# Finish erasure if we're editing the last paragraph
			} else if (in_array($ACT, array('edit', 'preview'))) {
				$parid = getParID();

				if ($parid == $this->parCounter) {
					endEditForm($renderer);
				# Invalid paragraph ID, show form here
				} else if ($parid > $this->parCounter) {
					startEditForm($renderer, true);
				}
			}

			$renderer->doc .= "</td>\n";
			
			if (needsReview($ID, $this->meta, $this->parCounter)) {
				$renderer->doc .= '<td class="reviewme">';
			} else {
				$renderer->doc .= '<td>';
			}

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
			$renderer->cdata($data[1]);
			break;
		}

		return true;
	}

	function _renderReviews($id, $meta, $parid) {
		# Check for permission to write reviews
		$mod = canReview($id, $meta, $parid);

		# No reviews and no moderator privileges => no review block
		if (!$mod && empty($meta[$parid]['reviews'])) {
			return '';
		}

		$ret = "<div class=\"dokutranslate_review\">\n";
		$ret .= '<h5>' . $this->getLang('review_header') . "</h5>\n";
		$ret .= "<table>\n";

		$listbox = array(
			array('0', $this->getLang('trans_wrong')),
			array('1', $this->getLang('trans_rephrase')),
			array('2', $this->getLang('trans_accepted'))
		);

		# Prepare review form for current user
		if ($mod) {
			if (isset($meta[$parid]['reviews'][$_SERVER['REMOTE_USER']])) {
				$myReview = $meta[$parid]['reviews'][$_SERVER['REMOTE_USER']];
			} else {
				$myReview = array('message' => '', 'quality' => 0, 'incomplete' => false);
			}

			$form = new Doku_Form(array());
			$form->addHidden('parid', strval($parid));
			$form->addHidden('do', 'dokutranslate_review');
			$form->addElement(form_makeTextField('review', $myReview['message'], $this->getLang('trans_message'), '', 'nowrap', array('size' => '50')));
			$form->addElement(form_makeMenuField('quality', $listbox, strval($myReview['quality']), $this->getLang('trans_quality'), '', 'nowrap'));
			$args = array();

			if ($myReview['incomplete']) {
				$args['checked'] = 'checked';
			}

			$form->addElement(form_makeCheckboxField('incomplete', '1', $this->getLang('trans_incomplete'), '', 'nowrap', $args));
			$form->addElement(form_makeButton('submit', '', $this->getLang('add_review')));
		}

		# Display all reviews for this paragraph
		while (list($key, $value) = each($meta[$parid]['reviews'])) {
			$ret .= '<tr><td>' . hsc($key) . '</td><td>';

			# Moderators can modify their own review
			if ($mod && $key == $_SERVER['REMOTE_USER']) {
				$ret .= $form->getForm();
			} else {
				$ret .= '(' . $listbox[$value['quality']][1];

				if ($value['incomplete']) {
					$ret .= ', ' . $this->getLang('rend_incomplete');
				}

				$ret .= ') ';
				$ret .= hsc($value['message']);
			}

			$ret .= "</td></tr>\n";
		}

		# Current user is a moderator who didn't write a review yet,
		# display the review form at the end
		if ($mod && !isset($meta[$parid]['reviews'][$_SERVER['REMOTE_USER']])) {
			if (empty($meta[$parid]['reviews'])) {
				$ret .= '<tr><td>';
			} else {
				$ret .= '<tr><td colspan="2">';
			}

			$ret .= $form->getForm();
			$ret .= "</td></tr>\n";
		}

		$ret .= "</table></div>\n";
		return $ret;
	}
}

// vim:ts=4:sw=4:et:
