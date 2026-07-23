<?php

// nginx-log-test.php — returns a configurable HTTP status code for
// end-to-end Nginx access-log testing.
//
// Usage: GET /nginx-log-test.php?status=200|301|404|500&token=any
//
// The response body includes the token so the log line is uniquely
// identifiable in Loki queries.

require __DIR__ . '/FixtureHelpers.php';

$token = FixtureHelpers::requireAuth();

$status = (int) ($_GET['status'] ?? 200);
$token = $_GET['token'] ?? 'no-token';

$validStatuses = [200, 301, 302, 400, 403, 404, 500, 502, 503];

if (!in_array($status, $validStatuses, true)) {
    $status = 200;
}

if ($status >= 300 && $status < 400) {
    header('Location: /nginx-log-test.php?status=200&token=' . urlencode($token . '-redirected'));
}

http_response_code($status);
header('Content-Type: text/plain');
printf("nginx-log-test status=%d token=%s", $status, $token);
