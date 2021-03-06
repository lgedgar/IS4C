<?php

/**
  Process recurring transactions

  Recurring transactions use a transType starting "R." such as "R.Sale".
  First the task locates transactions with a recurring transType that
  occurred exactly one month ago and have a valid token. Because tokens
  are only valid for the merchant account that ran the original transaction,
  the task needs to determine which store a transaction belongs to. The
  original payments can be mapped to store via register number. For subsequent
  payments the task uses register #31 for store #1 and register #32 for store #2.
  A record is always written to PaycardTransactions. If the transaction succeeds
  an equity and a credit record are added to dtransactions. If the transaction
  fails a comment is added so the PaycardTransaction has a corresponding entry
  in dtransactions.

  The token is always cleared from PaycardTransactions regardless of whether the 
  authorization succeeds or fails. On a decline or error the payment plan is
  effectively over.
*/
class EqRecurTask extends FannieTask
{
    private $CREDENTIALS = array(
        1 => array('foo', 'bar'),
        2 => array('foo', 'bar'),
    );

    public function run()
    {
        $this->CREDENTIALS = json_decode(file_get_contents(__DIR__ . '/credentials.json'), true);
        $dbc = FannieDB::get(FannieConfig::config('TRANS_DB'));
        $payments = $this->getTransactions($dbc);
        $EMP_NO = 1001;
        $ptransP = $dbc->prepare("INSERT INTO " . FannieDB::fqn('PaycardTransactions', 'trans') . " (dateID, empNo, registerNo, transNo, transID,
            previousPaycardTransactionID, processor, refNum, live, cardType, transType, amount, PAN, issuer,
            name, manual, requestDatetime, responseDatetime, seconds, commErr, httpCode, validResponse,
            xResultCode, xApprovalNumber, xResponseCode, xResultMessage, xTransactionID, xBalance, xToken,
            xProcessorRef, xAcquirerRef) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($payments as $payment) {
            $store = $this->getStoreID($payment);
            $card_no = $this->getMemberID($dbc, $payment);
            $balance = $this->getBalance($dbc, $card_no);
            if ($card_no === false) {
                $this->cronMsg(sprintf("Cannot find memberID for PT %d,%d", $payment['paycardTransactionID'], $payment['storeRowId']),
                    FannieLogger::ALERT);
                continue;
            } elseif ($balance >= 100) {
                $this->cronMsg(sprintf("Payments complete member %d, PT %d,%d", $card_no, $payment['paycardTransactionID'], $payment['storeRowId']));
                $this->clearToken($dbc, $payment);
                continue;
            }
            $this->cronMsg("Processing payment for {$card_no}.
                Previous payment {$payment['dateID']} {$payment['empNo']}-{$payment['registerNo']}-{$payment['transNo']}");
            $REGISTER_NO = $store == 1 ? 31 : 32;
            $TRANS_NO = DTrans::getTransNo($dbc, $EMP_NO, $REGISTER_NO);
            $amount = $balance > 80 ? 100 - $balance : 20;
            $amount = sprintf('%.2f', $amount);
            $invoice = $this->refnum($EMP_NO, $REGISTER_NO, $TRANS_NO, 2);

            // beginning of PaycardTransactions record
            $pcRow = array(
                date('Ymd'),
                $EMP_NO,
                $REGISTER_NO,
                $TRANS_NO,
                2, // transID
                $payment['paycardTransactionID'],
                $payment['processor'],
                $invoice,
                1,
                'CREDIT',
                'R.Sale',
                $amount,
                $payment['PAN'],
                $payment['issuer'],
                $payment['name'],
                $payment['manual'],
                date('Y-m-d H:i:s'),
            );

            // route to x1.mercurypay.com
            $hostOrIP = '63.111.40.6';
            $terminalID = '';
            if ($payment['processor'] == 'RapidConnect') {
                $hostOrIP = $this->CREDENTIALS['hosts']['RapidConnect' . $store][0];
                $store = "RapidConnect" . $store;
                $terminalID = '<TerminalID>' . $this->CREDENTIALS[$store][1] . '</TerminalID>';
            }

        $reqXML = <<<XML
<?xml version="1.0"?>
<TStream>
    <Transaction>
        <IpAddress>{$hostOrIP}</IpAddress>
        <IpPort>9000</IpPort>
        <MerchantID>{$this->CREDENTIALS[$store][0]}</MerchantID>
        {$terminalID}
        <OperatorID>{$EMP_NO}</OperatorID>
        <TranType>Credit</TranType>
        <TranCode>SaleByRecordNo</TranCode>
        <SecureDevice>{{SecureDevice}}</SecureDevice>
        <ComPort>{{ComPort}}</ComPort>
        <InvoiceNo>{$invoice}</InvoiceNo>
        <RefNo>{$payment['xTransactionID']}</RefNo>
        <Amount>
            <Purchase>{$amount}</Purchase>
        </Amount>
        <Account>
            <AcctNo>SecureDevice</AcctNo>
        </Account>
        <LaneID>{$REGISTER_NO}</LaneID>
        <SequenceNo>{{SequenceNo}}</SequenceNo>
        <RecordNo>{$payment['xToken']}</RecordNo>
        <Frequency>Recurring</Frequency>
    </Transaction>
</TStream>
XML;
            $startTime = microtime(true);
            $approvedAmount = 0;
            echo $reqXML . "\n";

            $curl = curl_init('http://' .   $this->CREDENTIALS['hosts'][$store][0] . ':8999');
            $this->cronMsg("Processing via {$this->CREDENTIALS['hosts'][$store][0]}");
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $reqXML);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
            $respXML = curl_exec($curl);
            echo $respXML . "\n";

            $resp = simplexml_load_string($respXML);
            if (strlen($respXML) > 0 && $resp !== false) {
                $elapsed = microtime(true) - $startTime;
                $pcRow[] = date('Y-m-d H:i:s');
                $pcRow[] = $elapsed;
                $pcRow[] = 0;
                $pcRow[] = 200;
                $pcRow[] = 1; // valid response
                $status = strtolower($resp->CmdResponse->CmdStatus[0]);
                if ($status == 'approved') { // finish record as approved
                    $pcRow[] = 1;
                    $pcRow[] = $resp->TranResponse->AuthCode[0];
                    $pcRow[] = $resp->CmdResponse->DSIXReturnCode[0];
                    $pcRow[] = $resp->CmdResponse->TextResponse[0];
                    $pcRow[] = $resp->TranResponse->RefNo[0];
                    $pcRow[] = 0; // xBalance
                    $pcRow[] = $resp->TranResponse->RecordNo[0];
                    $pcRow[] = $resp->TranResponse->ProcessData[0];
                    $pcRow[] = $resp->TranResponse->AcqRefData[0];
                    $approvedAmount = $resp->TranResponse->Amount->Authorize[0];
                } else { // finish record as declined or errored
                    $pcRow[] = $status == 'declined' ? 2 : 3;
                    $pcRow[] = ''; // xApprovalNumber
                    $pcRow[] = $resp->CmdResponse->DSIXReturnCode[0];
                    $pcRow[] = $status == 'declined' ? 'DECLINED' : $resp->CmdResponse->TextResponse[0];
                    $pcRow[] = ''; // xTransactionID
                    $pcRow[] = 0; // xBalance
                    $pcRow[] = ''; // xToken
                    $pcRow[] = ''; // xProcessorRef
                    $pcRow[] = ''; // xAcquirerRef
                }
            } else {
                $elapsed = microtime(true) - $startTime;
                $pcRow[] = date('Y-m-d H:i:s');
                $pcRow[] = $elapsed;
                $pcRow[] = curl_errno($curl);
                $pcRow[] = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $pcRow[] = 1; // valid response
                $pcRow[] = 3; // xResultCode
                $pcRow[] = ''; // xApprovalNumber
                $pcRow[] = 0; // xResponseCode
                $pcRow[] = curl_error($curl);
                $pcRow[] = ''; // xTransactionID
                $pcRow[] = 0; // xBalance
                $pcRow[] = ''; // xToken
                $pcRow[] = ''; // xProcessorRef
                $pcRow[] = ''; // xAcquirerRef
            }

            /*
            try {
                // process actual transaction
                $soap = new MSoapClient($this->CREDENTIALS[$store][0], $this->CREDENTIALS[$store][1]);
                $xml = $soap->saleByRecordNo($amount, $payment['xToken'], $payment['xTransactionID'], $invoice);

                $elapsed = microtime(true) - $startTime;
                $pcRow[] = date('Y-m-d H:i:s');
                $pcRow[] = $elapsed;
                $pcRow[] = 0;
                $pcRow[] = 200;
                $pcRow[] = 1; // valid response
                $status = strtolower($xml->CmdResponse->CmdStatus);
                if ($status == 'approved') { // finish record as approved
                    $pcRow[] = 1;
                    $pcRow[] = $xml->TranResponse->AuthCode;
                    $pcRow[] = $xml->CmdResponse->DSIXReturnCode;
                    $pcRow[] = $xml->CmdResponse->TextResponse;
                    $pcRow[] = $xml->TranResponse->RefNo;
                    $pcRow[] = 0; // xBalance
                    $pcRow[] = $xml->TranResponse->RecordNo;
                    $pcRow[] = $xml->TranResponse->ProcessData;
                    $pcRow[] = $xml->TranResponse->AcqRefData;
                    $approvedAmount = $xml->TranResponse->Amount->Authorize;
                } else { // finish record as declined or errored
                    $pcRow[] = $status == 'declined' ? 2 : 3;
                    $pcRow[] = ''; // xApprovalNumber
                    $pcRow[] = $xml->CmdResponse->DSIXReturnCode;
                    $pcRow[] = $status == 'declined' ? 'DECLINED' : $xml->CmdResponse->TextResponse;
                    $pcRow[] = ''; // xTransactionID
                    $pcRow[] = 0; // xBalance
                    $pcRow[] = ''; // xToken
                    $pcRow[] = ''; // xProcessorRef
                    $pcRow[] = ''; // xAcquirerRef
                }
            } catch (SoapFault $ex) { // also finish record as an error
                $elapsed = microtime(true) - $startTime;
                $pcRow[] = date('Y-m-d H:i:s');
                $pcRow[] = $elapsed;
                $pcRow[] = 1;
                $pcRow[] = $ex->faultcode;
                $pcRow[] = 1; // valid response
                $pcRow[] = 3; // xResultCode
                $pcRow[] = ''; // xApprovalNumber
                $pcRow[] = 0; // xResponseCode
                $pcRow[] = $ex->faultstring;
                $pcRow[] = ''; // xTransactionID
                $pcRow[] = 0; // xBalance
                $pcRow[] = ''; // xToken
                $pcRow[] = ''; // xProcessorRef
                $pcRow[] = ''; // xAcquirerRef
            }
             */

            $dbc->execute($ptransP, $pcRow);
            $pcID = $dbc->insertID();
            if ($approvedAmount > 0) {
                $this->cronMsg("Payment succeeded for {$card_no}");
                $this->successTransaction($dbc, $EMP_NO, $REGISTER_NO, $TRANS_NO, $approvedAmount, $card_no, $pcID);
            } else {
                $this->cronMsg("Payment failed for {$card_no}", FannieLogger::ALERT);
                $this->failTransaction($dbc, $EMP_NO, $REGISTER_NO, $TRANS_NO, $card_no, $pcID);
            }

            $this->clearToken($dbc, $payment);
        }
    }

    private function failTransaction($dbc, $emp, $reg, $trans, $card_no, $pcID)
    {
        $params = array(
            'description' => 'FAILED RECURRING CHARGE',
            'trans_type' => 'C',
            'trans_subtype' => 'CM',
            'trans_status' => 'D',
            'card_no' => $card_no,
            'register_no' => $reg,
            'emp_no' => $emp,
            'charflag' => 'PT',
            'numflag' => $pcID,
        );
        DTrans::addItem($dbc, $trans, $params);

        $noteP = $dbc->prepare("SELECT note FROM " . FannieDB::fqn('memberNotes', 'op') . " WHERE cardno=?");
        $note = $dbc->getValue($noteP, array($card_no));
        $insP = $dbc->prepare("INSERT INTO " . FannieDB::fqn('memberNotes', 'op') . " (cardno, note, stamp, username) VALUES (?, ?, ?, ?)");
        $args = array(
            $card_no,
            'Recurring payment failed ' . date('Y-m-d') . "\n" . $note,
            date('Y-m-d H:i:s'),
            'auto',
        );
        $dbc->execute($insP, $args);
    }

    private function successTransaction($dbc, $emp, $reg, $trans, $amt, $card_no, $pcID)
    {
        $dtrans = DTrans::defaults();
        $dtrans['emp_no'] = $emp;
        $dtrans['register_no'] = $reg;
        $dtrans['trans_no'] = $trans;
        $dtrans['trans_type'] = 'D';
        $dtrans['department'] = 991;
        $dtrans['description'] = 'Class B Equity';
        $dtrans['upc'] = $amt . 'DP991';
        $dtrans['quantity'] = 1;
        $dtrans['ItemQtty'] = 1;
        $dtrans['trans_id'] = 1;
        $dtrans['total'] = $amt;
        $dtrans['unitPrice'] = $amt;
        $dtrans['regPrice'] = $amt;
        $dtrans['card_no'] = $card_no;
        $prep = DTrans::parameterize($dtrans, 'datetime', $dbc->now());
        $insP = $dbc->prepare("INSERT INTO " . FannieDB::fqn('dtransactions', 'trans') . " ({$prep['columnString']}) VALUES ({$prep['valueString']})");
        $insR = $dbc->execute($insP, $prep['arguments']);

        $dtrans['trans_type'] = 'T';
        $dtrans['trans_subtype'] = 'CC';
        $dtrans['department'] = 0;
        $dtrans['description'] = 'Credit Card';
        $dtrans['upc'] = '';
        $dtrans['quantity'] = 0;
        $dtrans['ItemQtty'] = 0;
        $dtrans['trans_id'] = 2;
        $dtrans['total'] = -1 * $amt;
        $dtrans['unitPrice'] = 0;
        $dtrans['regPrice'] = 0;
        $dtrans['charflag'] = 'PT';
        $dtrans['numflag'] = $pcID;
        $prep = DTrans::parameterize($dtrans, 'datetime', $dbc->now());
        $insR = $dbc->execute($insP, $prep['arguments']);
    }

    private function refnum($emp, $reg, $trans, $id)
    {
        $ref = "";
        $ref .= date("md");
        $ref .= str_pad($emp, 4, "0", STR_PAD_LEFT);
        $ref .= str_pad($reg,    2, "0", STR_PAD_LEFT);
        $ref .= str_pad($trans,   3, "0", STR_PAD_LEFT);
        $ref .= str_pad($id,   3, "0", STR_PAD_LEFT);
        return $ref;
    }

    private function clearToken($dbc, $row)
    {
        return;
        $clearP = $dbc->prepare("UPDATE " . FannieDB::fqn('PaycardTransactions', 'trans') . " SET xToken='USED' WHERE paycardTransactionID=? and storeRowId=?");
        $clearR = $dbc->execute($clearP, array($row['paycardTransactionID'], $row['storeRowId']));

        return $clearR ? true : false;
    }

    private function getStoreID($row)
    {
        if ($row['registerNo'] < 10) {
            return 1;
        } elseif ($row['registerNo'] == 31) {
            return 1;
        } elseif ($row['registerNo'] == 32) {
            return 2;
        } else {
            return 2;
        }
    }

    private function getMemberID($dbc, $row)
    {
        $dlog = DTransactionsModel::selectDlog($row['requestDatetime']);
        $prep = $dbc->prepare("
            SELECT card_no
            FROM {$dlog}
            WHERE tdate BETWEEN ? AND ?
                AND emp_no=?
                AND register_no=?
                AND trans_no=?
        ");
        $date = date('Y-m-d', strtotime($row['requestDatetime']));
        $args = array(
            $date . ' 00:00:00',
            $date . ' 23:59:59',
            $row['empNo'],
            $row['registerNo'],
            $row['transNo'],
        );

        return $dbc->getValue($prep, $args);
    }

    private function getBalance($dbc, $card_no)
    {
        $prep = $dbc->prepare("
            SELECT payments
            FROM " . FannieDB::fqn('equity_live_balance', 'trans') . "
            WHERE memnum=?");
        return $dbc->getValue($prep, array($card_no));
    }

    private function getTransactions($dbc)
    {
        global $argv;
        $dateID = date('Ymd', strtotime('31 days ago'));
        if (isset($argv) && is_array($argv)) {
            foreach($argv as $arg) {
                if (is_numeric($arg) && strlen($arg) == 8) {
                    $dateID = $arg;
                }
            }
        }
        $transP = $dbc->prepare("
            SELECT *
            FROM " . FannieDB::fqn('PaycardTransactions', 'trans') . "
            WHERE dateID=?
                AND empNo <> 9999
                AND registerNo <> 99
                AND transType LIKE 'R.%'
                AND xToken IS NOT NULL
                AND xToken <> ''
                AND xToken <> 'USED'
        ");
        $transR = $dbc->execute($transP, array($dateID));
        $ret = array();
        while ($row = $dbc->fetchRow($transR)) {
            $ret[] = $row;
        }

        return $ret;
    }
}

