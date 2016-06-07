<?php
/**
/ ********************************************************************* \
 *                                                                      *
 *   Paystack Payment Gateway                                           *
 *   Version: 1.0.0                                                     *
 *   Build Date: 15 May 2016                                            *
 *                                                                      *
 ************************************************************************
 *                                                                      *
 *   Email: support@paystack.com                                        *
 *   Website: https://www.paystack.com                                  *
 *                                                                      *
\ ********************************************************************* /
**/

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
$invoiceId = filter_input(INPUT_GET, "invoiceid");
$trxref = filter_input(INPUT_GET, "trxref");

if ($gatewayParams['testMode'] == 'on') {
    $secretKey = $gatewayParams['testSecretKey'];
} else {
    $secretKey = $gatewayParams['liveSecretKey'];
}


if(strtolower(filter_input(INPUT_GET, 'go'))==='standard'){
    // falling back to standard
    $ch = curl_init();

    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    
    $amountinkobo = filter_input(INPUT_GET, 'amountinkobo');
    $email = filter_input(INPUT_GET, 'email');
    $phone = filter_input(INPUT_GET, 'phone');

    $callback_url = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        $_SERVER['REQUEST_URI'] . '?invoiceid=' . rawurlencode($invoiceId);

    $txStatus = new stdClass();

    // set url
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/initialize/");

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
        'Authorization: Bearer '. trim($secretKey)
        )
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        http_build_query(
            array(
            "amount"=>$amountinkobo,
            "email"=>$email,
            "phone"=>$phone,
            "callback_url"=>$callback_url
            )
        )
    );
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);

    // exec the cURL
    $response = curl_exec($ch);

    // should be 0
    if (curl_errno($ch)) {
        // curl ended with an error
        $txStatus->error = "cURL said:" . curl_error($ch);
        curl_close($ch);
    } else {
        //close connection
        curl_close($ch);

        // Then, after your curl_exec call:
        $body = json_decode($response);
        if (!$body->status) {
            // paystack has an error message for us
            $txStatus->error = "Paystack API said: " . $body->message;
        } else {
            // get body returned by Paystack API
            $txStatus = $body->data;
        }
    }
    if(!$txStatus->error){
        header('Location: ' . $txStatus->authorization_url);
        die('<meta http-equiv="refresh" content="0;url='.$txStatus->authorization_url.'" />
        Redirecting to <a href=\''.$txStatus->authorization_url.'\'>'.$txStatus->authorization_url.'</a>...');
    } else {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            $output = "Transaction Initialize failed"
                . "\r\nReason: {$txStatus->error}";
            logTransaction($gatewayModuleName, $output, "Unsuccessful");
        }
        die($txStatus->error);
    }
}
/**
 * Verify Paystack transaction.
 */
$txStatus = verifyTransaction($trxref, $secretKey);

if ($txStatus->error) {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: failed"
            . "\r\nReason: {$txStatus->error}";
        logTransaction($gatewayModuleName, $output, "Unsuccessful");
    }
    $success = false;
} elseif ($txStatus->status == 'success') {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: succeeded";
        logTransaction($gatewayModuleName, $output, "Successful");
    }
    $success = true;
} else {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: {$txStatus->status}";
        logTransaction($gatewayModuleName, $output, "Unsuccessful");
    }
    $success = false;
}

if ($success) {
    /**
     * Validate Callback Invoice ID.
     *
     * Checks invoice ID is a valid invoice number.
     *
     * Performs a die upon encountering an invalid Invoice ID.
     *
     * Returns a normalised invoice ID.
     */
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

    /**
     * Add Invoice Payment.
     *
     * Applies a payment transaction entry to the given invoice ID.
     *
     * @param int $invoiceId         Invoice ID
     * @param string $transactionId  Transaction ID
     * @param float $paymentAmount   Amount paid (defaults to full balance)
     * @param float $paymentFee      Payment fee (optional)
     * @param string $gatewayModule  Gateway module name
     */
    addInvoicePayment($invoiceId, $trxref, floatval($txStatus->amount)/100, 0, $gatewayModuleName);

    // load invoice
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
        
    $invoice_url = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/../../../viewinvoice.php?id='.
        rawurlencode($invoiceId);

    header('Location: '.$invoice_url);
} else {
    die($txStatus->error . ' ; ' . $txStatus->status);
}

function verifyTransaction($trxref, $secretKey)
{
    $ch = curl_init();
    $txStatus = new stdClass();

    // set url
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/" . rawurlencode($trxref));

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
        'Authorization: Bearer '. trim($secretKey)
        )
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    
    // exec the cURL
    $response = curl_exec($ch);
    
    // should be 0
    if (curl_errno($ch)) {
        // curl ended with an error
        $txStatus->error = "cURL said:" . curl_error($ch);
        curl_close($ch);
    } else {
        //close connection
        curl_close($ch);

        // Then, after your curl_exec call:
        $body = json_decode($response);
        if (!$body->status) {
            // paystack has an error message for us
            $txStatus->error = "Paystack API said: " . $body->message;
        } else {
            // get body returned by Paystack API
            $txStatus = $body->data;
        }
    }

    return $txStatus;
}
