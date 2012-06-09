<?php
/**
 * DokuWiki Plugin dokutranslate (Admin Component)
 * Based on built-in ACL plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Martin Doucha <next_ghost@quick.cz>
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Anika Henke <anika@selfthinker.org> (concepts)
 * @author     Frank Schubert <frank@schokilade.de> (old version)
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'admin.php';
require_once 'utils.php';

/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_dokutranslate extends DokuWiki_Admin_Plugin {
    var $acl = null;
    var $ns  = null;

    /**
     * return prompt for admin menu
     */
    function getMenuText($language) {
        return $this->getLang('admin_dokutranslate');
    }

    /**
     * return sort order for position in admin menu
     */
    function getMenuSort() {
        return 1;
    }

    /**
     * handle user request
     *
     * Initializes internal vars and handles modifications
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
	function handle() {
		global $AUTH_ACL;
		global $ID;
		global $auth;

		// fresh 1:1 copy without replacements
		$AUTH_ACL = loadModlist();

		// namespace given?
		if(empty($_REQUEST['ns']) || $_REQUEST['ns'] == '*'){
			$this->ns = '*';
		} else {
			$this->ns = cleanID($_REQUEST['ns']);
		}

		// handle modifications
		if (isset($_REQUEST['cmd']) && checkSecurityToken()) {
			// scope for modifications
			if($this->ns == '*'){
				$scope = '*';
			}else{
				$scope = $this->ns.':*';
			}

			if (isset($_REQUEST['cmd']['save']) && $scope && isset($_REQUEST['modgroup'])) {
				// handle additions or single modifications
				$this->_acl_del($scope);
				$this->_acl_add($scope, trim($_REQUEST['modgroup']));
			} elseif (isset($_REQUEST['cmd']['del']) && $scope) {
				// handle single deletions
				$this->_acl_del($scope);
			} elseif (isset($_REQUEST['cmd']['update'])) {
				// handle update of the whole file
				foreach((array) $_REQUEST['del'] as $where){
					// remove all rules marked for deletion
					unset($_REQUEST['acl'][$where]);
				}

				// prepare lines
				$lines = array();

				// keep header
				foreach($AUTH_ACL as $line){
					if($line{0} == '#'){
						$lines[] = $line;
					}else{
						break;
					}
				}

				foreach((array) $_REQUEST['acl'] as $where => $who){
					$who = $auth->cleanGroup($who);
					$who = auth_nameencode($who,true);
					$lines[] = "$where\t$who\n";
				}

				// save it
				io_saveFile(DOKUTRANSLATE_MODLIST, join('',$lines));
			}
			
			// reload ACL config
			$AUTH_ACL = loadModlist();
		}

		// initialize ACL array
		$this->_init_acl_config();
	}

    /**
     * ACL Output function
     *
     * print a table with all significant permissions for the
     * current id
     *
     * @author  Frank Schubert <frank@schokilade.de>
     * @author  Andreas Gohr <andi@splitbrain.org>
     */
    function html() {
        global $ID;

        echo '<div id="dokutranslate_manager">'.NL;
        echo '<h1>'.$this->getLang('admin_dokutranslate').'</h1>'.NL;
        echo '<div class="level1">'.NL;

        echo '<div id="dokutranslate__tree">'.NL;
        $this->_html_explorer();
        echo '</div>'.NL;

        echo '<div id="dokutranslate__detail">'.NL;
        $this->_html_detail();
        echo '</div>'.NL;
        echo '</div>'.NL;

        echo '<div class="clearer"></div>';
        echo '<h2>'.$this->getLang('current').'</h2>'.NL;
        echo '<div class="level2">'.NL;
        $this->_html_table();
        echo '</div>'.NL;

        echo '</div>'.NL;
    }

    /**
     * returns array with set options for building links
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _get_opts($addopts=null){
        global $ID;
        $opts = array(
                    'do'=>'admin',
                    'page'=>'dokutranslate',
                );
        if($this->ns) $opts['ns'] = $this->ns;

        if(is_null($addopts)) return $opts;
        return array_merge($opts, $addopts);
    }

    /**
     * Display a tree menu to select a page or namespace
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _html_explorer(){
        global $conf;
        global $ID;
        global $lang;

        $dir = $conf['datadir'];
        $ns  = $this->ns;
        if(empty($ns)){
            $ns = dirname(str_replace(':','/',$ID));
            if($ns == '.') $ns ='';
        }elseif($ns == '*'){
            $ns ='';
        }
        $ns  = utf8_encodeFN(str_replace(':','/',$ns));

        $data = $this->_get_tree($ns);

        // wrap a list with the root level around the other namespaces
        array_unshift($data, array( 'level' => 0, 'id' => '*', 'type' => 'd',
                   'open' =>'true', 'label' => '['.$lang['mediaroot'].']'));

        echo html_buildlist($data,'dokutranslate',
                            array($this,'_html_list_acl'),
                            array($this,'_html_li_acl'));

    }

	/**
	 * get a combined list of media and page files
	 *
	 * @param string $folder an already converted filesystem folder of the current namespace
	 * @param string $limit  limit the search to this folder
	 */
	function _get_tree($folder,$limit=''){
		global $conf;

		// read tree structure from pages and media
		$data = array();
		search($data,$conf['datadir'],'search_index',array('ns' => $folder),$limit);

		# Filter out pages, leave only namespaces
		$count = count($data);

		for ($i = 0; $i < $count; $i++) {
			if ($data[$i]['type'] != 'd') {
				unset($data[$i]);
			}
		}

		return $data;
	}

    /**
     * Display the current ACL for selected where/who combination with
     * selectors and modification form
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
	function _html_detail(){
		echo '<form action="'.wl().'" method="post" accept-charset="utf-8"><div class="no">'.NL;

		echo '<div id="dokutranslate__user">';
		$this->_html_modform();
		echo '</div>'.NL;

		echo '<input type="hidden" name="ns" value="'.hsc($this->ns).'" />'.NL;
		echo '<input type="hidden" name="do" value="admin" />'.NL;
		echo '<input type="hidden" name="page" value="dokutranslate" />'.NL;
		echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />'.NL;
		echo '</div></form>'.NL;
	}

	function _html_modform() {
		global $lang;

		$ns = $this->ns . ($this->ns == '*' ? '' : ':*');

		echo $this->getLang('modgroup').' ';
		echo '<input type="text" name="modgroup" class="edit" value="'.hsc(isset($this->acl[$ns]) ? $this->acl[$ns] : '').'" />'.NL;

		if(!isset($this->acl[$ns])){
			echo '<input type="submit" name="cmd[save]" class="button" value="'.$lang['btn_save'].'" />'.NL;
		}else{
			echo '<input type="submit" name="cmd[save]" class="button" value="'.$lang['btn_update'].'" />'.NL;
			echo '<input type="submit" name="cmd[del]" class="button" value="'.$lang['btn_delete'].'" />'.NL;
		}
	}

    /**
     * Item formatter for the tree view
     *
     * User function for html_buildlist()
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _html_list_acl($item){
        global $ID;
        $ret = '';
        // what to display
        if($item['label']){
            $base = $item['label'];
        }else{
            $base = ':'.$item['id'];
            $base = substr($base,strrpos($base,':')+1);
        }

        // highlight?
	if ($item['id'] == $this->ns) {
		$cl = ' cur';
	}

        // namespace or page?
        if($item['open']){
            $img   = DOKU_BASE.'lib/images/minus.gif';
            $alt   = '&minus;';
        }else{
            $img   = DOKU_BASE.'lib/images/plus.gif';
            $alt   = '+';
        }
        $ret .= '<img src="'.$img.'" alt="'.$alt.'" />';
        $ret .= '<a href="'.wl('',$this->_get_opts(array('ns'=>$item['id'],'sectok'=>getSecurityToken()))).'" class="idx_dir'.$cl.'">';
        $ret .= $base;
        $ret .= '</a>';

        return $ret;
    }


    function _html_li_acl($item){
        return '<li class="level' . $item['level'] . ' ' .
               ($item['open'] ? 'open' : 'closed') . '">';
    }


    /**
     * Get current ACL settings as multidim array
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
	function _init_acl_config(){
		global $AUTH_ACL;

		$this->acl = parseModlist($AUTH_ACL);
	}

    /**
     * Display all currently set permissions in a table
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
	function _html_table(){
		global $lang;
		global $ID;

		echo '<form action="'.wl().'" method="post" accept-charset="utf-8"><div class="no">'.NL;

		if($this->ns){
			echo '<input type="hidden" name="ns" value="'.hsc($this->ns).'" />'.NL;
		}

		echo '<input type="hidden" name="do" value="admin" />'.NL;
		echo '<input type="hidden" name="page" value="dokutranslate" />'.NL;
		echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />'.NL;
		echo '<table class="inline">';
		echo '<tr>';
		echo '<th>'.$this->getLang('where').'</th>';
		echo '<th>'.$this->getLang('who').'</th>';
		echo '<th>'.$lang['btn_delete'].'</th>';
		echo '</tr>';

		foreach($this->acl as $where => $who){
			echo '<tr>';
			echo '<td>';
			echo '<span class="dokutranslatens">'.hsc($where).'</span>';
			echo '</td>';

			echo '<td>';
			echo '<span class="dokutranslategroup">'.hsc($who).'</span>';
			echo '</td>';
			
			echo '<td align="center">';
			echo '<input type="hidden" name="acl['.hsc($where).']" value="'.hsc($who).'" />';
			echo '<input type="checkbox" name="del" value="'.hsc($where).'" />';
			echo '</td>';
			echo '</tr>';
		}

		echo '<tr>';
		echo '<th align="right" colspan="3">';
		echo '<input type="submit" value="'.$this->getLang('delsel').'" name="cmd[update]" class="button" />';
		echo '</th>';
		echo '</tr>';
		echo '</table>';
		echo '</div></form>'.NL;
	}

    /**
     * adds new acl-entry to conf/acl.auth.php
     *
     * @author  Frank Schubert <frank@schokilade.de>
     */
	function _acl_add($acl_scope, $acl_user){
		$acl_config = loadModlist();
		$acl_user = auth_nameencode($acl_user,true);

		$new_acl = "$acl_scope\t$acl_user\n";
		$acl_config[] = $new_acl;

		return io_saveFile(DOKUTRANSLATE_MODLIST, join('',$acl_config));
	}

    /**
     * remove acl-entry from conf/acl.auth.php
     *
     * @author  Frank Schubert <frank@schokilade.de>
     */
	function _acl_del($acl_scope){
		$acl_config = loadModlist();
		$acl_pattern = '^'.preg_quote($acl_scope,'/').'\s+.*$';

		// save all non!-matching
		$new_config = preg_grep("/$acl_pattern/", $acl_config, PREG_GREP_INVERT);

		return io_saveFile(DOKUTRANSLATE_MODLIST, join('',$new_config));
	}
}
