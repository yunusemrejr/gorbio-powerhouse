
<?php
class RateLimiter {
    private $ip;
    private $data_file = 'rate_limit_data.json';

    public function __construct($ip) {
        $this->ip = $ip;
        $this->ensureDataFileExists();
    }

    private function ensureDataFileExists() {
        if (!file_exists($this->data_file)) {
            file_put_contents($this->data_file, json_encode([]));
        }
    }

    private function readData() {
        $data = json_decode(file_get_contents($this->data_file), true);
        return $data ?: [];
    }

    private function writeData($data) {
        file_put_contents($this->data_file, json_encode($data), LOCK_EX);
    }

    public function increment() {
        $data = $this->readData();
        $now = time();

        if (!isset($data[$this->ip])) {
            $data[$this->ip] = [];
        }

        $data[$this->ip][] = $now;
        // Clean up old requests (older than 24 hours)
        $data[$this->ip] = array_filter($data[$this->ip], function($timestamp) {
            return $timestamp > (time() - 86400);
        });
        
        $this->writeData($data);
    }

    public function isAllowed($limit, $period) {
        $data = $this->readData();
        if (!isset($data[$this->ip])) {
            return true;
        }

        $requests = array_filter($data[$this->ip], function($timestamp) use ($period) {
            return $timestamp > (time() - $period);
        });

        return count($requests) < $limit;
    }

    public function getStats($limit, $period) {
        $data = $this->readData();
        $requests = isset($data[$this->ip]) ? array_filter($data[$this->ip], function($timestamp) use ($period) {
            return $timestamp > (time() - $period);
        }) : [];
        
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
