<?php
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

/** Detect module name from filename */
$gatewayModuleName = basename(__FILE__, '.php');
/** Get gateway data */
$gateway = getGatewayVariables($gatewayModuleName);

/** Checks gateway module is active before accepting callback */
if (!$gateway["type"]) {
    die("Module Not Activated");
}

/* Get Returned Variables*/
$requestBody = file_get_contents("php://input");

// DEBUG
error_log("Callback");

/** Security check */
$key = $gateway['private_key'];
$checksum = hash_hmac("sha256", $requestBody, $key);
if ($checksum === $_SERVER["HTTP_QUICKPAY_CHECKSUM_SHA256"]) {
    /** Decode response */
    $request = json_decode($requestBody);

    $operation = end($request->operations);
    $orderType = $request->type;
    $operationType = $operation->type;
    $transid = $request->id;
    $invoiceid = $request->order_id;
    /** Strip prefix if any*/
    if (isset($gateway['prefix'])) {
        $invoiceid = substr(explode('_', $invoiceid)[0], strlen($gateway['prefix']));
    }
    /** Convert amount to decimal type */
    $amount = ($operation->amount / 100.0);

    /** In order to find any added fee, we must find the original order amount in the database */
    $tblinvoices_query = select_query("tblinvoices", "id,total", array("id" => $invoiceid));
    $tblinvoices = mysql_fetch_array($tblinvoices_query);
    /* Calculate the fee */
    $fee = $amount - $tblinvoices['total'];

    /** Checks invoice ID is a valid invoice number or ends processing */
    $invoiceid = checkCbInvoiceID($invoiceid, $gateway["name"]);

    /** Checks transaction number isn't already in the database and ends processing if it does */
    checkCbTransID($transid);

    /** If request is accepted, authorized and qp status is ok*/
    if ($request->accepted && ($operationType=='authorize' || $operationType=='recurring') && $operation->qp_status_code == "20000") {

        /** Add transaction to Invoice */
        if(($orderType == "Subscription" && $operationType != 'authorize') || $orderType != "Subscription"){
            /** Admin username needed for api commands */
            $adminuser = $gateway['whmcs_adminname'];
            /** Api request parameters */
            $values = [ 'invoiceid' => $invoiceid
                      , 'transid' => $transid
                      , 'amount' => $amount
                      , 'fee' => $fee
                      , 'gateway' => $gatewayModuleName
                      ];
            /** Add invoice payment request */
            localAPI("addinvoicepayment", $values, $adminuser);

            /** Add the fee to Invoice */
            if ($fee>0) {
                $values = [ 'invoiceid' => $invoiceid
                          , 'newitemdescription' => array("Payment fee")
                          , 'newitemamount' => array($fee)
                          , 'newitemtaxed' => array("0")
                          ];
                /** Update invoice request */
                localAPI("updateinvoice", $values, $adminuser);
            }
        }

        /** Get recurring values of invoice parent order */
        $recurringData = getRecurringBillingValues($invoiceid);

        /** If Subscription */
        if ($recurringData && isset($recurringData['primaryserviceid'])) {
            /** In order to find any added fee, we must find the original order amount in the database */
            $query_quickpay_transaction = select_query("quickpay_transactions", "transaction_id,paid", array("transaction_id" => (int)$transid));
            $quickpay_transaction = mysql_fetch_array($query_quickpay_transaction);

            if ($quickpay_transaction['paid'] == '0') {
                if ($operation->type=='authorize') {
                    /** Paid 1 on subscription parent record = authorized */
                    full_query("UPDATE quickpay_transactions SET paid = '1' WHERE transaction_id = '".(int)$transid."'");

                    require_once __DIR__ . '/../../../modules/gateways/quickpay.php';
                    /** Payment link from response */
                    $linkArray = json_decode(json_encode($request->link), TRUE);
                    /** Recurring payment parameters */
                    $params = [ "amount"                        => number_format(($linkArray['amount']/100.0), 2, '.', '') /** Convert amount to decimal type */
                              , "continue_url"                  => $linkArray['continue_url']
                              , "cancel_url"                    => $linkArray['cancel_url']
                              , "callback_url"                  => $linkArray['callback_url']
                              , "customer_email"                => $linkArray['customer_email']
                              , "payment_methods"               => $linkArray['payment_methods']
                              , "language"                      => $linkArray['language']
                              , "autocapture"                   => $gateway['autocapture']
                              , "autofee"                       => $gateway['autofee']
                              , "branding_id"                   => $gateway['quickpay_branding_id']
                              , "google_analytics_tracking_id"  => $gateway['quickpay_google_analytics_tracking_id']
                              , "google_analytics_client_id"    => $gateway['quickpay_google_analytics_client_id']
                              , "apikey"                        => $gateway['apikey']
                              , "invoiceid"                     => $invoiceid
                              ];

                    //DEBUG
                    error_log("Autorized callback");

                    /** SET subscription id in tblhosting if is empty, in order to enable autobiling and cancel methods*/
                    update_query("tblhosting", array("subscriptionid" => $transid), array("id" => $recurringData['primaryserviceid'],"subscriptionid" => ''));

                    /** Trigger recurring payment */
                    quickpay_create_payment_link((object)['id' => $transid/** Subscription ID */], $params, 'recurring');
                } else {

                    //DEBUG
                    error_log("Recuring callback");

                    /* If recurring payment succeeded set transaction as paid */
                    full_query("UPDATE quickpay_transactions SET paid = '1' WHERE transaction_id = '".(int)$transid."'");
                }
            }
        /** If Simple Payment */
        } else {
            //DEBUG
            error_log("Simple payment callback");

            /** Mark payment in custom table as processed */
            full_query("UPDATE quickpay_transactions SET paid = '1' WHERE transaction_id = '".(int)$transid."'");
        }

        /** Save to Gateway Log: name, data array, status */
        logTransaction($gateway["name"], $_POST, "Successful");
    } else {
        /** Save to Gateway Log: name, data array, status */
        logTransaction($gateway["name"], $_POST, "Unsuccessful");
    }
} else {
    /** Save to Gateway Log: name, data array, status */
    logTransaction($gateway["name"], $_POST, "Bad private key in callback, check configuration");
}
