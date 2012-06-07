/**
 * Dokutranslate Moderator Group Manager AJAX enhancements
 * Based on built-in ACL plugin
 *
 * @author Martin Doucha <next_ghost@quick.cz>
 * @author Andreas Gohr <andi@splitbrain.org>
 */
var dw_dokutranslate = {
    /**
     * Initialize the object and attach the event handlers
     */
    init: function () {
        var $tree;

        //FIXME only one underscore!!
        if (jQuery('#dokutranslate_manager').length === 0) {
            return;
        }

        $tree = jQuery('#dokutranslate__tree');
        $tree.dw_tree({toggle_selector: 'img',
                       load_data: function (show_sublist, $clicky) {
                           // get the enclosed link and the edit form
                           var $frm = jQuery('#dokutranslate__detail form');

                           jQuery.post(
                               DOKU_BASE + 'lib/plugins/dokutranslate/ajax.php',
                               jQuery.extend(dw_dokutranslate.parseatt($clicky.parent().find('a')[0].search),
                                             {ajax: 'tree'
                                              }),
                               show_sublist,
                               'html'
                           );
                       },

                       toggle_display: function ($clicky, opening) {
                           $clicky.attr('src',
                                        DOKU_BASE + 'lib/images/' +
                                        (opening ? 'minus' : 'plus') + '.gif');
                       }});
        $tree.delegate('a', 'click', dw_dokutranslate.treehandler);
    },

    /**
     * Load the current permission info and edit form
     */
    modform: function () {
        var $frm = jQuery('#dokutranslate__detail form');
        jQuery('#dokutranslate__user')
            .html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="..." />')
            .load(
                DOKU_BASE + 'lib/plugins/dokutranslate/ajax.php',
//                jQuery('#dokutranslate__detail form').serialize() + '&ajax=info'
                {ajax: 'modform',
                 ns: $frm.find('input[name=ns]').val(),
                 sectok: $frm.find('input[name=sectok]').val()
                 }
            );
        return false;
    },

    /**
     * parse URL attributes into a associative array
     *
     * @todo put into global script lib?
     */
    parseatt: function (str) {
        if (str[0] === '?') {
            str = str.substr(1);
        }
        var attributes = {};
        var all = str.split('&');
        for (var i = 0; i < all.length; i++) {
            var att = all[i].split('=');
            attributes[att[0]] = decodeURIComponent(att[1]);
        }
        return attributes;
    },

    /**
     * Handles clicks to the tree nodes
     */
    treehandler: function () {
        var $link, $frm;

        $link = jQuery(this);

            // remove highlighting
            jQuery('#dokutranslate__tree a.cur').removeClass('cur');

            // add new highlighting
        $link.addClass('cur');

            // set new page to detail form
        $frm = jQuery('#dokutranslate__detail form');
        $frm.find('input[name=ns]').val(dw_dokutranslate.parseatt($link[0].search).ns);
        dw_dokutranslate.modform();

        return false;
    }
};

jQuery(dw_dokutranslate.init);

var dokutranslate = {
    init: DEPRECATED_WRAP(dw_dokutranslate.init, dw_dokutranslate),
//    userselhandler: DEPRECATED_WRAP(dw_dokutranslate.userselhandler, dw_dokutranslate),
    modform: DEPRECATED_WRAP(dw_dokutranslate.modform, dw_dokutranslate),
    parseatt: DEPRECATED_WRAP(dw_dokutranslate.parseatt, dw_dokutranslate),
    treehandler: DEPRECATED_WRAP(dw_dokutranslate.treehandler, dw_dokutranslate)
};
