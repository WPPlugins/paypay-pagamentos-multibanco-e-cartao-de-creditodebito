<?php

class PaymentDetails {
    /** @var String */
    public $code;

    /** @var Int */
    public $state;

    /** @var String */
    public $reference;

    /** @var Int */
    public $paymentState;

    /** @var Int */
    public $paymentStateId;

    /** @var Int */
    public $paymentBlocked;

    /** @var Int */
    public $paymentCancelled;

    /** @var String */
    public $paymentDate;

    /** @var String */
    public $paymentMode;

    /** @var String */
    public $message;


    public function __construct($params)
    {
        $fields = array('code',
                        'state',
                        'reference',
                        'paymentState',
                        'paymentStateId',
                        'paymentBlocked',
                        'paymentCancelled',
                        'paymentDate',
                        'paymentMode',
                        'message');
        foreach ($fields as $fkey => $fvalue) {
            if (isset($params[$fvalue]) && $params[$fvalue] != '')  {
                $this->$fvalue = $params[$fvalue];
            }

        }
    }
}

?>
