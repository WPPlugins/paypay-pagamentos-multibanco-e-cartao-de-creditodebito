<?php

class ResponseGetPayment {

    /** @var ResponseIntegrationState */
    public $integrationState;

    /** @var Int */
    public $state;

    /** @var Int */
    public $idPayment;

    /** @var Int */
    public $amount;

    /** @var Int */
    public $creditCardPayment;

    /** @var Int */
    public $atmPayment;

    /** @var String */
    public $atmEntity;

    /** @var String */
    public $reference;

    /** @var String */
    public $hash;

    /** @var String */
    public $linkPayment;

    /** @var String */
    public $err_code;

    /** @var String */
    public $err_msg;


    public function __construct($params)
    {
        $fields = array(
                        'integrationState',
                        'state',
                        'idPayment',
                        'amount',
                        'creditCardPayment',
                        'atmPayment',
                        'atmEntity',
                        'reference',
                        'hash',
                        'linkPayment',
                        'err_code',
                        'err_msg');
        foreach ($fields as $fkey => $fvalue) {
            $this->$fvalue = empty($params[$fvalue]) ? '': $params[$fvalue];
        }
    }

}

?>
