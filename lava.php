<?php
/**
 * WHMCS Lava.ru Payment Gateway Module
 *
 * Payment gateway module for integration with Lava.ru payment system.
 * Documentation: https://dev.lava.ru/
 * Based on official SDK: https://github.com/LavaDevelop/lava-sdk
 *
 * @author Claude
 * @version 1.1
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * @return array
 */
function lava_MetaData()
{
    return array(
        'DisplayName' => 'Lava.ru Payment Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options.
 *
 * @return array
 */
function lava_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Lava.ru',
        ),
        'shopId' => array(
            'FriendlyName' => 'Shop ID',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Project UUID from your Lava.ru dashboard',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '100',
            'Default' => '',
            'Description' => 'Secret key for signing API requests',
        ),
        'webhookKey' => array(
            'FriendlyName' => 'Webhook Key (Additional Key)',
            'Type' => 'password',
            'Size' => '100',
            'Default' => '',
            'Description' => 'Additional key for webhook signature verification',
        ),
        'expireMinutes' => array(
            'FriendlyName' => 'Expire (minutes)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '60',
            'Description' => 'Invoice lifetime in minutes (max 7200 = 5 days)',
        ),
    );
}

/**
 * Generate HMAC SHA256 signature for Lava.ru API.
 * Based on official SDK implementation.
 *
 * @param array $data Request data (will be sorted by keys)
 * @param string $secretKey Secret key
 * @return string
 */
function lava_generateSignature($data, $secretKey)
{
    ksort($data);
    $jsonData = json_encode($data);
    return hash_hmac('sha256', $jsonData, $secretKey);
}

/**
 * Remove null values from array.
 *
 * @param array $data
 * @return array
 */
function lava_clearData($data)
{
    foreach ($data as $key => $value) {
        if (is_null($value)) {
            unset($data[$key]);
        }
    }
    return $data;
}

/**
 * Convert amount to RUB using exchange rate API.
 *
 * @param float $amount Amount to convert
 * @param string $currency Source currency code (e.g. 'USD')
 * @return float|false Converted amount in RUB, or false on failure
 */
function lava_convertToRub($amount, $currency)
{
    if ($currency === 'RUB') {
        return $amount;
    }

    $url = 'https://rates.as199566.net/' . urlencode($currency) . '/RUB';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || empty($response)) {
        logModuleCall('Lava', 'convertToRub', ['currency' => $currency], 'cURL Error: ' . $error, null);
        return false;
    }

    $data = json_decode($response, true);

    if (empty($data['rate']) || !is_numeric($data['rate'])) {
        logModuleCall('Lava', 'convertToRub', ['currency' => $currency], 'Invalid rate response: ' . $response, null);
        return false;
    }

    return round($amount * (float) $data['rate'], 2);
}

/**
 * Make API request to Lava.ru
 * Signature is passed in request body, not in header.
 *
 * @param string $endpoint API endpoint
 * @param array $data Request data
 * @param string $secretKey Secret key
 * @return array|false
 */
function lava_apiRequest($endpoint, $data, $secretKey)
{
    $baseUrl = 'https://api.lava.ru';
    $url = $baseUrl . $endpoint;

    // Remove null values
    $data = lava_clearData($data);

    // Generate signature and add to data
    $data['signature'] = lava_generateSignature($data, $secretKey);

    // Prepare JSON body
    $jsonBody = json_encode($data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logModuleCall('Lava', $endpoint, $data, 'cURL Error: ' . $error, $error);
        return false;
    }

    $result = json_decode($response, true);

    logModuleCall('Lava', $endpoint, $data, $response, $result);

    return $result;
}

/**
 * Payment link.
 *
 * Generates a payment link for the invoice.
 *
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function lava_link($params)
{
    // Gateway Configuration Parameters
    $shopId = $params['shopId'];
    $secretKey = $params['secretKey'];
    $expireMinutes = (int) $params['expireMinutes'] ?: 60;

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params['description'];
    $amount = $params['amount'];

    // todo: нормальное определение валюты
    $currency = 'USD';

    // System Parameters
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleName = $params['paymentmethod'];

    // Validate configuration
    if (empty($shopId) || empty($secretKey)) {
        return '<div class="alert alert-danger">Lava.ru: Module not configured properly</div>';
    }

    // Convert amount to RUB if needed (Lava only accepts RUB)
    if ($currency !== 'RUB') {
        $amountRub = lava_convertToRub($amount, $currency);
        if ($amountRub === false) {
            return '<div class="alert alert-danger">Lava.ru: Failed to fetch exchange rate for ' . htmlspecialchars($currency) . '/RUB</div>';
        }

        $amount = $amountRub;
    }

    // Generate unique orderId (Lava requires unique orderId for each request)
    $uniqueOrderId = $invoiceId . '_' . uniqid('', true);

    // Prepare request data (matching SDK CreateInvoiceDto)
    $requestData = [
        'sum' => (float) $amount,
        'orderId' => $uniqueOrderId,
        'shopId' => $shopId,
        'hookUrl' => $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php',
        'successUrl' => $returnUrl,
        'failUrl' => $returnUrl,
        'expire' => $expireMinutes,
        'comment' => $description,
    ];

    // Make API request to create invoice
    $response = lava_apiRequest('/business/invoice/create', $requestData, $secretKey);

    if (!$response) {
        return '<div class="alert alert-danger">Lava.ru: Failed to connect to payment gateway</div>';
    }

    // Check for error response
    if (!empty($response['error'])) {
        $errorMessage = is_array($response['error'])
            ? json_encode($response['error'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : $response['error'];
        return '<div class="alert alert-danger">Lava.ru Error: ' . htmlspecialchars($errorMessage) . '</div>';
    }

    if (isset($response['status']) && $response['status'] !== 200) {
        $errorMessage = isset($response['message']) ? $response['message'] : 'Unknown error (status: ' . $response['status'] . ')';
        return '<div class="alert alert-danger">Lava.ru Error: ' . htmlspecialchars($errorMessage) . '</div>';
    }

    if (!isset($response['data']['url'])) {
        $debugResponse = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return '<div class="alert alert-danger">Lava.ru: Invalid response - ' . htmlspecialchars($debugResponse) . '</div>';
    }

    $paymentUrl = $response['data']['url'];

    // Generate payment button
    $htmlOutput = '<a class="btn btn-primary" href="' . htmlspecialchars($paymentUrl) . '">' . htmlspecialchars($langPayNow) . '</a>';

    return $htmlOutput;
}
