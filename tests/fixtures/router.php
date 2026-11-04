<?php

/**
 * Router for the fake KHQRPay API used by the HTTP tests:
 *
 *     php -S 127.0.0.1:<port> tests/fixtures/router.php
 *
 * KHQRPAY_FAKE_DIR names a directory shared with the test. Every request is
 * recorded to request.json there, and the reply comes from response.json:
 * {"status": 201, "headers": {"Name": "value"}, "body": "<raw>", "delay_ms": 0}.
 */

$dir = getenv('KHQRPAY_FAKE_DIR');
if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
    http_response_code(500);
    echo 'KHQRPAY_FAKE_DIR is not set';

    return true;
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}

$record = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
];
file_put_contents($dir . '/request.json', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$response = ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];
if (is_file($dir . '/response.json')) {
    $response = json_decode((string) file_get_contents($dir . '/response.json'), true);
}

if (!empty($response['delay_ms'])) {
    usleep((int) $response['delay_ms'] * 1000);
}

http_response_code((int) $response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];

return true;
