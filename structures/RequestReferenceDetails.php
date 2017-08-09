<?php

class RequestReferenceDetails {

    /** @var Int */
    public $amount;

    /** @var String */
    public $reference;

    /** @var Int */
    public $paymentId;

    public function __construct($data)
    {
        $this->amount               = !empty($data['amount']) ? $data['amount']:'';
        $this->reference            = !empty($data['reference']) ? $data['reference']:'';
        $this->paymentId            = !empty($data['paymentId']) ? $data['paymentId']:'';
    }

}
