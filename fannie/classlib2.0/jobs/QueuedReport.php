<?php

namespace COREPOS\Fannie\API\jobs;
use \FannieConfig;
use \FannieDB;
use \FannieLogger;
use \COREPOS\common\ErrorHandler;
use \COREPOS\common\mvc\ValueContainer;
use \COREPOS\Fannie\API\data\pipes\OutgoingEmail;


/**
 * @class QueuedReport
 * 
 * Run a report in the background if it might take awhile
 * and dispatch the result via email
 *
 * Data format:
 * {
 *     'reportFile': <string>,
 *     'reportClass': <string>,
 *     'email': <string>
 *     'formData': {
 *          'key': 'val',
 *          'key': 'val',
 *          ...
 *     }
 * }
 */
class QueuedReport extends Job
{
    public function run()
    {
        if (!class_exists('\\' . $this->data['reportClass'])) {
            include($this->data['reportFile']);
        }
        $pClass = $this->data['reportClass'];
        $page = new $pClass(array());

        $config = FannieConfig::factory();
        $logger = FannieLogger::factory();
        $op_db = $config->get('OP_DB');
        $dbc = FannieDB::get($op_db);
        ErrorHandler::setLogger($logger);
        ErrorHandler::setErrorHandlers();
        $page->setConfig($config);
        $page->setLogger($logger);
        $page->setConnection($dbc);

        $values = new ValueContainer();
        $values->setMany($this->data['formData']);
        $values['excel'] = 'csv';
        $page->setForm($values);

        ob_start();
        $page->draw_page();
        $csv = ob_get_clean();

        $mail = OutgoingEmail::get();
        $mail->isSMTP();
        $mail->Host = '127.0.0.1';
        $mail->Port = 25;
        $mail->SMTPAuth = false;
        $mail->SMTPAutoTLS = false;
        $mail->From = $config->get('PO_EMAIL');
        $mail->Body = 'The requested report is attached.';
        $mail->addStringAttachment(
            $csv,
            $this->data['className'] . '.csv',
            'base64',
            'text/csv'
        );
        $mail->send();
    }
}
