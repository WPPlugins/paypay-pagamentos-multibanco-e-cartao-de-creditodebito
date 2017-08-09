<?php
class Paypay extends WC_Payment_Gateway {
    const TESTING    = 1;
    const PRODUCTION = 2;
	/**
	 * Constructor responsible for:
	 * - Setting the general variables;
	 * - Initializing those settings;
	 * - Setting the URL to which the requests should be sent;
	 * - Subscribing to the webhook;
	 * - Checking the pending payments.
	 */
	function __construct() {
		$this->id = "paypay";
		$this->method_title = __( "PayPay", 'paypay' );
		$this->method_description = __( "PayPay Plug-in for WooCommerce", 'paypay' );
		$this->title = __( "PayPay", 'paypay' );
		$this->icon = null;
        $this->has_fields = false;
		$this->paypay_method_code = '';
		$this->init_form_fields();
		$this->init_settings();

		if (is_admin()) {
			add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
		}
	}

    /**
     * Init settings for gateways.
     */
    public function init_settings() {
        parent::init_settings();
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }
    }

    protected function getSoapClient()
    {
        require_once __DIR__.'/structures/RequestEntity.php';
        require_once __DIR__.'/structures/ResponseIntegrationState.php';
        require_once __DIR__.'/structures/RequestEntityPayments.php';
        require_once __DIR__.'/structures/RequestPaymentDetails.php';
        require_once __DIR__.'/structures/RequestReferenceDetails.php';
        require_once __DIR__.'/structures/ResponseEntityPayments.php';
        require_once __DIR__.'/structures/PaymentDetails.php';
        require_once __DIR__.'/structures/RequestWebhook.php';
        require_once __DIR__.'/structures/ResponseWebhook.php';
        require_once __DIR__.'/structures/RequestCreditCardPayment.php';
        require_once __DIR__.'/structures/RequestPaymentOrder.php';

        $classmap = array(
            'RequestEntity'            => 'RequestEntity',
            'RequestReferenceDetails'  => 'RequestReferenceDetails',
            'RequestCreditCardPayment' => 'RequestCreditCardPayment',
            'RequestPaymentOrder'      => 'RequestPaymentOrder',
            'ResponseIntegrationState' => 'ResponseIntegrationState',
            'RequestWebhook'           => 'RequestWebhook',
            'ResponseWebhook'          => 'ResponseWebhook',
            'RequestEntityPayments'    => 'RequestEntityPayments',
            'ResponseEntityPayments'   => 'ResponseEntityPayments',
            'PaymentDetails'           => 'PaymentDetails'
        );

        $this->server_url   = "https://paypay.acin.pt/paypaybeta/index.php/paypayservices/paypayservices_c/server";
        $this->wsdl_url     = "https://paypay.acin.pt/paypaybeta/index.php/paypayservices/paypayservices_c/wsdl";

        if (defined('PAYPAY_SERVER_URL')) {
            $this->server_url   = PAYPAY_SERVER_URL;
        }

        if (defined('PAYPAY_WSDL_URL')) {
            $this->wsdl_url   = PAYPAY_WSDL_URL;
        }

        $woocommerce_paypay_settings = get_option('woocommerce_paypay_settings');
        if ($woocommerce_paypay_settings && $woocommerce_paypay_settings['environment'] == self::PRODUCTION) {
            $this->server_url   = "https://paypay.pt/paypay/index.php/paypayservices/paypayservices_c/server";
            $this->wsdl_url     = "https://paypay.pt/paypay/index.php/paypayservices/paypayservices_c/wsdl";
        }

        $options = array (
            'classmap'  => $classmap,
            'location'  => $this->server_url,
            'cache_wsdl' => WSDL_CACHE_NONE
        );

        return new SoapClient($this->wsdl_url, $options);
    }

    public function addPaymentGatewaysTo(&$checkoutGateways)
    {
        $response = $this->validatePaymentReference();
        foreach ($response->paymentOptions as $po) {
            $paypayGateway = $this->getClassForMethod($po->code);
            $paypayGateway->id                 = 'paypay_'.strtolower($po->code);
            $paypayGateway->title              = $po->name;
            $paypayGateway->method_title       = $po->name;
            $paypayGateway->method_description = $po->name;
            $paypayGateway->paypay_method_code = $po->code;
            $paypayGateway->icon               = $po->iconUrl;
            $paypayGateway->description        = $po->description;

            $checkoutGateways[$paypayGateway->id] = $paypayGateway;
        }

        return $checkoutGateways;
    }

    private function getClassForMethod($code)
    {
        $methodClassFile = dirname(__FILE__).'/class-wc-paypay-'.strtolower($code).'.php';
        if (file_exists($methodClassFile)) {
            include_once $methodClassFile;
            $className = 'PayPay'.strtoupper($code);

            return new $className();
        }
        return new PayPay();
    }
    /**
     * Calls
     * @return [mixed  ]               [Response if integration is successful, -1 otherwise]
     */
    private function validatePaymentReference() {
        global $woocommerce;

        $paymentWebservice = $this->getSoapClient();

        $date       = new DateTime();
        $date->modify("+1 minutes");
        $date->modify("+1 hour");
        $dataAtual  = $date->format("d-m-Y H:i:s");
        $amount     = str_replace(",", "", number_format($woocommerce->cart->total, 2)) * 100;
        $language   = substr(get_bloginfo('language'), -2);
        $hash = hash('sha256', $this->hash.$dataAtual);
        $requestEntity = new RequestEntity(
            $this->platformCode,
            $hash,
            $dataAtual,
            $this->nif,
            $language
        );

        $requestReferenceDetails = new RequestReferenceDetails(
            array(
                'amount' => $amount
            )
        );

        $response = $paymentWebservice->validatePaymentReference($requestEntity, $requestReferenceDetails);

        if ($response->integrationState->state == "1") {
            return $response;
        }

        throw new UnexpectedValueException("Error Validating Payment Options", 1);
    }

	/**
	 * Processes the payment communicated through the webhook
	 */
	public function webhookCallback() {
		global $wpdb;
		global $woocommerce;

		$confirmation_hash = hash('sha256', $this->hash.$_POST['hookAction'].$_POST['hookDate']);

		if ($_POST['hookHash'] != $confirmation_hash) {
		    header('HTTP/1.1 403 Forbidden');
		    return;
		}

		foreach ($_POST['payments'] as $payment) {
		    if ($payment['paymentMethodCode'] == "MB") {
                $query = "SELECT id_order, paid, comment_id FROM paypay_reference WHERE id_transaction = %d";
                $res   = $wpdb->get_row($wpdb->prepare($query, array($payment['paymentId'])));

		        if (isset($res) && $res->paid == "0") {
		            $query = "UPDATE paypay_reference SET paid = 1 WHERE id_order = %d";
		            $wpdb->query($wpdb->prepare($query, array($res->id_order)));
		        }
		    } else {
                $query = "SELECT id_order, paid, comment_id FROM paypay_payment WHERE id_transaction = %d";
                $res   = $wpdb->get_row($wpdb->prepare($query, array($payment['paymentId'])));

		        if (isset($res) && $res->paid == "0") {
		            $query = "UPDATE paypay_payment SET paid = 1 WHERE id_order = %d";
		            $wpdb->query($wpdb->prepare($query, array($res->id_order)));
		        }
		    }

		    if (isset($res) && $res->paid == "0") {
                $customer_order = new WC_Order($res->id_order);
		        $customer_order->payment_complete($payment['paymentId']);
		    }
		}
	}

	/**
	 * Cancels the payment relative to the order
	 */
	public function failureCallback($order_id)
	{
		global $wpdb;

        $customer_order = new WC_Order($order_id);

		$query = "UPDATE paypay_payment SET paid = 2 WHERE id_order = %d";
		$affectedRows = $wpdb->query($wpdb->prepare($query, array($order_id)));

        if (!$customer_order->is_paid()) {
            $customer_order->update_status('cancelled', __('Processing', 'paypay'));
        }

		// Redirects the user to the order he cancelled
		wp_redirect($this->getReturnUrlFromCustomerOrder($customer_order));
	}

	/**
	 * Processes the admin options inserted by the store admin and checks the integration status
	 * @return [mixed] [Processed options if integration is successful, false otherwise]
	 */
	public function process_admin_options()
	{
        parent::process_admin_options();
        $this->init_settings();

        try {
            $response = $this->subscribeToWebhook();
        } catch (Exception $e) {
            WC_Admin_Settings::add_error($e->getMessage());
            return;
        }

		if ($response !== true) {
            WC_Admin_Settings::add_error($response->integrationState->message);
            return;
		}

		$response = $this->checkPayments();
		if ($response === 0) {
            WC_Admin_Settings::add_message(__("No payment was processed.", 'paypay'));
		} else {
            WC_Admin_Settings::add_message(sprintf(__("Some payments were processed.", 'paypay'), $response));
		}

		return true;
	}

	/**
	 * Subscribes to the webhook
	 * @param  [integer] $nif          [NIF with which the store is registered in PayPay]
	 * @param  [string ] $hash         [Hash provided by the PayPay support team]
	 * @param  [string ] $platformCode [Platform code provided by the PayPay support team]
	 * @return [mixed  ]               [Response if integration is successful, -1 otherwise]
	 */
	public function subscribeToWebhook()
    {
        $paymentWebservice = $this->getSoapClient();

        $date = new DateTime();
        $date->modify("+1 minutes");
        $date->modify("+1 hour");
        $dataAtual = $date->format("d-m-Y H:i:s");

        $hash = hash('sha256', $this->hash.$dataAtual);
        $requestEntity = new RequestEntity(
            $this->platformCode,
            $hash,
            $dataAtual,
            $this->nif,
            "PT"
        );

		// The url is the home page because it is on the homepage that the payment is checked
        $url = get_site_url()."/?wc-api=paypay_webhook";

        $requestWebhook = new RequestWebhook(
            array(
                'action' => 'payment_confirmed',
                'url'    => $url
            )
        );

        $response = $paymentWebservice->subscribeToWebhook($requestEntity, $requestWebhook);

        if ($response->integrationState->state == 1) {
			global $wpdb;
			$query = "INSERT INTO paypay_config VALUES(DEFAULT, 1, 'payment_confirmed', %s, %s)";
			$wpdb->query($wpdb->prepare($query, array($url, $this->nif)));
			return true;
        }

        return $response;
	}



	/**
	 * Checks the integration with the PayPay
	 * @param  [integer] $nif          [NIF with which the store is registered in PayPay]
	 * @param  [string ] $hash         [Hash provided by the PayPay support team]
	 * @param  [string ] $platformCode [Platform code provided by the PayPay support team]
	 * @return [boolean]               [Result of the integration]
	 */
	protected function checkIntegration($nif, $hash, $platformCode)
	{
      	$paymentWebservice = $this->getSoapClient();

      	$date = new DateTime();
      	$date->modify("+1 minutes");
      	$date->modify("+1 hour");
      	$dataAtual = $date->format("d-m-Y H:i:s");

      	$hash = hash('sha256', $hash.$dataAtual);
      	$requestEntity = new RequestEntity(
          	$platformCode,
          	$hash,
          	$dataAtual,
          	$nif,
          	"PT"
      	);

      	try {
          	$response = $paymentWebservice->checkIntegrationState($requestEntity);
      	} catch (Exception $e) {
          	return $e->getCode();
      	}

      	if ($response->state == "1") {
          	return true;
      	} else {
          	return false;
      	}
	}

	/**
	 * Checks the pending payments to check if they were already paid
	 * @return [boolean] [Result of the payment check]
	 */
	protected function checkPayments()
	{
		require_once __DIR__.'/structures/RequestEntity.php';
        require_once __DIR__.'/structures/RequestEntityPayments.php';
        require_once __DIR__.'/structures/RequestPaymentDetails.php';
        require_once __DIR__.'/structures/RequestReferenceDetails.php';
        require_once __DIR__.'/structures/ResponseEntityPayments.php';
        require_once __DIR__.'/structures/ResponseIntegrationState.php';
        require_once __DIR__.'/structures/PaymentDetails.php';
        $result_mb = $this->checkMBPayments();
        $result_cc = $this->checkEntityCCPayments();

		return ($result_mb + $result_cc);
	}

	/**
	 * Checks the MB payments
	 * @return [boolean] [Result of the payment check]
	 */
	protected function checkMBPayments()
	{
		global $wpdb;
		global $woocommerce;

        $paymentWebservice = $this->getSoapClient();

        $date = new DateTime();
        $date->modify("+1 minutes");
        $date->modify("+1 hour");
        $dataAtual = $date->format("d-m-Y H:i:s");

        $hash = hash('sha256', $this->hash.$dataAtual);
        $requestEntity = new RequestEntity(
            $this->platformCode,
            $hash,
            $dataAtual,
            $this->nif,
            "PT"
        );

        $query = "SELECT refMB, id_order, comment_id FROM paypay_reference WHERE paid = '0'";
        $pending_mb_payments = $wpdb->get_results($query);

		$requestEntityPayments = new RequestEntityPayments();
        foreach ($pending_mb_payments as $pp) {
            $requestEntityPayments->payments[] = new RequestReferenceDetails(array('reference' => $pp->refMB));
        }

        try {
            $response = $paymentWebservice->checkEntityPayments($requestEntity, $requestEntityPayments);
        } catch (Exception $e) {
            return $e->getCode();
        }

        if ($response->state->state != 1 || empty($response->payments)) {
            return false;
        }

        if (empty($response->payments)) {
            return true;
        }

		$processed = 0;
		foreach ($response->payments as $index => $payment) {
            if ($payment->paymentState !== 1) {
                continue;
            }

            $pmbp  = $pending_mb_payments[$index];
            $query = "UPDATE paypay_reference SET paid = 1 WHERE id_order = %d";
            $wpdb->query($wpdb->prepare($query, array($pmbp->id_order)));

			// Loads the data relative to the order
			// This data is loaded automatically by passing the order_id
			$customer_order = new WC_Order($pmbp->id_order);
            $customer_order->payment_complete($payment->paymentId);

            $processed++;
        }

        return $processed;
	}


    /**
     * Checks the CC payments
     * @return [boolean] [Result of the payment check]
     */
    protected function checkEntityCCPayments()
    {
        global $wpdb;
        global $woocommerce;

        $paymentWebservice = $this->getSoapClient();

        $date = new DateTime();
        $date->modify("+1 minutes");
        $date->modify("+1 hour");
        $dataAtual = $date->format("d-m-Y H:i:s");

        $hash = hash('sha256', $this->hash.$dataAtual);
        $requestEntity = new RequestEntity(
            $this->platformCode,
            $hash,
            $dataAtual,
            $this->nif,
            "PT"
        );

        $query = "SELECT id_transaction, id_order FROM paypay_payment WHERE paid = '0'";
        $pending_mb_payments = $wpdb->get_results($query);

        $pending_payments_data = array();
        $payments = array();
        foreach ($pending_mb_payments as $payment) {
            $payment_order_data[] = $payment->id_order;
            $data['paymentId']    = $payment->id_transaction;
            $requestReferenceDetails = new RequestReferenceDetails($data);
            $payments[] = $requestReferenceDetails;
        }

        $requestEntityPayments = new RequestEntityPayments();
        $requestEntityPayments->payments = $payments;

        try {
            $response = $paymentWebservice->checkEntityPayments($requestEntity, $requestEntityPayments);
        } catch (Exception $e) {
            return $e->getCode();
        }

        if ($response->state->state != 1 || empty($response->payments)) {
            return false;
        }

        if (empty($response->payments)) {
            return true;
        }

        $processed = 0;
        foreach ($response->payments as $index => $payment) {
            if (empty($payment->paymentState) && $payment->code === '0062') { // Não foi encontrado o pagamento
                $order_id = $payment_order_data[$index];
                $query = "UPDATE paypay_payment SET paid = '3' WHERE id_order=%d";
                $wpdb->query($wpdb->prepare($query, array($order_id)));
            }

            if ((int)$payment->paymentState !== 1) {
                continue;
            }

            $order_id = $payment_order_data[$index];
            $query = "UPDATE paypay_payment SET paid = '1' WHERE id_order=%d";
            $wpdb->query($wpdb->prepare($query, array($order_id)));

            $customer_order = new WC_Order($order_id);
            $customer_order->payment_complete($payment->paymentId);

            $processed++;
        }

        return $processed;
    }

	/**
	 * Sets the fields presented in the checkout
	 */
/*	public function payment_fields() {
        echo __('You will be redirected to www.paypay.pt to complete the payment.', 'paypay');
	}*/

	/**
	 * Sets the fields present in the settings of the plugin
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'		=> __( 'Activate / Deactivate', 'paypay' ),
				'label'		=> __( 'Activates the payment gateway', 'paypay' ),
				'type'		=> 'checkbox',
                'desc_tip'  => __( 'Title which the user you see during checkout.', 'paypay' ),
				'default'	=> 'no',
			),
/*			'title' => array(
				'title'		=> __( 'Title', 'paypay' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'Title which the user you see during checkout.', 'paypay' ),
				'default'	=> __( 'PayPay', 'paypay' ),
			),
			'description' => array(
				'title'		=> __( 'Description', 'paypay' ),
				'type'		=> 'textarea',
				'desc_tip'	=> __( 'Description which the user you see during checkout.', 'paypay' ),
				'default'	=> __( 'Choose your payment method:', 'paypay' ),
				'css'		=> 'max-width:350px;'
			),*/
			'nif' => array(
				'title'		=> __( 'NIF', 'paypay' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'NIF that is associated to you PayPay account.', 'paypay' ),
                'default'   => __( '510542700', 'paypay' ),
                'css'       => 'max-width:175px;',
			),
            'platformCode' => array(
                'title'     => __( 'Platform Code', 'paypay' ),
                'type'      => 'text',
                'desc_tip'  => __( 'Platform Code that should be requested from the PayPay support team.', 'paypay' ),
                'default'   => __( '0009', 'paypay' ),
                'css'       => 'max-width:175px;',
            ),
			'hash' => array(
				'title'		=> __( 'Encription Key', 'paypay' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'Encription Key that should be requested from the PayPay support team.', 'paypay' ),
                'default'   => __( '4F16A63E4ABA1', 'paypay' )
			),
			'environment' => array(
				'title'		=> __( 'Environment', 'paypay' ),
				'type'		=> 'select',
				'desc_tip'	=> __( 'Environment that should be used.', 'paypay' ),
				'css'		=> 'min-width:175px;',
				'options'   => array(
					'1' => __( 'Test', 'paypay' ),
					'2' => __( 'Production', 'paypay' )
				)
			)
		);
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
            $payment = $this->generateCC($customer_order);
        } catch (Exception $e) {
            wc_add_notice(__("No payment was processed.", 'paypay'), 'error' );
            return;
        }

        $customer_order->update_status('on-hold', __('Awaiting Payment', 'paypay'));


        return array(
            'result'   => 'success',
            'redirect' => $payment['url']
        );
	}

	/**
	 * Returns the layout to show the reference / link to the payment
	 * @param  [string] $method  [Method of the payment]
	 * @param  [string] $payment [Data relative to the payment]
	 * @return [string]          [HTML relative to the layout]
	 */
	protected function getPaymentLayout($method, $payment) {
		$html = "";

		if ($method == "1") {
            $html .= __( 'You can use the following information to pay your order in an ATM.', 'paypay' );
          	$html .= '<table cellpadding="3" cellspacing="0" style="width: 220px; font-weight: normal; padding: 5px 0 0 0; border: 0; margin: 0;">';
          	$html .= "	<tr>";
          	$html .= '		<td style="font-weight:bold; text-align:left; padding: 15px 0 0 0; border: 0;">'.__( 'Entity:', 'paypay' ).'</td>';
          	$html .= '		<td style=" padding-left: 5px; text-align:right; padding: 15px 0 0 0; border: 0;">'.$payment['atmEntity'].'</td>';
          	$html .= "	</tr>";
          	$html .= "	<tr>";
          	$html .= '	    <td style="font-weight:bold; text-align:left; padding: 5px 0 0 0; border: 0;">'.__( 'Reference:', 'paypay' ).'</td>';
          	$html .= '		<td style="padding-left: 5px; text-align:right; padding: 5px 0 0 0; border: 0;">'.$payment['reference'].'</td>';
          	$html .= "	</tr>";
          	$html .= "	<tr>";
          	$html .= '		<td style="font-weight:bold; text-align:left; padding: 5px 0 0 0; border: 0;">'.__( 'Amount:', 'paypay' ).'</td>';
          	$html .= '		<td style="padding-left: 5px; text-align:right; padding: 5px 0 0 0; border: 0;">'.wc_price($payment['amount']).'</td>';
          	$html .= "	</tr>";
          	$html .= "</table>";
		} elseif ($method == "2") {
			$html .= __( 'Click', 'paypay' ).' <a href="'.$payment['url'].'">'.__( 'here', 'paypay' ).'</a> '.__( 'to pay your order.', 'paypay' );
		}

		return $html;
	}

    public function getPayPayOrderNote($order_id)
    {
        global $wpdb;

        $query = "SELECT comment_id FROM paypay_reference WHERE id_order = %d";
        $res   = $wpdb->get_row($wpdb->prepare($query, array($order_id)));

        if (!isset($res)) {
            $query = "SELECT comment_id FROM paypay_payment WHERE id_order = %d";
            $res   = $wpdb->get_row($wpdb->prepare($query, array($order_id)));
        }

        if (!isset($res)) {
            return false;
        }

        return get_comment($res->comment_id);
    }

    public function addThankYouNote($order_id, $type)
    {
        $paypayOrderNote = $this->getPayPayOrderNote($order_id);

        if ($paypayOrderNote === false) {
            return false;
        }

        // Removes previous comment and adds the new thank you comment
        wp_delete_comment($paypayOrderNote->comment_ID);

        $customer_order = new WC_Order($order_id);
        return $customer_order->add_order_note($this->getThankYouLayout($type), 1, true);
    }

	/**
	 * Returns the layout to show the thank you sentence
	 * @param  [boolean] $success  [Whether the message is positive or not]
	 * @return [string]          [HTML relative to the layout]
	 */
	public function getThankYouLayout($success)
    {
        switch ($success) {
            case 2:
                $message = __( 'Unpaid order cancelled – time limit reached.', 'paypay' );
                break;
            case 1:
                $message = __( 'Thank you for your payment. Your order will be processed as soon as possible.', 'paypay' );
                break;
            default:
                $message = __( 'Your order was cancelled. Please, contact the store owner.', 'paypay' );
                break;
        }

		return $message;
	}

	/**
	 * Generates the CC payment data
	 * @param  [integer ] $order_id        [ID of the order]
	 * @param  [decimal ] $total           [Total of the order]
	 * @param  [WC_Order] $customer_order  [Order object]
	 * @return [array   ]                  [Payment data]
	 */
	protected function generateCC($customer_order)
    {
        $paymentWebservice = $this->getSoapClient();

        $date = new DateTime();
        $date->modify("+1 minutes");
        $date->modify("+1 hour");
        $dataAtual = $date->format("d-m-Y H:i:s");
        $amount    = number_format($customer_order->order_total, 2, '', '');

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

        $requestCreditCardPayment = new RequestCreditCardPayment(
            new RequestPaymentOrder(
                array(
                    'amount'        => $amount
                )
            ),
            $this->getReturnUrlFromCustomerOrder($customer_order),
            $url = get_site_url()."/?wc-api=paypay_cancel&order_id=".$customer_order->id,
            '',
            $this->paypay_method_code
        );


        $requestCreditCardPayment->returnUrlBack = $this->getReturnUrlFromCustomerOrder($customer_order);

        $response = $paymentWebservice->doWebPayment($requestEntity, $requestCreditCardPayment);

        if (!isset($response) || empty($response) || $response->requestState->state != "1") {
            throw new DomainException('Invalid PayPay CC response');
        }

        $payment = array(
            'url'           => $response->url,
            'idTransaction' => $response->idTransaction,
            'token'         => $response->token,
            'paid'          => "0"
        );

		$comment_id = $customer_order->add_order_note( $this->getPaymentLayout("2", $payment), 0, true );
		$payment['comment_id'] = $comment_id;

        $this->saveCC($customer_order->id, $payment);

        return $payment;
	}

	/**
	 * Saves the data relative to the CC payments
	 * @param  [integer] $order_id [ID of the order]
	 * @param  [array  ] $payment  [Payment data]
	 * @return [boolean]           [Result of the query]
	 */
	protected function saveCC($order_id, $payment) {
		global $wpdb;
		$query = "INSERT INTO paypay_payment VALUES(%d, '%s', '%s', '%s', '1', '0', %d)";
		$wpdb->query($wpdb->prepare($query, array($order_id, $payment['idTransaction'], $payment['token'], $payment['url'], $payment['comment_id'])));
		$query = "INSERT INTO paypay_payment_type VALUES(%d, '1')";
		return $wpdb->query($wpdb->prepare($query, array($order_id)));
	}

    protected function getReturnUrlFromCustomerOrder($customer_order)
    {
        if (is_user_logged_in()) {
            return $customer_order->get_view_order_url();
        }

        return $this->get_return_url($customer_order);
    }

	public function do_ssl_check() {
		if( $this->enabled == "yes" ) {
			if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
				echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";
			}
		}
	}
}
