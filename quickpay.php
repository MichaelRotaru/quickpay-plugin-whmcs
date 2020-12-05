<?php
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Get module metadata
 *
 * @return array
 */
function quickpay_MetaData()
{
    return array(
        'DisplayName' => 'QuickPay',
        'APIVersion' => '1.1',
        'DisableLocalCredtCardInput' => TRUE,
        'TokenisedStorage' => FALSE,
    );
}

/**
 * Get module configuration
 *
 * @return array
 */
function quickpay_config()
{
    quickpay_verify_table();

    $config = array(
        "FriendlyName" => array(
            "Type" => "System",
            "Value" => "Quickpay"
        ),
        "quickpay_versionnumber" => array("FriendlyName" => "Installed module version", "Type" => null, "Description" => "2.3.3", "Size" => "20", "disabled" => TRUE),
        "whmcs_adminname" => array("FriendlyName" => "WHMCS administrator username", "Type" => "text", "Value" => "admin", "Size" => "20",),
        "merchant" => array("FriendlyName" => "Merchant ID", "Type" => "text", "Size" => "30",),
        "md5secret" => array("FriendlyName" => "Payment Window Api Key", "Type" => "text", "Size" => "60",),
        "apikey" => array("FriendlyName" => "API Key", "Type" => "text", "Size" => "60",),
        "private_key" => array("FriendlyName" => "Private Key", "Type" => "text", "Size" => "60",),
        "agreementid" => array("FriendlyName" => "Agreement ID", "Type" => "text", "Size" => "30",),
        "language" => array("FriendlyName" => "Language", "Type" => "dropdown", "Options" => "da,de,en,es,fi,fr,fo,kl,it,no,nl,pl,sv,ru",),
        "autofee" => array("FriendlyName" => "Autofee", "Type" => "dropdown", "Options" => "0,1",),
        "autocapture" => array("FriendlyName" => "Autocapture", "Type" => "dropdown", "Options" => "0,1",),
        "payment_methods" => array("FriendlyName" => "Payment Method", "Type" => "text", "Size" => "30", "Value" => "creditcard"),
        "prefix" => array("FriendlyName" => "Order Prefix", "Type" => "text", "Size" => "30",),
        "quickpay_branding_id" => array("FriendlyName" => "Branding ID", "Type" => "text", "Size" => "30",),
        "quickpay_google_analytics_tracking_id" => array("FriendlyName" => "Google Analytics Tracking ID", "Type" => "text", "Size" => "30",),
        "quickpay_google_analytics_client_id" => array("FriendlyName" => "Google Analytics Client ID", "Type" => "text", "Size" => "30",),
        "link_text" => array("FriendlyName" => "Pay now text", "Type" => "text", "Value" => "Pay Now", "Size" => "60",)
    );

    return $config;
}

/**
 * Create payment, get payment link and redirect
 *
 * @param $params
 *
 * @return string
 */
function quickpay_link($params)
{
    // DEBUG:
    error_log("Trigger link");

    /** Get payment URL */
    $payment = quickpay_get_payment($params);
    /** Payment button HTML body */
    $code = sprintf('<a href="%s">%s</a>', $payment, $params['link_text']);
    $cart = $_GET['a'];

    /** Inject redirect parameters in page header */
    if ($cart == 'complete') {
        $invoiceId = $params['invoiceid'];
        header('Location: viewinvoice.php?id='.$invoiceId.'&qpredirect=true');
    }

    /** Determine if we should autoredirect */
    if ($_GET['qpredirect']) {
        $code .= '<script type="text/javascript">window.location.replace("'.$payment.'");</script>';
    }

    return $code;
}

/**
 * Get or create payment
 *
 * @param $params
 *
 * @return mixed
 */
function quickpay_get_payment($params)
{
    /** Get PDO and determine if payment exists and is usable */
    $pdo = Capsule::connection()->getPdo();
    /** Get transaction data from quickpay custom table */
    $statement = $pdo->prepare("SELECT * FROM quickpay_transactions WHERE invoice_id = :invoice_id ORDER BY id DESC");
    $statement->execute([
        ':invoice_id' => $params['invoiceid'],
    ]);

    /** Determine if invoice is part of subscription or not */
    $payment_type = getInvoiceType($params['invoiceid']);

    $result = $statement->fetch();
    if ($result > 0) {
        /** New payment needs creating */
        if ($result['paid'] && $payment_type !== 'subscription') {
            /** unique order id required for new payment */
            $params['suffix'] = '_'.$statement->rowCount();
            /** fall through to create payment below */
        /** Invoice amount changed, payment link needs updating */
        } elseif ($result['amount'] != $params['amount']) {
            return quickpay_create_payment_link(json_decode('{"id":'.$result['transaction_id'].'}'), $params, $payment_type);
        /** Existing payment link still OK */
        } else {
            return $result['payment_link'];
        }
    }

    /** If payment | subscription doesn't exist, create it */
    if ($payment_type === 'subscription') {
        $paymentlink = quickpay_create_subscription($params);
    } else {
        $paymentlink = quickpay_create_payment($params);
    }

    return $paymentlink;
}

/**
 * Create QuickPay payment and trigger payment link
 *
 * @param $params
 * @return mixed
 *
 * @throws Exception
 */
function quickpay_create_payment($params)
{
    /** Build request parameters array */
    $request = quickpay_request_params($params);
    /** Create gateway payment */
    $payment = quickpay_request($params['apikey'], '/payments', $request, 'POST');

    if (!isset($payment->id)) {
        throw new Exception('Failed to create payment');
    }

    //DEBUG
    error_log("New simple payment");

    /** Do payment - payment link URL expected*/
    $paymentLink = quickpay_create_payment_link($payment, $params);
    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Create payment request complete');

    return $paymentLink;
}

/**
 * Create QuickPay subscription and trigger payment link | trigger recurring payment
 *
 * @param $params
 *
 * @return mixed
 * @throws Exception
 */
function quickpay_create_subscription($params)
{
    /** Get invoice parent order recurring values */
    $recurringData = getRecurringBillingValues($params['invoiceid']);

    /** Check if invoice parent order is subscription type*/
    if ($recurringData && isset($recurringData['primaryserviceid'])) {
        /** Get active subscription id */
        $result = select_query("tblhosting", "id, subscriptionid", ["orderid" => $recurringData['primaryserviceid']]);
        $data = mysql_fetch_array($result);
        $activeSubscriptionId = $data['subscriptionid'];
    }

    /** Check if active subscription */
    if ($activeSubscriptionId > 0) {
        //DEBUG
        error_log("active subscription");
        /** Do subscription recurring payment - null payment link expected*/
        $paymentLink = quickpay_create_payment_link((object)['id' => $activeSubscriptionId], $params, 'recurring');
    } else {
        //DEBUG
        error_log("new subscription");

        $request = quickpay_request_params($params);
        /** Create gateway subscription */
        $payment = quickpay_request($params['apikey'], '/subscriptions', $request, 'POST');

        logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Create subscription request complete');

        /** Fail */
        if (! isset($payment->id)) {
            throw new Exception('Failed to create subscription');
        }
        /** Do subscription first payment - payment link URL expected*/
        $paymentLink = quickpay_create_payment_link($payment, $params, 'subscription');
    }

    return $paymentLink;
}

/**
 * Create payment link
 *
 * @param $payment - gateway payment | gateway subscription
 * @param $params
 * @param string $type - payment | subscription | recurring
 *
 * @return string - Payment URL | Null
 * @throws Exception
 */
function quickpay_create_payment_link($payment, $params, $type = 'payment')
{
    /** Quickpay API key */
    $apiKey = $params['apikey'];

    /** Gateway request parameters array */
    $request = [ "amount"                       => str_replace('.', '', $params['amount'])
               , "continue_url"                 => $params['returnurl']
               , "cancel_url"                   => $params['returnurl']
                //TODO - switch to systemurl
               , "callback_url"                 => 'http://a160c3b18df9.ngrok.io/whmcs/' . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php'//$params['systemurl']
               , "customer_email"               => $params['clientdetails']['email']
               , "payment_methods"              => $params['payment_methods']
               , "language"                     => $params['language']
               , "auto_capture"                 => $params['autocapture']
                /** TYPO FIX */
               , "autocapture"                  => $params['autocapture']
               , "autofee"                      => $params['autofee']
                /** TYPO FIX */
               , "auto_fee"                     => $params['autofee']
               , "branding_id"                  => $params['quickpay_branding_id']
               , "google_analytics_tracking_id" => $params['quickpay_google_analytics_tracking_id']
               , "google_analytics_client_id"   => $params['quickpay_google_analytics_client_id']
               ];

    /** Check if transaction type is recurring */
    if ($type === 'recurring') {
        /** Construt orderid string */
        $request["order_id"] = sprintf('%s%04d_r', $orderPrefix, $params['invoiceid']);
        /** Request endpoint */
        $endpoint = sprintf('subscriptions/%s/recurring', $payment->id/** Subscription_id */);
        $response = quickpay_request($apiKey, $endpoint, $request, 'POST');
        /** Current tranzaction id */
        $payment->id = $response->id;


        if (!isset($response->id)) {
            throw new Exception('Failed to create recurring payment');
        }
        logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Recurring payment request complete');
    } else {
        /** Construt request endpoint URL based on payment type */
        $endpoint = sprintf('payments/%s/link', $payment->id);
        if ($type === 'subscription') {
            $endpoint = sprintf('subscriptions/%s/link', $payment->id);
        }
        /** Payment link request */
        $paymentlink = quickpay_request($apiKey, $endpoint, $request, 'PUT');
        /** Fail */
        if (!isset($paymentlink->url)) {
            throw new Exception('Failed to create payment link');
        }
        logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Create payment link request complete');

        // DEBUG:
        error_log("Payment link created");
    }

    /** Save transaction data to custom table */
    $pdo = Capsule::connection()->getPdo();
    $pdo->beginTransaction();


    // DEBUG
    error_log("Custom table log");
    try {
        /** Replace old payment link if one already exists */
        $pdo->prepare(
            'DELETE FROM quickpay_transactions WHERE transaction_id = :transaction_id AND paid != 1'
        )->execute([
            ':transaction_id' => $payment->id,
        ]);

        /** Insert operation */
        $statement = $pdo->prepare(
            'INSERT INTO quickpay_transactions (invoice_id, transaction_id, payment_link, amount, paid) VALUES (:invoice_id, :transaction_id, :payment_link, :amount, 0)'
        )->execute([
            ':invoice_id' => $params['invoiceid'],
            ':transaction_id' => $payment->id,
            ':payment_link' => isset($paymentlink->url)?$paymentlink->url:'',
            ':amount' => $params['amount'],
        ]);

        $pdo->commit();

    } catch (\Exception $e) {
        /** DB operations fail */
        $pdo->rollBack();
        throw new Exception('Failed to create payment link, please try again later');
    }

    /** Return payment link if payment or subscription and null if recurring payment */
    return isset($paymentlink->url)?$paymentlink->url:null;
}

/**
 * Cancel subscription.
 *
 * If the payment gateway creates subscriptions and stores the subscription ID in tblhosting.subscriptionid, this function is called upon cancellation or request by an admin user.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Request response status
 * @throws Exception
 */
function quickpay_cancelSubscription($params)
{
    // DEBUG
    error_log('Cancel call - Test msg');
    error_log($params['subscriptionID']);

    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Cancel subscription request received');

    /** Gateway request parameters array */
    $request = ['id' => $params['subscriptionID']];

    /** Gateway cancel request */
    $response = quickpay_request($params['apikey'], sprintf('subscriptions/%s/cancel', $params['subscriptionID']), $request, 'POST');

    /** Fail due to a connection issue */
    if (!isset($response)) {
        throw new Exception('Failed to cancel subsciption payment');
    }

    // DEBUG
    error_log(print_r($response,TRUE));

    /** Fail due to a gateway issue */
    if (!isset($response->id)) {
        return array(
          /** 'success' if successful, any other value for failure */
          'status' => 'failed',
           /** Data to be recorded in the gateway log - can be a string or array */
          'rawdata' => $response->message,
      );
    }

    /** Success */
    return array(
        /** 'success' if successful, any other value for failure */
        'status' => 'success',
        /** Data to be recorded in the gateway log - can be a string or array */
        'rawdata' => 'Subscription successfully canceled',
    );
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Transaction response status
 * @throws Exception
 */
function quickpay_refund($params)
{
    // DEBUG
    error_log('Refund call - Test msg');

    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Refund request received');

    /** Get invoice data */
    $invoice = localAPI(/**command*/'GetInvoice', /**postData*/['invoiceid' => $params['invoiceid']]);

    /** Gateway request parameters array */
    $request = [ 'id'       => $params['transid']
               , 'amount'   => str_replace('.', '', $params['amount'])
               , 'vat_rate' => number_format((float) $invoice['taxrate'] > 0?(float) $invoice['taxrate'] : (float) $invoice['taxrate2'], 2, '.', '')
               ];

    /** Gateway retund request */
    $response = quickpay_request($params['apikey'], sprintf('payments/%s/refund', $params['transid']), $request, 'POST');

    /** Fail due to a gateway connection issue */
    if (!isset($response)) {
        throw new Exception('Failed to refund payment');
    }

    // DEBUG
    error_log(print_r($response,TRUE));

    /** Fail due to a gateway issue */
    if (!isset($response->id)) {
        return array(
          /** 'success' if successful, any other value for failure */
          'status' => 'failed',
           /** Data to be recorded in the gateway log */
          'rawdata' => $response->message,
          'transid' => $params['transid']
      );
    }

    /** Success */
    return array(
        /** 'success' if successful, any other value for failure */
        'status' => 'success',
        /** Data to be recorded in the gateway log */
        'rawdata' => 'Transaction successfully refunded',
        'transid' => $params['transid'],
         /* Optional fee amount for the fee value refunded */
        'fees' => $response->fee
    );
}

/******************** Custom Quickpay functions START ***********************/

/**
 * Create quickpay payment | subscription request parameters
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Request parameters
 */
function quickpay_request_params($params)
{
    /** Request array */
    $request_arr = [ 'currency' => $params['currency']
                   , 'order_id' => sprintf('%s%04d%s', $params['prefix'], $params['invoiceid'], isset($params['suffix'])?$params['suffix']:'')
                   , 'description' => $params['description']
                   , 'branding_id' => $params['quickpay_branding_id']
                   ];

    $invoice_address = [ 'name' => $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname']
                       , 'company_name' => $params['clientdetails']['companyname']
                       , 'street' => (!empty($params['clientdetails']['address2'])) ? ($params['clientdetails']['address1'] . ', ' . $params['clientdetails']['address2']) : ($params['clientdetails']['address1'])
                       , 'city' => $params['clientdetails']['city']
                       , 'zip_code' => $params['clientdetails']['postcode']
                       , 'region' => $params['clientdetails']['state']
                       , 'phone_number' => $params['clientdetails']['phonenumber']
                       , 'email' => $params['clientdetails']['email']
                       ];

    $request_arr['invoice_address'] = $invoice_address;

    /** Extract the invoice items details. */
    $invoice = localAPI(/**command*/'GetInvoice', /**postData*/['invoiceid' => $params['invoiceid']]);

    $basket = [];
    foreach ($invoice['items']['item'] as $item) {
        $basket[] = [ 'qty' => 1
                    , 'item_no' => (string)$item['id']
                    , 'item_name' => $item['description']
                    , 'item_price' => (int) $item['amount']
                    , 'vat_rate' => number_format((float) $invoice['taxrate'] > 0?(float) $invoice['taxrate'] : (float) $invoice['taxrate2'], 2, '.', '')
                    ];
    }
    $request_arr['basket'] = $basket;

    /** Convert array to one dimension format */
    $request = flatten_params($request_arr);
    return $request;
}

/**
 * Perform a request to the QuickPay API
 *
 * @param $endpoint
 * @param array $params
 * @param string $method
 *
 * @return mixed
 * @throws Exception
 */
function quickpay_request($apikey = '', $endpoint = '', $params = array(), $method = 'GET')
{
    /** Endpoint URL */
    $url = 'https://api.quickpay.net/' . $endpoint;

    /** Request header */
    $headers = array(
        'Accept-Version: v10',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(':' . $apikey),
    );

    /** Request parameters */
    $options = array(
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_SSL_VERIFYPEER => TRUE,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_URL => $url,
        CURLOPT_POSTFIELDS => $params,
    );

    /** Do request */
    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);

    /** Check for errors */
    if (curl_errno($ch) !== 0) {
        /** Fail */
        throw new Exception(curl_error($ch), curl_errno($ch));
    }

    /** Close request */
    curl_close($ch);
    return json_decode($response);
}

/******************** Custom Quickpay functions END *************************/

/**************** Custom Quickpay DB table functions START ******************/

/**
 * Install quickpay custom table
 *
 * @param PDO $pdo
 */
function quickpay_install_table(PDO $pdo)
{
    $pdo->beginTransaction();

    try {
        $query = "CREATE TABLE IF NOT EXISTS `quickpay_transactions` (
            `id`             int(10) NOT NULL AUTO_INCREMENT,
            `invoice_id`     int (10) UNSIGNED NOT NULL,
            `transaction_id` int (32) UNSIGNED NOT NULL,
            `payment_link`   varchar(255) NOT NULL,
            `amount`         decimal(10,2) NOT NULL,
            `paid`           tinyint(1) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `id` (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

        $statement = $pdo->prepare($query);
        $statement->execute();

        $pdo->commit();
    } catch (\Exception $e) {
        /** Fail */
        $pdo->rollBack();
        logActivity('Error during quickpay table creation: '.$e->getMessage());
    }
}

/**
 * Update quickpay custom table
 *
 * @param PDO $pdo
 */
function quickpay_update_table(PDO $pdo)
{
    $pdo->beginTransaction();

    try {
        $query = "ALTER TABLE `quickpay_transactions`
            ADD `amount` decimal(10,2) NOT NULL,
            ADD `paid` tinyint(1) unsigned NOT NULL AFTER `amount`";

        $statement = $pdo->prepare($query);
        $statement->execute();

        $pdo->commit();
    } catch (\Exception $e) {
        /** Fail */
        $pdo->rollBack();
        logActivity('Error during quickpay table update: '.$e->getMessage());
    }
}

/**
 * Check for quickpay custom table and create if not exists
 */
function quickpay_verify_table()
{
    /** Get PDO and check if table exists */
    $pdo = Capsule::connection()->getPdo();

    $result = $pdo->query("SHOW TABLES LIKE 'quickpay_transactions'");
    $row = $result->fetch(PDO::FETCH_ASSOC);

    /** If not create it */
    if ($row === FALSE) {
        quickpay_install_table($pdo);
    } else {
        /** check table has columns added in 2020_07 version */
        $result = $pdo->query("SHOW COLUMNS FROM `quickpay_transactions` LIKE 'amount'");
        $row = $result->fetch(PDO::FETCH_ASSOC);

        if ($row === FALSE) {
            /** If not, add them */
            quickpay_update_table($pdo);
        }
    }
}

/****************** Custom Quickpay DB table functions END ******************/

/************************** Helper functions START **************************/

/**
 * Signs the setup parameters
 * @param array $params
 * @param array $api_key
 *
 * @return string encoded string
 */
function sign($params, $api_key)
{
    ksort($params);
    $base = implode(" ", $params);

    return hash_hmac("sha256", $base, $api_key);
}

/** Convert multidimensional array|object into one dimension array
 * @param array|object $obj
 * @param array $result
 * @param array $path
 *
 * @return array - unidimensional array
 */
function flatten_params($obj, $result = array(), $path = array())
{
    if (is_array($obj)) {
        foreach ($obj as $k => $v) {
            $result = array_merge($result, flatten_params($v, $result, array_merge($path, array($k))));
        }
    } else {
        $result[implode("", array_map(function ($p) {
            return "[{$p}]";
        }, $path))] = $obj;
    }
    return $result;
}

/** Determine if invoice is part of subscription or not
 * @param string $invoiceid
 *
 * @return string - invoice type
 */
function getInvoiceType($invoiceid){
  $recurringData = getRecurringBillingValues($invoiceid);
  if($recurringData && isset($recurringData['primaryserviceid'])){
      return 'subscription';
  }else{
      return 'payment';
  }
}

/************************** Helper functions END **************************/
