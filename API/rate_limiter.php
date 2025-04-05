<?php
class RateLimiter {
    private $ip; // Still useful for fallback or logging, but not for storage
    private $secret = 'your-secret-key-here'; // Secret for HMAC signature

    public function __construct($ip) {
        $this->ip = $ip;
    }

    private function getClientTimestamps() {
        // Read timestamps from cookie
        if (!isset($_COOKIE['rate_limit_timestamps']) || !isset($_COOKIE['rate_limit_signature'])) {
            return [];
        }

        $timestamps_json = $_COOKIE['rate_limit_timestamps'];
        $signature = $_COOKIE['rate_limit_signature'];

        // Verify signature to ensure data wasn't tampered with
        $expected_signature = hash_hmac('sha256', $timestamps_json, $this->secret);
        if ($signature !== $expected_signature) {
            file_put_contents('debug.log', "Invalid rate limit signature for IP: $this->ip\n", FILE_APPEND);
            return []; // Treat as invalid if signature doesn't match
        }

        $timestamps = json_decode($timestamps_json, true);
        return is_array($timestamps) ? $timestamps : [];
    }

    private function setClientTimestamps($timestamps) {
        // Clean up old requests (older than 24 hours)
        $timestamps = array_filter($timestamps, function($timestamp) {
            return $timestamp > (time() - 86400);
        });

        // Convert to JSON and sign
        $timestamps_json = json_encode(array_values($timestamps));
        $signature = hash_hmac('sha256', $timestamps_json, $this->secret);

        // Set cookies in response
        setcookie('rate_limit_timestamps', $timestamps_json, [
            'expires' => time() + 86400, // 24 hours
            'path' => '/',
            'httponly' => true, // Prevent JavaScript access
            'samesite' => 'Strict' // Mitigate CSRF
        ]);
        setcookie('rate_limit_signature', $signature, [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    public function increment() {
        $timestamps = $this->getClientTimestamps();
        $now = time();
        $timestamps[] = $now;
        $this->setClientTimestamps($timestamps);
    }

    public function isAllowed($limit, $period) {
        $timestamps = $this->getClientTimestamps();
        $requests = array_filter($timestamps, function($timestamp) use ($period) {
            return $timestamp > (time() - $period);
        });

        return count($requests) < $limit;
    }

    public function getStats($limit, $period) {
        $timestamps = $this->getClientTimestamps();
        $requests = array_filter($timestamps, function($timestamp) use ($period) {
            return $timestamp > (time() - $period);
        });

        $count = count($requests);
        $remaining = max(0, $limit - $count);
        $reset = ceil(time() / $period) * $period; // Next period boundary

        return [
            'count' => $count,
            'remaining' => $remaining,
            'reset' => $reset
        ];
    }
}
?>
