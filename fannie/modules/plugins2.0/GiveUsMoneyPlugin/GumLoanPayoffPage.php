<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of IT CORE.

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

include(dirname(__FILE__).'/../../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../../classlib2.0/FannieAPI.php');
}

/**
*/
class GumLoanPayoffPage extends FannieRESTfulPage 
{
    protected $must_authenticate = true;
    protected $auth_classes = array('GiveUsMoney');

    public $page_set = 'Plugin :: Give Us Money';
    public $description = '[Loan Payoff] generates a statement, 1099, and check for paying back a loan.';

    public function preprocess()
    {
        $this->header = 'Loan Payoff';
        $this->title = 'Loan Payoff';
        $this->__routes[] = 'get<id><pdf>';

        return parent::preprocess();
    }

    public function get_id_handler()
    {
        global $FANNIE_PLUGIN_SETTINGS, $FANNIE_OP_DB;
        $dbc = FannieDB::get($FANNIE_PLUGIN_SETTINGS['GiveUsMoneyDB']);
        $this->loan = new GumLoanAccountsModel($dbc);
        $this->loan->accountNumber($this->id);
        if (!$this->loan->load()) {
            echo _('Error: account') . ' ' . $this->id . ' ' . _('does not exist');
            return false;
        }

        $bridge = GumLib::getSetting('posLayer');
        $this->custdata = $bridge::getCustdata($this->loan->card_no());
        $this->meminfo = $bridge::getMeminfo($this->loan->card_no());

        // bridge may change selected database
        $dbc = FannieDB::get($FANNIE_PLUGIN_SETTINGS['GiveUsMoneyDB']);

        $this->taxid = new GumTaxIdentifiersModel($dbc);
        $this->taxid->card_no($this->loan->card_no());

        $this->settings = new GumSettingsModel($dbc);

        $this->check_info = new GumPayoffsModel($dbc);
        $map = new GumLoanPayoffMapModel($dbc);
        $map->gumLoanAccountID($this->loan->gumLoanAccountID());
        $payoff_id = false;
        foreach($map->find('gumPayoffID', true) as $obj) {
            // get highest matching ID
            $payoff_id = $obj->gumPayoffID();
            break;
        }
        // none found, allocate new check
        if ($payoff_id === false) {
            $payoff_id = GumLib::allocateCheck($map);
            if ($payoff_id) {
                $this->check_info->gumPayoffID($payoff_id);
                $loan_info = GumLib::loanSchedule($this->loan); 
                $this->check_info->amount($loan_info['balance']);
                $this->check_info->issueDate(date('Y-m-d'));
                $this->check_info->checkNumber(null);
                $this->check_info->save();
                $this->check_info->load();
            }
        } else {
            $this->check_info->gumPayoffID($payoff_id);
            $this->check_info->load();
        }

        return true;
    }

    public function get_id_pdf_handler()
    {
        if (!class_exists('FPDF')) {
            include(__DIR__ . '/../../../src/fpdf/fpdf.php');
            define('FPDF_FONTPATH','font/');
        }

        $this->get_id_handler(); // load models

        $loan_info = GumLib::loanSchedule($this->loan); 

        $name = $this->custdata->FirstName();
        $names = explode(' ', $name);
        $names = array_filter($names, function($i) { return strlen($i) > 1; });
        $name = implode(' ', $names);
        $name = ucwords(strtolower($name));

        $pdf = new FPDF('P', 'mm', 'Letter');
        $pdf->SetMargins(6.35, 6.35, 6.35); // quarter-inch margins
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $pdf->SetXY(0, 0);
        $pdf->Image('img/new_letterhead_horizontal.png', 22, null, 165); // scale to 8"

        $pdf->SetFont('Arial', '', 8);
        $line_height = 3.5;
        $pdf->SetXY(6.35, 43);
        $pdf->Write($line_height, "Dear $name,");
        $pdf->Ln();
        $pdf->Ln();
        $text = 'A few years ago you invested in the bold idea of doubling the size of Whole Foods Co-op with a new location in West Duluth, offering greater access to healthy, locally-sourced food.  Co-op Owners like yourself invested a huge $1.7 million into this project and today, our Denfeld store continues to grow and sustain the Co-op.';
        $pdf->Write($line_height, $text);
        $pdf->Ln();
        $pdf->Ln();
        $text = 'Thank you for believing in your Co-op and investing locally. You\'ve made a difference in your community!';
        $pdf->Write($line_height, $text);
        $pdf->Ln();
        $pdf->Ln();
        $text = 'Pursuant to the terms of your Promissory Note with WFC, included you will find a check for the principal and, as applicable, compound interest due.   A statement showing the terms of your loan and annual compounding of the interest thereon is provided.  Your 1099 for any interest paid will be mailed out to you by January 31st.';

        $pdf->Write($line_height, $text);
        $pdf->Ln();
        $pdf->Ln();
        $text = 'If you have questions regarding this payment, please contact Finance Administrator Josephine Lepak (jlepak@wholefoods.coop).';
        $pdf->Write($line_height, $text);
        $pdf->Ln();
        $pdf->Ln();
        $text = 'From all of us at the Co-op...THANK YOU!';
        $pdf->Write($line_height, $text);
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Write($line_height, "Sarah Hannigan,\nGeneral Manager");
        $pdf->Ln();
        
        $col_width = 40.64;
        $col1 = 6.35 + $col_width;
        $col2 = $col1 + $col_width;
        $col3 = $col2 + $col_width;
        $col4 = $col3 + $col_width;
        $table_y = 130;
        $pdf->SetFont('Arial', 'BU', 8);
        $pdf->SetXY($col1, $table_y);
        $pdf->Cell($col_width, $line_height, 'Ending Period', 0, 0, 'C');
        $pdf->SetXY($col2, $table_y);
        $pdf->Cell($col_width, $line_height, 'Days', 0, 0, 'C');
        $pdf->SetXY($col3, $table_y);
        $pdf->Cell($col_width, $line_height, 'Interest', 0, 0, 'R');
        $pdf->SetXY($col4, $table_y);
        $pdf->Cell($col_width, $line_height, 'Closing Balance', 0, 0, 'R');
        $pdf->SetFont('Arial', '', 8);
        $i=0;
        for($i=0; $i<count($loan_info['schedule']); $i++) {
            $line_y = $table_y + (($i+1) * $line_height);
            $pdf->SetXY($col1, $line_y);
            $pdf->Cell($col_width, $line_height, $loan_info['schedule'][$i]['end_date'], 0, 0, 'C');
            $pdf->SetXY($col2, $line_y);
            $pdf->Cell($col_width, $line_height, $loan_info['schedule'][$i]['days'], 0, 0, 'C');
            $pdf->SetXY($col3, $line_y);
            $pdf->Cell($col_width, $line_height, number_format($loan_info['schedule'][$i]['interest'], 2), 0, 0, 'R');
            $pdf->SetXY($col4, $line_y);
            $pdf->Cell($col_width, $line_height, number_format($loan_info['schedule'][$i]['balance'], 2), 0, 0, 'R');
        }
        $last_y = $table_y + (($i+1) * $line_height);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY($col1, $last_y);
        $pdf->Cell($col_width, $line_height, 'Total', 0, 0, 'C');
        $pdf->SetXY($col2, $last_y);
        $pdf->Cell($col_width, $line_height, number_format($this->loan->principal(), 2), 0, 0, 'R');
        $pdf->SetXY($col3, $last_y);
        $pdf->Cell($col_width, $line_height, number_format($loan_info['total_interest'], 2), 0, 0, 'R');
        $pdf->SetXY($col4, $last_y);
        $pdf->Cell($col_width, $line_height, number_format($loan_info['balance'], 2), 0, 0, 'R');
        $pdf->SetFont('Arial', '', 8);

        $pdf->SetXY(6.35, $table_y);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($col_width, $line_height, 'Loan Amount', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($col_width, $line_height, number_format($this->loan->principal(), 2), 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($col_width, $line_height, 'Term', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($col_width, $line_height, ($this->loan->termInMonths() / 12) . ' Years', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($col_width, $line_height, 'Loan Date', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($col_width, $line_height, date('m/d/Y', strtotime($this->loan->loanDate())), 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($col_width, $line_height, 'Interest Rate', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($col_width, $line_height, (100 * $this->loan->interestRate()).'%', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($col_width, $line_height, 'Maturity Date', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
        $ld = strtotime($this->loan->loanDate());
        $ed = mktime(0, 0, 0, date('n', $ld)+$this->loan->termInMonths(), date('j', $ld), date('Y', $ld));
        $pdf->Cell($col_width, $line_height, date('m/d/Y', $ed), 0, 1, 'C');

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Line(6.35, $table_y, 6.35 + $col_width, $table_y);
        $pdf->Line(6.35, $table_y, 6.35, $table_y + 10*$line_height);
        $pdf->Line(6.35 + $col_width, $table_y, 6.35 + $col_width, $table_y + 10*$line_height);
        $pdf->Line(6.35, $table_y + 10*$line_height, 6.35 + $col_width, $table_y + 10*$line_height);
        for($i=2; $i<=8; $i+= 2) {
            $pdf->Line(6.35, $table_y + $i*$line_height, 6.35+$col_width, $table_y + $i*$line_height);
        }

        $fields = array(1 => $loan_info['total_interest']);
        $this->settings->key('storeStateID');
        if ($this->settings->load()) {
            $fields[12] = $this->settings->value();
        }
        $this->settings->key('storeState');
        if ($this->settings->load()) {
            $fields[11] = $this->settings->value();
        }
            
        $ssn = 'Unknown';
        if ($this->taxid->load()) {
            $ssn = 'xxx-xx-' . $this->taxid->maskedTaxIdentifier();
            $key = file_get_contents('/path/to/key');
            $privkey = openssl_pkey_get_private($key);
            $try = openssl_private_decrypt($this->taxid->encryptedTaxIdentifier(), $decrypted, $privkey);
            if ($try) $ssn = $decrypted;
        }
        $form =  new GumTaxFormTemplate($this->custdata, $this->meminfo, $ssn, date('Y'), $fields, $this->loan->accountNumber());
        $pdf->addPage();
        $ret = $form->renderAsPDF($pdf, 105);

        $pdf->Output('LoanPayoff.pdf', 'I');

        if (FormLib::get('issued') == '1') {
            $this->check_info->checkIssued(1);
            $this->check_info->issueDate(date('Y-m-d H:i:s'));
            $this->check_info->save();
        }

        return false;
    }

    public function post_id_handler()
    {
        global $FANNIE_PLUGIN_SETTINGS, $FANNIE_OP_DB;
        $dbc = FannieDB::get($FANNIE_PLUGIN_SETTINGS['GiveUsMoneyDB']);
        $model = new GumPayoffsModel($dbc);
        $model->gumPayoffID($this->id);
        if (FormLib::get('checkNumber')) {
            $model->checkNumber(FormLib::get('checkNumber'));
        }
        if (FormLib::get('checkDate')) {
            $model->issueDate(FormLib::get('checkDate'));
        }
        if (FormLib::get('checkAmount')) {
            $model->amount(FormLib::get('checkAmount'));
        }
        if (FormLib::get('checkNote')) {
            $model->reason(FormLib::get('checkNote'));
        }
        $model->save();

        return 'GumLoanPayoffPage.php?id=' . FormLib::get('loanID');
    }

    public function css_content()
    {
        return '
            table#infoTable td {
                text-align: center;
                padding: 0 40px 0 40px;
            }
            table #infoTable {
                margin-left: auto;
                margin-right: auto;
            }
            table#infoTable tr.red td {
                color: red;
            }
        ';
    }

    public function get_id_view()
    {
        global $FANNIE_URL;
        $ret = '';

        $ret .= '<input onclick="location=\'GumLoanPayoffPage.php?id='.$this->id.'&pdf=1\'; return false;"
                    type="button" value="Print" />';
        $ret .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        $ret .= sprintf('<label for="issueCheckbox">Check has been issued</label> 
                        <input type="checkbox" onclick="return issueWarning();"
                        onchange="issueCheck(\'%s\');" id="issueCheckbox" %s />
                        <span id="issueDate">%s</span>',
                        $this->id,
                        ($this->check_info->checkIssued() ? 'checked disabled' : ''),
                        ($this->check_info->checkIssued() ? $this->check_info->issueDate() : '')
        );
        $this->addScript('js/loan_payoff.js?date=201807301');

        if (file_exists('img/new_letterhead_horizontal.png')) {
            $ret .= '<img src="img/new_letterhead_horizontal.png" style="width: 100%;" />';
        }

        $ret .= '<p>Thank you for investing in the Denfeld expansion. Pursuant to the terms of your Promissory Note with WFC, below please find a check for the principal and, as applicable, compound interest due.   A statement showing the terms of your loan and annual compounding of the interest thereon is provided. If you have questions regarding this payment, please contact Financial Manager Josephine Lepak (jlepak@wholefoods.coop).  Thank you very much for your support.</p>';

        $ret .= '<div>';
        $ret .= '<table style="border: solid 1px black; border-collapse: collapse; width: 20%; float:left;">';

        $ret .= '<tr><td style="text-align:center; border: solid 1px black;"><b>Loan Amount</b><br />';
        $ret .= number_format($this->loan->principal(), 2);
        $ret .= '</td></tr>';

        $ret .= '<tr><td style="text-align:center; border: solid 1px black;"><b>Term</b><br />';
        $ret .= ($this->loan->termInMonths() / 12) . ' Years';
        $ret .= '</td></tr>';

        $ret .= '<tr><td style="text-align:center; border: solid 1px black;"><b>Loan Date</b><br />';
        $ret .= date('m/d/Y', strtotime($this->loan->loanDate()));
        $ret .= '</td></tr>';

        $ret .= '<tr><td style="text-align:center; border: solid 1px black;"><b>Interest Rate</b><br />';
        $ret .= ($this->loan->interestRate() * 100) . '%';
        $ret .= '</td></tr>';

        $ld = strtotime($this->loan->loanDate());
        $ed = mktime(0, 0, 0, date('n', $ld)+$this->loan->termInMonths(), date('j', $ld), date('Y', $ld));
        $ret .= '<tr><td style="text-align:center; border: solid 1px black;"><b>Maturity Date</b><br />';
        $ret .= date('m/d/Y', $ed);
        $ret .= '</td></tr>';
        $ret .= '</table>';

        $loan_info = GumLib::loanSchedule($this->loan); 

        $ret .= '<table style="width: 75%; float: left;">';
        $ret .= '<tr>
                <td style="font-weight: bold; text-decoration: underline; text-align:center;">Ending Period</td>
                <td style="font-weight: bold; text-decoration: underline; text-align:center;">Days</td>
                <td style="font-weight: bold; text-decoration: underline; text-align:right;">Interest</td>
                <td style="font-weight: bold; text-decoration: underline; text-align:right;">Closing Balance</td>
                </tr>';
        foreach($loan_info['schedule'] as $year) {
            $ret .= sprintf('<tr>
                            <td style="text-align:center;">%s</td>
                            <td style="text-align:center;">%s</td>
                            <td style="text-align:right;">%s</td>
                            <td style="text-align:right;">%s</td>
                            </tr>',
                            $year['end_date'],
                            $year['days'],
                            number_format($year['interest'], 2),
                            number_format($year['balance'], 2)
            );
        }
        $ret .= sprintf('<tr>
                            <td style="text-align:right;">Total</td>
                            <td style="text-align:right;">%s</td>
                            <td style="text-align:right;">%s</td>
                            <td style="text-align:right;">%s</td>
                            </tr>',
                            number_format($this->loan->principal(), 2),
                            number_format($loan_info['total_interest'], 2),
                            number_format($loan_info['balance'], 2)
        );
        $ret .= '</table>';

        $ret .= '</div>';
        $ret .= '<div style="clear: left;"></div>';

        $ret .= '<hr />';

        $fields = array(1 => $loan_info['balance']);
        $this->settings->key('storeStateID');
        if ($this->settings->load()) {
            $fields[12] = $this->settings->value();
        }
        $this->settings->key('storeState');
        if ($this->settings->load()) {
            $fields[11] = $this->settings->value();
        }
            
        $ssn = 'Unknown';
        if ($this->taxid->load()) {
            $ssn = 'xxx-xx-' . $this->taxid->maskedTaxIdentifier();
        }
        $form =  new GumTaxFormTemplate($this->custdata, $this->meminfo, $ssn, date('Y'), $fields, $this->loan->accountNumber());
        $ret .= $form->renderAsHTML();

        $ret .= '<hr />';

        $checkID = $this->check_info->gumPayoffID();
        $checkNumber = $this->check_info->checkNumber();
        $issueDate = $this->check_info->issueDate();
        $amount = $this->check_info->amount();
        $note = $this->check_info->reason();
        $ret .= <<<HTML
<p>
<form method="post" action="GumLoanPayoffPage.php">
<input type="hidden" name="loanID" value="{$this->id}" />
<input type="hidden" name="id" value="{$checkID}" />
<table class="table table-bordered">
    <tr>
        <th>Check #</th>
        <th>Issue Date</th>
        <th>Amount</th>
        <th>Note</th>
    </tr>
    <tr>
        <td><input type="text" class="form-control" name="checkNumber" value="{$checkNumber}" /></td>
        <td><input type="text" class="form-control" name="checkDate" value="{$issueDate}" /></td>
        <td><input type="text" class="form-control" name="checkAmount" value="{$amount}" /></td>
        <td><input type="text" class="form-control" name="checkNote" value="{$note}" /></td>
    </tr>
</table>
<button type="submit" class="btn btn-default">Update Check Info</button>
</form>
</p>
HTML;

        return $ret;
    }
}

FannieDispatch::conditionalExec();

