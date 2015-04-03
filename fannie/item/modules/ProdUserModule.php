<?php
/*******************************************************************************

    Copyright 2014 Whole Foods Co-op, Duluth, MN

    This file is part of CORE-POS.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

if (!class_exists('FannieAPI')) {
    include_once(dirname(__FILE__).'/../../classlib2.0/FannieAPI.php');
}

class ProdUserModule extends ItemModule 
{

    public function showEditForm($upc, $display_mode=1, $expand_mode=1)
    {
        $FANNIE_URL = FannieConfig::config('URL');
        $upc = BarcodeLib::padUPC($upc);

        $ret = '<div id="ProdUserFieldset" class="panel panel-default">';
        $ret .=  "<div class=\"panel-heading\">
                <a href=\"\" onclick=\"\$('#ProdUserFieldsetContent').toggle();return false;\">
                Sign/Web Info</a>
                </div>";
        $css = ($expand_mode == 1) ? '' : ' collapse';
        $ret .= '<div id="ProdUserFieldsetContent" class="panel-body' . $css . '">';

        $dbc = $this->db();
        $model = new ProductUserModel($dbc);
        $model->upc($upc);
        $model->load();

        $prod = new ProductsModel($dbc);
        $prod->upc($upc);
        $prod->load();

        $ret .= '<div class="col-sm-6">';
        $ret .= '<div class="form-group form-inline">'
                . '<label>Brand</label> '
                . '<input type="text" class="form-control" id="lf_brand" name="lf_brand" value="' . $model->brand() . '" />'
                . ' <a href="" onclick="createSign(); return false;">Make Sign</a>'
                . '</div>';
        $ret .= '<div class="form-group form-inline">'
                . '<label>Desc.</label> '
                . '<input type="text" class="form-control" id="lf_desc" name="lf_desc" value="' . $model->description() . '" />'
                . '</div>';

        if ($dbc->tableExists('productExpires')) {
            $e = new ProductExpiresModel($dbc);
            $e->upc($upc);
            $e->load();
            $ret .= '<div class="form-group form-inline">'
                    . '<label>Expires</label> '
                    . '<input type="text" class="form-control date-field" id="lf_expires" name="lf_expires" 
                        value="' . ($e->expires() == '' ? '' : date('Y-m-d', strtotime($e->expires()))) . '" />'
                    . '</div>';
        }


        $otherOriginBlock = '<div class=form-inline><select name=otherOrigin[] class=form-control><option value=0>n/a</option>';

        $ret .= '<div class="form-group form-inline">'
                . '<label><a href="' . $FANNIE_URL . 'item/origins/OriginEditor.php">Origin</a></label>'
                . ' <select name="origin" class="form-control">'
                . '<option value="0">n/a</option>';
        $origins = new OriginsModel($dbc);
        $origins->local(0);
        foreach ($origins->find('name') as $o) {
            $ret .= sprintf('<option %s value="%d">%s</option>',
                        $prod->current_origin_id() == $o->originID() ? 'selected' : '',
                        $o->originID(), $o->name());
            $otherOriginBlock .= sprintf('<option value=%d>%s</option>',
                                            $o->originID(), $o->name());
        }
        $ret .= '</select>';
        $otherOriginBlock .= '</div>';
        $ret .= '&nbsp;&nbsp;&nbsp;&nbsp;<a href="" 
                onclick="$(\'#originsBeforeMe\').before(\'' . $otherOriginBlock . '\'); return false;">Add more</a>';
        $ret .= '</div>';

        $mapP = 'SELECT originID FROM ProductOriginsMap WHERE upc=? AND originID <> ?';
        $mapR = $dbc->execute($mapP, array($upc, $prod->current_origin_id()));
        while ($mapW = $dbc->fetch_row($mapR)) {
            $ret .= '<div class="form-group form-inline">
                <select name="otherOrigin[]" class="form-control"><option value="0">n/a</option>';
            foreach ($origins->find('name') as $o) {
                $ret .= sprintf('<option %s value="%d">%s</option>',
                            $mapW['originID'] == $o->originID() ? 'selected' : '',
                            $o->originID(), $o->name());
            }
            $ret .= '</select></div>';
        }
        $ret .= '<div id="originsBeforeMe"></div>';
        $ret .= '</div>';

        $ret .= '<div class="col-sm-6">';
        $ret .= '<div class="form-group"><label>Ad Text</label></div>';
        $ret .= '<div class="form-group">
                <textarea name="lf_text" class="form-control"
                    rows="8" cols="45">' 
                    . str_replace('<br />', "\n", $model->long_text()) 
                    . '</textarea></div>';

        $ret .= '</div>';
        $ret .= '</div>';
        $ret .= '</div>';

        return $ret;
    }

    function SaveFormData($upc)
    {
        $upc = BarcodeLib::padUPC($upc);
        $brand = FormLib::get('lf_brand');
        $desc = FormLib::get('lf_desc');
        $origin = FormLib::get('origin', 0);
        $text = FormLib::get('lf_text');
        $text = str_replace("\r", '', $text);
        $text = str_replace("\n", '<br />', $text);
        // strip non-ASCII (word copy/paste artifacts)
        $text = preg_replace("/[^\x01-\x7F]/","", $text); 

        $dbc = $this->db();

        $model = new ProductUserModel($dbc);
        $model->upc($upc);
        $model->brand($brand);
        $model->description($desc);
        $model->long_text($text);

        if ($dbc->tableExists('productExpires')) {
            $e = new ProductExpiresModel($dbc);
            $e->upc($upc);
            $e->expires(FormLib::getDate('lf_expires', date('Y-m-d')));
            $e->save();
        }

        $multiOrigin = FormLib::get('otherOrigin', array());
        $originMap = array();
        if ($origin != 0) {
            $originMap[] = $origin;
        }
        foreach ($multiOrigin as $originID) {
            if ($originID != 0) {
                $originMap[] = $originID;
            }
        }
        
        $mapP = $dbc->prepare('DELETE FROM ProductOriginsMap WHERE upc=?');
        $addP = $dbc->prepare('INSERT INTO ProductOriginsMap
                                (originID, upc, active)
                                VALUES (?, ?, 1)');

        $lcP = $dbc->prepare('SELECT u.upc
                            FROM upcLike AS u
                                INNER JOIN products AS p ON u.upc=p.upc
                            WHERE u.likeCode IN (
                                SELECT l.likeCode
                                FROM upcLike AS l
                                WHERE l.upc = ?
                            )');
        $lcR = $dbc->execute($lcP, array($upc));
        $items = array($upc);
        while ($w = $dbc->fetch_row($lcR)) {
            if ($w['upc'] == $upc) {
                continue;
            }
            $items[] = $w['upc'];
        }

        $prod = new ProductsModel($dbc);
        foreach ($items as $item) {
            $prod->upc($item);
            $prod->current_origin_id($origin);
            $prod->save();

            $dbc->execute($mapP, array($item));
            foreach ($originMap as $originID) {
                $dbc->execute($addP, array($originID, $item));
            }
        }
        
        return $model->save();
    }

    public function getFormJavascript($upc)
    {
        $FANNIE_URL = FannieConfig::config('URL');
        ob_start();
        ?>
        function createSign()
        {
           var form = $('<form />',
                            { action: '<?php echo $FANNIE_URL; ?>admin/labels/SignFromSearch.php',
                              method: 'post',
                              id: 'newSignForm' }
            );
           form.append($('<input />',
                        { type: 'hidden', name: 'u[]', value: '<?php echo $upc; ?>' }));

           $('body').append(form);
           $('#newSignForm').submit();
        }
        <?php
        return ob_get_clean();

    }

    public function summaryRows($upc)
    {
        $FANNIE_URL = FannieConfig::config('URL');
        $form = sprintf('<form id="newSignForm" method="post" action="%sadmin/labels/SignFromSearch.php">
                        <input type="hidden" name="u[]" value="%s" />
                        </form>', $FANNIE_URL, $upc);
        $ret = '<td>' . $form . '<a href="" onclick="$(\'#newSignForm\').submit();return false;">Create Sign</a></td>';

        return array($ret);
    }
}

