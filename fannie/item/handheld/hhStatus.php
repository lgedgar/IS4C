<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Community Co-op

    This file is part of Fannie.

    Fannie is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    Fannie is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include('../../config.php');
include($FANNIE_ROOT.'src/mysql_connect.php');

?>
<html><head><title>Item Status</title>
<script type="text/javascript" 
	src="<?php echo $FANNIE_URL; ?>src/jquery-1.2.6.min.js">
</script>
<?php if ($FANNIE_HANDHELD == 'Linea'){ ?>
<script type="text/javascript" 
	src="<?php echo $FANNIE_URL; ?>src/linea/cordova-2.2.0.js">
</script>
<script type="text/javascript" 
	src="<?php echo $FANNIE_URL; ?>src/linea/ScannerLib-Linea-2.0.0.js">
</script>
<script type="text/javascript" >
Device = new ScannerDevice({
	barcodeData: function (data, type){
		var upc = data.substring(0,data.length-1);
		if ($('#upc_in').length > 0){
			$('#upc_in').val(upc);
			$('#submitBtn').click();
		}
	}
});
ScannerDevice.registerListener(Device);
</script>
<?php } else { ?>
<script type="text/javascript">
$(document).ready(function(){
	$('#upc_in').focus();
});
</script>
<?php } ?>
<style>
body {
	margin: 10px;
}
em {
	color: #330066;
	font-style: normal;
}
a {
	color: blue;
}
</style>
</head>

<?php


echo "<body>";
echo '<a href="index.php">Home</a>';
echo '<p />';
echo "<b>Item Status</b>";
echo "<form action=hhStatus.php method=get>";
echo "<table><tr><td>";
echo "<input name=upc type=text id=upc_in> 
</td><td>
<select name=\"ntype\">
<option>UPC</option>
<option>SKU</option>
<option>Brand Prefix</option>
</select><td></tr><tr> 
<td colspan=2 align=right>or description</td></tr>";
echo "<tr><td colspan=2 align=left>";
echo '<input type="hidden" name="submit1" value="s" />';
echo "<input name=submitBtn id=submitBtn type=submit value=Submit 
	style=\"width:150px;height:50px;font-size:110%;\" /> ";
echo "</td></tr></table></form>";

if(isset($_REQUEST['upc']) && !empty($_REQUEST['upc'])){
	if (isset($_REQUEST['submit1'])){
		$query = "SELECT p.upc,p.description,p.normal_price,p.special_price,
			p.department,p.tax,p.foodstamp,p.scale,p.qttyEnforced,p.discount,
			p.discounttype,x.manufacturer,x.distributor,u.likeCode,l.likeCodeDesc,
			p.modified
			FROM products AS p LEFT JOIN prodExtra AS x ON p.upc=x.upc
			LEFT JOIN upcLike AS u ON p.upc=u.upc LEFT JOIN likeCodes AS l
			ON l.likeCode=u.likeCode ";
		$args = array();
		if (!is_numeric($_REQUEST['upc'])){
			$query .= "WHERE p.description like ?";
			$args[] = '%'.$_REQUEST['upc'].'%';
		}
		else {
			switch($_REQUEST['ntype']){
			case 'Brand Prefix':
				$query .= "WHERE p.upc LIKE ?";
				$args[] = '%'.$_REQUEST['upc'].'%';
				break;
			case 'SKU':
				$query .= "INNER JOIN vendorItems AS v ON p.upc=v.upc
					WHERE v.sku=?";
				$args[] = $_REQUEST['upc'];
				break;
			case 'UPC':
			default:
				$query .= "WHERE p.upc=?";
                $args[] = BarcodeLib::padUPC(FormLib::get('upc'));
				break;
			}
		}
		$query .= " ORDER BY p.description";

		$prep = $dbc->prepare_statement($query);
		$result = $dbc->exec_statement($query,$args);
		$num = $dbc->num_rows($result);
		if ($num > 1){
			while($row=$dbc->fetch_row($result)){
				printf("<a href=\"hhStatus.php?submit1=Submit&upc=%s\">%s</a><br />",
					$row['upc'],$row['description']);
			}
		}
		else if ($num == 0){
			echo 'No item found';
		}
		else if (is_numeric($_REQUEST['upc']) && $num == 1){
			$row = $dbc->fetch_row($result);
			echo "<table cellpadding=4><tr><td colspan=2>";
			echo "<span style=\"color:red;\">".$row['upc']."</span>";
			printf(" <input type=text name=desc value=\"%s\" /></td></tr>",
				$row['description']);

			printf("<tr><td colspan=2 style=\"font-weight:bold;\"><em>Price</em>
				\$<input style=\"font-size:100%%;height:40px;\" size=\"4\"
				id=\"pricefield\" type=text name=price value=\"%.2f\" />
				 <span style=\"color:green;\">%s</span>
				</td></tr>",$row['normal_price'],
				(($row['discounttype']>0 && $row['special_price'] != 0)?
				sprintf("On Sale @ \$%.2f",$row['special_price']):""));

			$departments = array();
			$deptQ = $dbc->prepare_statement("SELECT dept_no,dept_name FROM departments ORDER BY dept_no");
			$deptR = $dbc->exec_statement($deptQ);
			while($deptW = $dbc->fetch_row($deptR))
				$departments[$deptW[0]] = $deptW[1];
			echo "<tr><td><em>Dept</em> <select name=dept>";
			foreach($departments as $k=>$v){
				printf("<option %s value=%d>%d %s</option>",
					($row['department']==$k?'selected':''),
					$k,$k,substr($v,0,10));
			}
			echo "</select></td>";

			$taxrates = array();
			$taxrates[0] = "NoTax";
			$taxQ = $dbc->prepare_statement("SELECT id,description FROM taxrates ORDER BY id");
			$taxR = $dbc->exec_statement($taxQ);
			while($taxW = $dbc->fetch_row($taxR))
				$taxrates[$taxW[0]] = $taxW[1];
			echo "<td><em>Tax</em> <select name=tax>";
			foreach($taxrates as $k=>$v){
				printf("<option %s value=%d>%s</option>",
					($row['tax']==$k?'selected':''),
					$k,$v);
			}
			echo "</select></td></tr>";

			echo "<tr><td><em>FS</em> <input type=checkbox name=fs";
			echo ($row['foodstamp']==1?' checked':'');
			echo " /></td>";

			echo "<td><em>Scale</em> <input type=checkbox name=scale";
			echo ($row['scale']==1?' checked':'');
			echo " /></td></tr>";

			echo "<tr><td><em>QtyFrc</em> <input type=checkbox name=qttyForce";
			echo ($row['qttyEnforced']==1?' checked':'');
			echo " /></td>";

			echo "<td><em>NoDisc</em> <input type=checkbox name=nodisc";
			echo ($row['discount']==0?' checked':'');
			echo " /></td></tr>";

			printf("<tr><td colspan=2><em>Likecode</em>:
				%s</td></tr>",
				(empty($row['likeCode'])?'None':
				$row['likeCode'].' '.$row['likeCodeDesc']));

			echo "<tr><td colspan=2><em>Manufacturer</em>: ";
			echo (!empty($row['manufacturer'])?$row['manufacturer']:'n/a');
			echo "</td></tr>";

			echo "<tr><td colspan=2><em>Distributor</em>: ";
			echo (!empty($row['distributor'])?$row['distributor']:'n/a');
			echo "</td></tr>";

			echo "<tr><td colspan=2><em>Last modified</em>: ";
			echo (!empty($row['modified'])?$row['modified']:'Unknown');
			echo "</td></tr>";
		}
		else {
			echo "<i>Error: No item found for: ".$_REQUEST['upc']."</i><br />";
		}
	}
}

?>
</body>
</html>
