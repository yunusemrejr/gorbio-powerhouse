<?php
header('Content-Type: application/json');

// Include dependencies
require_once 'rate_limiter.php';
require_once 'gather_resources.php';

// Rate limit configuration
define('REQUESTS_PER_MINUTE', 100);
define('REQUESTS_PER_DAY', 10000);

// Get client IP
$client_ip = $_SERVER['REMOTE_ADDR'];

// Logging function for debugging
function log_error($message) {
    file_put_contents('api_error.log', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

// Check rate limits
$rate_limiter = new RateLimiter($client_ip);
$minute_allowed = $rate_limiter->isAllowed(REQUESTS_PER_MINUTE, 60); // 1 minute
$day_allowed = $rate_limiter->isAllowed(REQUESTS_PER_DAY, 86400);    // 1 day

if (!$minute_allowed) {
    http_response_code(429); // Too Many Requests
    $retry_after = 60 - (time() % 60);
    header("Retry-After: $retry_after");
    header('X-Rate-Limit-Limit: ' . REQUESTS_PER_MINUTE);
    header('X-Rate-Limit-Remaining: 0');
    echo json_encode([
        'error' => 'Rate limit exceeded',
        'details' => 'Exceeded ' . REQUESTS_PER_MINUTE . ' requests per minute',
        'retry_after' => $retry_after . ' seconds'
    ]);
    exit;
}
if (!$day_allowed) {
    http_response_code(429);
    $retry_after = 86400 - (time() % 86400);
    header("Retry-After: $retry_after");
    header('X-Rate-Limit-Limit: ' . REQUESTS_PER_DAY);
    header('X-Rate-Limit-Remaining: 0');
    echo json_encode([
        'error' => 'Daily rate limit exceeded',
        'details' => 'Exceeded ' . REQUESTS_PER_DAY . ' requests per day',
        'retry_after' => $retry_after . ' seconds'
    ]);
    exit;
}

// Get blockchain name from query parameter
$blockchain_name = isset($_GET['blockchain_name']) ? strtolower(trim($_GET['blockchain_name'])) : null;

if (!$blockchain_name) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Missing blockchain_name parameter']);
    exit;
}

// Fetch data
try {
    $data_gatherer = new DataGatherer();
    $data = $data_gatherer->getPowerUsage($blockchain_name);

    if ($data === null) {
        http_response_code(503); // Service Unavailable instead of 404
        log_error("Failed to fetch data for blockchain: $blockchain_name");
        echo json_encode([
            'error' => "Data unavailable for '$blockchain_name'",
            'details' => 'External data sources may be temporarily unavailable. Please try again later.'
        ]);
        exit;
    }

    // Increment request count only on successful data fetch
    $rate_limiter->increment();

    // Set rate limit headers
    $minute_stats = $rate_limiter->getStats(REQUESTS_PER_MINUTE, 60);
    $day_stats = $rate_limiter->getStats(REQUESTS_PER_DAY, 86400);
    header('X-Rate-Limit-Limit: ' . REQUESTS_PER_MINUTE);
    header('X-Rate-Limit-Remaining: ' . $minute_stats['remaining']);
    header('X-Rate-Limit-Reset: ' . $minute_stats['reset']);

    // Return successful response
    http_response_code(200);
    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    log_error("Unexpected error for $blockchain_name: " . $e->getMessage());
    echo json_encode([
        'error' => 'Internal server error',
        'details' => 'An unexpected error occurred. Please try again later.'
    ]);
}
?>
