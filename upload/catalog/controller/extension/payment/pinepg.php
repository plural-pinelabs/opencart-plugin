<?php
class Controllerextensionpaymentpinepg extends Controller {
	
  
  public function index() {
    $this->load->language('extension/payment/pinepg');
	$this->load->model('extension/payment/pinepg');
	$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
    $this->load->model('checkout/order');
	$this->load->model('catalog/product');
	$Order_Id=$this->session->data['order_id'];
    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);


	// Fetch product details
    $products = $this->cart->getProducts();
    $product_details = [];

    foreach ($products as $product) {
        $product_info = $this->model_catalog_product->getProduct($product['product_id']);
        $product_details[] = [
            'product_id'    => $product['product_id'],
            'name'          => $product['name'],
            'quantity'      => $product['quantity'],
            'price'         => $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], false),
            'total'         => $this->currency->format($product['total'], $order_info['currency_code'], $order_info['currency_value'], false),
            'model'         => $product_info['model'] ?? '',
            'sku'           => $product_info['sku'] ?? '',
        ];
    }

    $order_info['products'] = $product_details;
	// Fetch product details

// Only add coupon discount if it exists	
$coupon_discount = 0;
if (isset($this->session->data['coupon'])) {
    $this->load->model('extension/total/coupon');
    $coupon_info = $this->model_extension_total_coupon->getCoupon($this->session->data['coupon']);
    
    if ($coupon_info && !empty($coupon_info['discount'])) {
        $coupon_discount = abs($coupon_info['discount']); // Ensure it's positive
    }
}


if ($coupon_discount > 0) {
    $order_info['cart_coupon_discount_amount'] = $coupon_discount;
}

// Only add coupon discount if it exists

    
    $this->logger->write('[Order ID]:' . $Order_Id.'  Order Info: ' . serialize($order_info));
    
    if ($order_info) {
		
		
		$payment_response = $this->initiatePayment($order_info);

		$order_id_from_order_api=$payment_response['order_id'];

		if(!empty($order_id_from_order_api)){

			$this->model_extension_payment_pinepg->saveOrderMetadata($Order_Id, $order_id_from_order_api);

		}
		


		$this->logger->write('Redirect url is'. $payment_response['redirect_url']);

		if (!empty($payment_response['redirect_url'])) {
            $data['action'] =$payment_response['redirect_url'];

			$parsed_url = parse_url($payment_response['redirect_url']);
			parse_str($parsed_url['query'], $query_params);
            $token = isset($query_params['token']) ? $query_params['token'] : null;
			$data['token'] =$token;

        } else {
            $this->session->data['error'] = 'Payment initiation failed: ' . $payment_response['message'];
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }


		$data['button_confirm'] = $this->language->get('button_confirm');

	

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . 'extension/payment/pinepg')){
			return $this->load->view($this->config->get('config_template') . 'extension/payment/pinepg', $data);
		} else {
			return $this->load->view('extension/payment/pinepg', $data);
		}
}
  }



  private function is_part_payment_enabled() {
    return $this->config->get('payment_pinepg_enable_down_payment') == '1';
}

  private function initiatePayment($order_info) {
    $this->logger->write('Inside Initiate Payment method');

    $callback_url = $this->getCallbackUrl();
    $PinePgMode = $this->config->get('payment_pinepg_mode');
    $url = ($PinePgMode === "live")
        ? 'https://api.pluralpay.in/api/checkout/v1/orders'
        : 'https://pluraluat.v2.pinepg.in/api/checkout/v1/orders';

    $access_token = $this->getAccessToken();
    if (!$access_token) {
        return ['response_code' => 500, 'message' => 'Access token retrieval failed'];
    }

    // Prepare customer mobile
    $telephone = preg_replace('/\D/', '', $order_info['telephone']) ?: '9999999999';

    // Truncate addresses
    $billing_address = $this->truncateAddress($order_info['payment_address_1']);
    $shipping_address = $this->truncateAddress($order_info['shipping_address_1']);

    // Amounts in paise
    $grandTotal = intval(round(floatval($order_info['total']) * 100));
    $shippingAmount = isset($order_info['shipping_cost']) ? intval(round(floatval($order_info['shipping_cost']) * 100)) : 0;
    $couponDiscount = isset($order_info['cart_coupon_discount_amount']) ? abs(floatval($order_info['cart_coupon_discount_amount'])) : 0;

    $products = [];
    $totalProductValue = 0;

    // Calculate total item price incl. tax for proportional discount
    $totalItemTaxIncl = 0.0;
    foreach ($order_info['products'] as $product) {
        $priceInclTax = floatval($product['price'] + (isset($product['tax']) ? $product['tax'] : 0));
        $totalItemTaxIncl += $priceInclTax * $product['quantity'];
    }

    // Process products with proportional discount
    foreach ($order_info['products'] as $product) {
        $qty = intval($product['quantity']);
        if ($qty <= 0) continue;

        $priceInclTax = floatval($product['price'] + (isset($product['tax']) ? $product['tax'] : 0));
        $itemDiscount = isset($product['discount']) ? abs(floatval($product['discount'])) : 0;
        $sku = !empty($product['sku']) ? $product['sku'] : 'ITEM_' . $product['product_id'] . '_' . rand(10000, 99999);

        $itemTotal = $priceInclTax * $qty;

        $cartDiscountShare = $totalItemTaxIncl > 0
            ? ($itemTotal / $totalItemTaxIncl) * $couponDiscount
            : 0;

        $totalDiscount = $itemDiscount + $cartDiscountShare;
        $finalItemPrice = ($itemTotal - $totalDiscount) / $qty;
        $finalItemPrice = max(0, $finalItemPrice);

        $finalItemPricePaise = intval(round($finalItemPrice * 100));

        if ($finalItemPricePaise <= 0) {
            $this->logger->write("Skipping product with 0 price after discount: {$product['name']} (SKU: $sku)");
            continue;
        }

        $this->logger->write("Item: {$product['name']}, SKU: $sku, Qty: $qty, PriceInclTax: $priceInclTax, ItemDiscount: $itemDiscount, CartDiscountShare: $cartDiscountShare, FinalPrice: $finalItemPricePaise");

        for ($i = 0; $i < $qty; $i++) {
            $products[] = [
                'product_code' => $sku,
                'product_amount' => [
                    'value' => $finalItemPricePaise,
                    'currency' => 'INR',
                ],
            ];
            $totalProductValue += $finalItemPricePaise;
        }
    }

    // Add shipping as a product
    if ($shippingAmount > 0) {
        $products[] = [
            'product_code' => 'shipping_charge',
            'product_amount' => [
                'value' => $shippingAmount,
                'currency' => 'INR',
            ],
        ];
        $this->logger->write("Shipping added: ₹" . ($shippingAmount / 100));
        $totalProductValue += $shippingAmount;
    }

    // Rounding adjustment
    $roundingAdjustment = $grandTotal - $totalProductValue;
    if ($roundingAdjustment != 0) {
        $products[] = [
            'product_code' => 'rounding_adjustment',
            'product_amount' => [
                'value' => $roundingAdjustment,
                'currency' => 'INR',
            ],
        ];
        $this->logger->write("Adding rounding adjustment: ₹" . ($roundingAdjustment / 100));
        $totalProductValue += $roundingAdjustment;
    }

    // Validation
    if (abs($grandTotal - $totalProductValue) > 1) {
        $this->logger->write("Amount mismatch! GrandTotal: $grandTotal, Calculated: $totalProductValue");
        throw new \Exception("Amount calculation error - totals don't match");
    }

    $partPaymentEnabled = $this->is_part_payment_enabled();


    $payload = [
        'merchant_order_reference' => $order_info['order_id'] . '_' . date('ymdHis'),
        'order_amount' => [
            'value' => $grandTotal,
            'currency' => 'INR',
        ],
        'callback_url' => $callback_url,
        'pre_auth' => false,
        'integration_mode' => "REDIRECT",
        "plugin_data" => [
            "plugin_type" => "Opencart",
            "plugin_version" => "V3"
        ],
        'purchase_details' => [
            'customer' => [
                'email_id' => $order_info['email'],
                'first_name' => $order_info['firstname'],
                'last_name' => $order_info['lastname'],
                'mobile_number' => $telephone,
            ],
            'billing_address' => [
                'address1' => $billing_address,
                'pincode' => $order_info['payment_postcode'],
                'city' => $order_info['payment_city'],
                'state' => $order_info['payment_zone'],
                'country' => $order_info['payment_iso_code_2'],
            ],
            'shipping_address' => [
                'address1' => $shipping_address,
                'pincode' => $order_info['shipping_postcode'],
                'city' => $order_info['shipping_city'],
                'state' => $order_info['shipping_zone'],
                'country' => $order_info['shipping_iso_code_2'],
            ],
            'merchant_metadata' => [
									'express_checkout_allowed_action' => 'checkoutCollectAddress, checkoutCollectMobile'
							],
            'products' => $products,
        ],
    ];

    if ($partPaymentEnabled) {
        $payload['part_payment'] = true;
    }

    $body = json_encode($payload);
    $this->logger->write('V3 request log: ' . $body);

    $headers = [
        'Merchant-ID: ' . $this->config->get('payment_pinepg_merchantid'),
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ];

    $response = $this->sendPostRequest($url, $body, $headers);
    return json_decode($response, true);
}






	
public function truncateAddress($address) {
    return (strlen($address) > 100) ? substr($address, 0, 100) : $address;
}


public function getCallbackUrl() {
    // Check if running locally
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
        // Local environment
        return 'http://localhost/opencart/index.php?route=extension/payment/pinepg/callback';
    }

    // Production or staging environment
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $protocol . $_SERVER['HTTP_HOST'];
    
    return $domain . '/index.php?route=extension/payment/pinepg/callback';
}



private function getAccessToken() {

	$PinePgMode=$this->config->get('payment_pinepg_mode');
	if($PinePgMode == "live")
	{
		$url ='https://api.pluralpay.in/api/auth/v1/token';
	}else{
		$url ='https://pluraluat.v2.pinepg.in/api/auth/v1/token';
	}

    $access_code= $this->config->get('payment_pinepg_access_code');
	$secret_key= $this->config->get('payment_pinepg_secure_secret');


	$body = json_encode([
		'client_id' => $access_code,
		'client_secret' => $secret_key,
		'grant_type' => 'client_credentials'
	]);

	$response = $this->sendPostRequest($url, $body, ['Content-Type: application/json','Accept: application/json']);
	$data = json_decode($response, true);

	

	return $data['access_token'] ?? null;
}


public function sendPostRequest($url, $body, $headers) {
    $curl = curl_init($url);

    // Configure cURL options
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $body); // JSON-encode the body
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); // Set headers
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Expect response
	// Disable SSL certificate verification
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

    // Execute and capture the response
    $response = curl_exec($curl);

    // Check for cURL errors
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new Exception("cURL error: " . $error);
    }

    // Get HTTP response code
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($curl);

    

    // Check for HTTP errors
    if ($httpCode >= 400) {
        //throw new Exception("HTTP error $httpCode: " . $response);
    }

    return $response;
}


public function onOrderHistoryAdd($route, $args, $output) {

    $order_id=$args[0];
	$comment = isset($args[2]) ? (string)$args[2] : '';

	// The success history entry also dispatches this event; do not refund twice.
	if (strpos($comment, '[PinePG Refund]') !== false) {
		return;
	}

    $this->load->model('checkout/order');
	$this->load->model('catalog/product');
    
	$order_info = $this->model_checkout_order->getOrder($order_id);

	$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
    

    
	if (!empty($order_info) && $order_info['payment_code'] === 'pinepg' && strtolower($order_info['order_status']) === 'refunded') {
      $this->process_refund($order_info);
	}
	
	
}



public function process_refund($order_info, $amount = null, $reason = 'Initiated by merchant') {
	

	// Retrieve the Edge order ID from custom field or metadata
	$this->load->model('extension/payment/pinepg');
	$refund_order_id = $this->model_extension_payment_pinepg->getCheckoutOrderId($order_info['order_id']);
	if (empty($refund_order_id)) {
		$this->logger->write('Refund failed: Pine Labs order ID is missing for OpenCart order ' . (int)$order_info['order_id']);
		return false;
	}

	// Prepare API request URL
	$PinePgMode=$this->config->get('payment_pinepg_mode');
	if($PinePgMode == "live")
	{
		$url ='https://api.pluralpay.in/api/pay/v1/refunds/' . $refund_order_id;
	}else{
		$url ='https://pluraluat.v2.pinepg.in/api/pay/v1/refunds/' . $refund_order_id;
	}



	$refund_amount = $amount === null ? (float)$order_info['total'] : (float)$amount;
	if ($refund_amount <= 0 || $refund_amount > (float)$order_info['total']) {
		$this->logger->write('Refund failed: invalid refund amount for OpenCart order ' . (int)$order_info['order_id']);
		return false;
	}

	$orderamount = (int)round($refund_amount * 100);

	// Prepare the request payload
	$body = json_encode([
		'merchant_order_reference' => uniqid(),
		'order_amount' => ['value' => $orderamount, 'currency' => 'INR'],
		'merchant_metadata' => ['key1' => 'DD', 'key2' => 'XOF'],
		'refund_reason' => $reason,
	]);

	$merchant_id=$this->config->get('payment_pinepg_merchantid');

	// Set up headers
	$access_token = $this->getAccessToken();

	$headers = [
		'Merchant-ID: ' . $merchant_id,
		'Authorization: Bearer ' . $access_token,
		'Content-Type: application/json',
	];

	// Make the API call
	$response = $this->sendPostRequest($url, $body, $headers);

	$data = json_decode($response);
	$status = $data->data->status ?? null;
	$order_id = $data->data->order_id ?? null;
	$parent_order_id = $data->data->parent_order_id ?? null;

	// Handle the response
	if ($status === 'PROCESSED' && !empty($order_id)) {
		// Add order history note for successful refund
		    $this->load->model('checkout/order');
			$order_status_id = (int)$order_info['order_status_id'];
			$comment='[PinePG Refund] Refund successful for order ID: '.$order_info['order_id'].', Pine Labs payment ID: '.$parent_order_id.', Pine Labs refund ID: '.$order_id.', amount: '.number_format($refund_amount, 2, '.', '');
		    $this->model_checkout_order->addOrderHistory($order_info['order_id'], $order_status_id,$comment,false,false);
			$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
		    $this->logger->write('Refund successful for OpenCart order ' . (int)$order_info['order_id'] . ', Pine Labs refund ID ' . $order_id);

		return true;
	} else {
		$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
		$this->logger->write('Refund failed for OpenCart order ' . (int)$order_info['order_id'] . ': ' . $response);
		return false;
	}
}
  
  public function Hex2String($hex){
            $string='';
            for ($i=0; $i < strlen($hex)-1; $i+=2){
                $string .= chr(hexdec($hex[$i].$hex[$i+1]));
            }
            return $string;
        }
		

		public function callback() 
{
    $this->load->model('extension/payment/pinepg');
    $this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
    $this->logger->write('Callback() called with POST data: ' . print_r($_POST, true));

    $signature = $_POST['signature'];
    $order_id_from_api = $_POST['order_id'];
    $status = $_POST['status'];

    // Get OpenCart order ID from our metadata table
    $opencart_order_id = $this->model_extension_payment_pinepg->getOpenCartOrderId($order_id_from_api);
    
    if (!$opencart_order_id) {
        $this->logger->write("Error: No OpenCart order found for PinePG order: $order_id_from_api");
        $this->response->redirect($this->url->link('checkout/failure'));
        return;
    }

    // Verify payment status with retries (3 attempts, 20 seconds apart)
    $verified_status = $this->verifyPaymentStatusWithRetry($order_id_from_api);
    
    if ($verified_status === 'PROCESSED') {
        $this->handleSuccessfulPayment($opencart_order_id, $order_id_from_api);
    } else {
        $this->handleFailedPayment($opencart_order_id, $order_id_from_api, $verified_status);
    }
}

private function verifyPaymentStatusWithRetry($pinepg_order_id)
{
    $max_retries = 3;
    $retry_delay = 20; // seconds
    
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $this->logger->write("Enquiry API attempt $attempt for order: $pinepg_order_id");
        
        $response = $this->callEnquiryApi($pinepg_order_id);
        
        if ($response && isset($response['data']['status'])) {
            $status = $response['data']['status'];
            $this->logger->write("Enquiry API response status: $status");
            
            if ($status === 'PROCESSED') {
                return $status;
            }
        }
        
        // If not final attempt, wait before retrying
        if ($attempt < $max_retries) {
            sleep($retry_delay);
        }
    }
    
    return $status ?? 'UNKNOWN';
}

private function callEnquiryApi($pinepg_order_id)
{
    $PinePgMode = $this->config->get('payment_pinepg_mode');
    $url = ($PinePgMode == "live") 
        ? 'https://api.pluralpay.in/api/pay/v1/orders/' . $pinepg_order_id
        : 'https://pluraluat.v2.pinepg.in/api/pay/v1/orders/' . $pinepg_order_id;

    $access_token = $this->getAccessToken();
    if (!$access_token) {
        $this->logger->write("Error: Failed to get access token");
        return false;
    }

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Accept: application/json',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $this->logger->write("CURL Error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($http_code == 200) {
        return json_decode($response, true);
    }
    
    $this->logger->write("Enquiry API failed. HTTP Code: $http_code, Response: $response");
    return false;
}

private function handleSuccessfulPayment($opencart_order_id, $pinepg_order_id)
{
    $this->load->model('checkout/order');
    
    // Update order status
    $Order_Status = '2'; // Processing
    $comment = 'Payment verified and successful. PinePG ID: ' . $pinepg_order_id;
    $this->model_checkout_order->addOrderHistory($opencart_order_id, $Order_Status, $comment, true, false);

    // Set only the essential session data for success page
    $amount = $this->model_extension_payment_pinepg->getOpenCartAmount($opencart_order_id);
    
    

	$this->session->data = array_merge($this->session->data, [
        'ppc_Amount' => $amount * 100,
        'Order_No' => $opencart_order_id,
        'ppc_PinePGTransactionID' => $pinepg_order_id,
        'ppc_UniqueMerchantTxnID' => $pinepg_order_id,
        
        // Initialize all EMI-related variables with empty values
        'ppc_Is_BrandEMITransaction' => '',
        'ppc_IssuerName' => '',
        'ppc_EMIInterestRatePercent' => '',
        'ppc_EMIAmountPayableEachMonth' => '',
        'ppc_EMITotalDiscCashBackPercent' => '',
        'ppc_EMITotalDiscCashBackAmt' => '',
        'ppc_EMITenureMonth' => '',
        'ppc_EMICashBackType' => '',
        'ppc_EMIAdditionalCashBack' => ''
    ]);

    $this->logger->write("Successfully processed order $opencart_order_id with PinePG ID $pinepg_order_id");
    $this->response->redirect($this->url->link('extension/payment/pinepgsuccess'));
}

private function handleFailedPayment($opencart_order_id, $pinepg_order_id, $status)
{
    $this->load->model('checkout/order');
    
    $Order_Status = '10'; // Failed
    $comment = "Payment failed. Status: $status. PinePG ID: $pinepg_order_id";
    $this->model_checkout_order->addOrderHistory($opencart_order_id, $Order_Status, $comment, true, false);

    $this->logger->write("Payment failed for order $opencart_order_id. Status: $status");
    $this->response->redirect($this->url->link('checkout/failure'));
}

public function webhook()
{
    $this->load->model('extension/payment/pinepg');
    $this->load->model('checkout/order');

    $this->logger = new Log('pinepg_' . date("Y-m-d") . '.log');

    // Read raw body
    $rawBody = file_get_contents('php://input');
    $headers = $this->getWebhookHeaders();

    $this->logger->write('Webhook RAW HEADERS: ' . json_encode($headers));
    $this->logger->write('Webhook RAW BODY: ' . $rawBody);

    // 🔐 FIRST verify signature BEFORE processing payload
    if (!$this->verifyWebhookSignature($headers, $rawBody)) {
        $this->logger->write('Invalid webhook signature');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        return;
    }

    $this->logger->write('Signature verified successfully');

    $payload = json_decode($rawBody, true);

    if (
        empty($payload['event_type']) ||
        empty($payload['data']['order_id']) ||
        empty($payload['data']['status'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }

    // Only process successful payment
    if (
        $payload['event_type'] !== 'ORDER_PROCESSED' ||
        $payload['data']['status'] !== 'PROCESSED'
    ) {
        echo json_encode(['message' => 'Event ignored']);
        return;
    }

    $pineOrderId = $payload['data']['order_id'];

    // Map PinePG order → OpenCart order
    $opencartOrderId = $this->model_extension_payment_pinepg
        ->getOpenCartOrderId($pineOrderId);

    if (!$opencartOrderId) {
        $this->logger->write("Webhook error: Order not found for Pine order $pineOrderId");
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        return;
    }

    $order_info = $this->model_checkout_order->getOrder($opencartOrderId);

    // 🛑 Idempotency (already paid)
    if ((int)$order_info['order_status_id'] === 2) {
        $this->logger->write("Order $opencartOrderId already processed (status 2)");
        echo json_encode(['message' => 'Order already processed']);
        return;
    }

    // ✅ Mark order as PAID
    $comment = 'Payment confirmed via PinePG webhook. Pine Order ID: ' . $pineOrderId;

    $this->model_checkout_order->addOrderHistory(
        $opencartOrderId,
        2, // Processing
        $comment,
        true
    );

    $this->logger->write("Webhook success for order $opencartOrderId");

    echo json_encode(['message' => 'Webhook processed successfully']);
}

private function verifyWebhookSignature(array $headers, string $rawBody): bool
{
    $webhookId  = $headers['webhook-id'] ?? '';
    $timestamp  = $headers['webhook-timestamp'] ?? '';
    $signature  = $headers['webhook-signature'] ?? '';

    $this->logger->write("Signature check - ID: $webhookId, Timestamp: $timestamp, Sig: $signature");

    if (!$webhookId || !$timestamp || !$signature) {
        $this->logger->write("Missing webhook headers");
        return false;
    }

    // ⏱ Replay protection (5 minutes)
    $timeDiff = abs(time() - (int)$timestamp);
    if ($timeDiff > 300) {
        $this->logger->write("Timestamp expired. Diff: $timeDiff seconds");
        return false;
    }

    // Secret from OpenCart admin config - Use the SAME secret as in order creation
    $secret = $this->config->get('payment_pinepg_secure_secret');
    
    if (!$secret) {
        $this->logger->write("No webhook secret configured");
        return false;
    }

    $this->logger->write("Secret length: " . strlen($secret));
    
    // 🔑 IMPORTANT: The secret needs to be base64 encoded first (as per PinePG docs)
    $base64Secret = base64_encode($secret);
    $this->logger->write("Base64 encoded secret: $base64Secret");
    
    // Then decode it to get the actual bytes
    $secretBytes = base64_decode($base64Secret);
    
    if ($secretBytes === false) {
        $this->logger->write("Failed to base64 decode the secret");
        return false;
    }

    // PinePG signing format: webhook-id.timestamp.body
    $signedPayload = $webhookId . '.' . $timestamp . '.' . $rawBody;
    $this->logger->write("Signed payload length: " . strlen($signedPayload));
    $this->logger->write("Raw body length: " . strlen($rawBody));

    // Generate expected signature
    $expectedSignature = base64_encode(
        hash_hmac('sha256', $signedPayload, $secretBytes, true)
    );

    // Header format: v1,XXXX
    $receivedSignature = str_replace('v1,', '', $signature);

    $this->logger->write("Expected signature: $expectedSignature");
    $this->logger->write("Received signature: $receivedSignature");

    // Use hash_equals for timing attack protection
    $result = hash_equals($expectedSignature, $receivedSignature);
    
    if (!$result) {
        $this->logger->write("Signature mismatch!");
    }
    
    return $result;
}

private function getWebhookHeaders(): array
{
    $headers = [];
    
    // First try to get headers using getallheaders() if available
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if ($headers) {
            // Convert all keys to lowercase for consistency
            $headers = array_change_key_case($headers, CASE_LOWER);
            return $headers;
        }
    }
    
    // Fallback: extract headers from $_SERVER
    foreach ($_SERVER as $key => $value) {
        // Handle standard HTTP_ headers
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = str_replace('_', '-', substr($key, 5));
            $headers[strtolower($headerName)] = $value;
        }
        // Handle CONTENT_ headers (like CONTENT_TYPE, CONTENT_LENGTH)
        elseif (strpos($key, 'CONTENT_') === 0) {
            $headerName = str_replace('_', '-', $key);
            $headers[strtolower($headerName)] = $value;
        }
    }
    
    return $headers;
}

		
}
?>
