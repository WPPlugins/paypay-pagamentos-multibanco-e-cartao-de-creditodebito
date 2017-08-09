<?php
class PaypayMB extends Paypay {

	/**
	 * Constructor responsible for:
	 * - Setting the general variables;
	 * - Initializing those settings;
	 * - Showing a notice when SSL is not enabled;
	 * - Setting the URL to which the requests should be sent;
	 * - Subscribing to the webhook;
	 * - Checking the pending payments.
	 */
	function __construct() {
		parent::__construct();
		$this->id = "paypay_mb";
        $this->method_title = __( "MULTIBANCO by Paypay", 'paypay' );
        $this->description = null;
        $this->title = $this->method_title;
        $this->supports = array();
	}

	/**
	 * Processes the payments by performing the requests to the PayPay
	 * @param  [integer] $order_id [ID of the order to be processed]
	 * @return [array  ]           [Result of the processment and return URL]
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;

		// Loads the data relative to the order
		// This data is loaded automatically by passing the order_id
		$customer_order = new WC_Order($order_id);

        try {
            $payment = $this->generateMB($order_id, $customer_order);
        } catch (Exception $e) {
            return array(
                'result'   => 'failed',
                'redirect' => $this->get_return_url($customer_order)
            );
        }

        $customer_order->update_status('on-hold', __('Awaiting Payment', 'paypay'));

        return array(
            'result'   => 'success',
            'redirect' => $this->getReturnUrlFromCustomerOrder($customer_order)
        );
	}


	/**
	 * Generates the MB payment data
	 * @param  [integer ] $order_id        [ID of the order]
	 * @param  [decimal ] $total           [Total of the order]
	 * @param  [WC_Order] $customer_order  [Order object]
	 * @return [array   ]                  [Payment data]
	 */
	private function generateMB($order_id, $customer_order)
    {
        $paymentWebservice = $this->getSoapClient();

        $date = new DateTime();
        $date->modify("+1 minutes");
        $date->modify("+1 hour");
        $dataAtual  = $date->format("d-m-Y H:i:s");
        $amount     = number_format($customer_order->order_total, 2, '', '');

        $hash = hash('sha256', $this->hash.$dataAtual);
        $requestEntity = new RequestEntity(
            $this->platformCode,
            $hash,
            $dataAtual,
            $this->nif,
            "PT"
        );

        $requestReferenceDetails = new RequestReferenceDetails(
            array(
                'amount' => $amount
            )
        );

        $response = $paymentWebservice->createPaymentReference($requestEntity, $requestReferenceDetails);

        if (!isset($response) ||
            empty($response) ||
            $response->integrationState->state != 1 ||
            $response->state != 1) {
            throw new DomainException('Invalid PayPay MB response');
        }

        $payment = array(
            'reference' => $response->reference,
            'atmEntity' => $response->atmEntity,
            'amount'    => number_format($response->amount/100, 2),
            'idPayment' => $response->idPayment,
            'paid'      => "0"
        );

		$comment_id = $customer_order->add_order_note($this->getPaymentLayout("1", $payment), 0, true);
		$payment['comment_id'] = $comment_id;

        $this->saveMB($order_id, $payment);

        return $payment;
	}

	/**
	 * Saves the data relative to the MB payments
	 * @param  [integer] $order_id [ID of the order]
	 * @param  [array  ] $payment  [Payment data]
	 * @return [boolean]           [Result of the query]
	 */
	private function saveMB($order_id, $payment) {
		global $wpdb;
		$query = "INSERT INTO paypay_reference VALUES(%d, '%s', '%s', '%s', %d, '0', %d)";
		$wpdb->query($wpdb->prepare($query, array($order_id, $payment['reference'], $payment['atmEntity'], $payment['amount'], $payment['idPayment'], $payment['comment_id'])));
		$query = "INSERT INTO paypay_payment_type VALUES(%d, '1')";
		return $wpdb->query($wpdb->prepare($query, array($order_id)));
	}

    public function get_description()
    {
        return null;
    }
}
