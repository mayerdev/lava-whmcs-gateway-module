<?php
/**
 * WHMCS Lava.ru Payment Gateway Callback Handler
 *
 * Handles webhook notifications from Lava.ru payment system.
 * Documentation: https://dev.lava.ru/
 *
 * @author Claude
 * @version 1.0
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

// Detect module name from filename
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active
if (!$gatewayParams['type']) {
    http_response_code(400);
    die('Module Not Activated');
}

// Only accept POST requests (webhooks)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo 'OK';
    exit;
}

// Get raw POST data
$rawInput = file_get_contents('php://input');

// Parse JSON data
$data = json_decode($rawInput, true);

// Log incoming request for debugging
logTransaction($gatewayParams['name'], [
    'raw_input' => $rawInput,
    'parsed_data' => $data,
    'headers' => getallheaders(),
], 'Webhook Received');

// Validate webhook data
if (empty($data)) {
    logTransaction($gatewayParams['name'], 'Empty webhook data', 'Failure');
    http_response_code(400);
    die('Invalid request');
}

// Get required fields from webhook
$invoiceUuid = isset($data['invoice_id']) ? $data['invoice_id'] : null;
$orderId = isset($data['order_id']) ? $data['order_id'] : null;
$status = isset($data['status']) ? $data['status'] : null;
$amount = isset($data['amount']) ? $data['amount'] : null;
$credited = isset($data['credited']) ? $data['credited'] : null;
$payTime = isset($data['pay_time']) ? $data['pay_time'] : null;

// Validate required fields
if (empty($orderId) || empty($status)) {
    logTransaction($gatewayParams['name'], [
        'error' => 'Missing required fields',
        'data' => $data,
    ], 'Failure');
    http_response_code(400);
    die('Missing required fields');
}

// Verify signature
$webhookKey = $gatewayParams['webhookKey'];

if (empty($webhookKey)) {
    logTransaction($gatewayParams['name'], 'Webhook key is not configured', 'Failure');
    http_response_code(500);
    die('Webhook key not configured');
}

// Get signature from Authorization or Signature header
$headers = getallheaders();
$receivedSignature = null;

foreach ($headers as $key => $value) {
    if (strtolower($key) === 'authorization' || strtolower($key) === 'signature') {
        $receivedSignature = $value;
        break;
    }
}

if (empty($receivedSignature)) {
    logTransaction($gatewayParams['name'], [
        'error' => 'Missing signature header',
        'headers' => $headers,
    ], 'Failure');
    http_response_code(403);
    die('Missing signature');
}

// Generate signature for verification (based on SDK)
$verifyData = $data;
ksort($verifyData);
$calculatedSignature = hash_hmac('sha256', json_encode($verifyData), $webhookKey);

if (!hash_equals($calculatedSignature, $receivedSignature)) {
    logTransaction($gatewayParams['name'], [
        'error' => 'Signature verification failed',
        'received' => $receivedSignature,
        'calculated' => $calculatedSignature,
        'data' => $data,
    ], 'Failure');

    http_response_code(403);
    die('Invalid signature');
}

// Extract invoice ID from order_id (format: invoiceId_timestamp)
$orderIdParts = explode('_', $orderId);
$invoiceId = (int)$orderIdParts[0];

// Validate invoice exists
try {
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
} catch (Exception $e) {
    logTransaction($gatewayParams['name'], [
        'error' => 'Invoice not found',
        'order_id' => $orderId,
    ], 'Failure');
    http_response_code(404);
    die('Invoice not found');
}

// Check if payment is successful
$successStatuses = ['success', 'paid', 'completed'];
$isSuccess = in_array(strtolower($status), $successStatuses);

if (!$isSuccess) {
    logTransaction($gatewayParams['name'], [
        'status' => $status,
        'invoice_id' => $invoiceId,
        'data' => $data,
    ], 'Payment Status: ' . $status);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Status recorded']);
    exit;
}

// Use invoice UUID as transaction ID (unique identifier from Lava)
$transactionId = $invoiceUuid ?: 'LAVA_' . $orderId . '_' . time();

// Check for duplicate transaction
try {
    checkCbTransID($transactionId);
} catch (Exception $e) {
    logTransaction($gatewayParams['name'], [
        'error' => 'Duplicate transaction',
        'transaction_id' => $transactionId,
    ], 'Duplicate');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Already processed']);
    exit;
}

// Get invoice from database to check status
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

if ($invoice && $invoice->status === 'Paid') {
    logTransaction($gatewayParams['name'], [
        'message' => 'Invoice already paid',
        'invoice_id' => $invoiceId,
    ], 'Already Paid');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Already paid']);
    exit;
}

// Log successful transaction
logTransaction($gatewayParams['name'], [
    'invoice_id' => $invoiceId,
    'transaction_id' => $transactionId,
    'amount' => $amount,
    'status' => $status,
    'data' => $data,
], 'Success');

// Add payment to invoice
// Using 0 for amount to let WHMCS use the invoice amount
// Fee is typically not provided by Lava webhook, so set to 0
addInvoicePayment(
    $invoiceId,
    $transactionId,
    0, // Amount (0 means use invoice amount)
    0, // Fee
    $gatewayModuleName
);

// Return success response
http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'Payment processed']);
