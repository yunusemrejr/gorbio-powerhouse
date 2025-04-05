
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

// Check rate limits
$rate_limiter = new RateLimiter($client_ip);
if (!$rate_limiter->isAllowed(REQUESTS_PER_MINUTE, 60)) { // 60 seconds = 1 minute
    http_response_code(429); // Too Many Requests
    $retry_after = 60 - (time() % 60); // Seconds until next minute
    header("Retry-After: $retry_after");
    header('X-Rate-Limit-Limit: ' . REQUESTS_PER_MINUTE);
    header('X-Rate-Limit-Remaining: 0');
    echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    exit;
}
if (!$rate_limiter->isAllowed(REQUESTS_PER_DAY, 86400)) { // 86400 seconds = 1 day
    http_response_code(429);
    $retry_after = 86400 - (time() % 86400); // Seconds until next day
    header("Retry-After: $retry_after");
    header('X-Rate-Limit-Limit: ' . REQUESTS_PER_DAY);
    header('X-Rate-Limit-Remaining: 0');
    echo json_encode(['error' => 'Daily rate limit exceeded.']);
    exit;
}

// Get blockchain name from query parameter
$blockchain_name = isset($_GET['blockchain_name']) ? strtolower($_GET['blockchain_name']) : null;

if (!$blockchain_name) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Missing blockchain_name parameter']);
    exit;
}

// Fetch data using gather_resources.php
$data_gatherer = new DataGatherer();
$data = $data_gatherer->getPowerUsage($blockchain_name);

// Check if data was retrieved successfully
if ($data === null) {
    http_response_code(404); // Not Found
    echo json_encode(['error' => "Blockchain '$blockchain_name' not supported or data unavailable"]);
    exit;
}

// Increment request count
$rate_limiter->increment();

// Set rate limit headers
$minute_stats = $rate_limiter->getStats(REQUESTS_PER_MINUTE, 60);
$day_stats = $rate_limiter->getStats(REQUESTS_PER_DAY, 86400);
header('X-Rate-Limit-Limit: ' . REQUESTS_PER_MINUTE);
header('X-Rate-Limit-Remaining: ' . $minute_stats['remaining']);
header('X-Rate-Limit-Reset: ' . $minute_stats['reset']);

// Return response
http_response_code(200);
echo json_encode($data);
?>
