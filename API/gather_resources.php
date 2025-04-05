<?php
class DataGatherer {
    // API endpoints
    private $bitcoin_hashrate_api = 'https://api.blockchair.com/bitcoin/stats'; // Primary for Bitcoin
    private $bitcoin_hashrate_fallback = 'https://whattomine.com/coins/1.json'; // Bitcoin fallback
    private $ethereum_nodes_api = 'https://ethernodes.org/api/stats'; // Ethereum nodes
    private $whattomine_base_url = 'https://whattomine.com/coins/'; // Base URL for WhatToMine coins

    // Supported blockchains and their WhatToMine coin IDs
    private $supported_blockchains = [
        'bitcoin' => 1,
        'ethereum' => null, // Ethereum uses ethernodes.org, not WhatToMine
        'eticacoin' => 382, // EGAZ
        'ethereumclassic' => 162, // ETC
        'ravencoin' => 234, // RVN
        'ergo' => 340, // ERG
        'conflux' => 337, // CFX
    ];

    // Algorithm-specific energy efficiency (joules per gigahash)
    private $energy_per_gh = [
        'sha256' => 0.045, // Bitcoin
        'etchash' => 0.050, // ETC, EGAZ
        'kawpow' => 0.060, // RVN
        'autolykos' => 0.055, // ERG
        'octopus' => 0.058, // CFX
    ];

    public function getPowerUsage($blockchain_name) {
        $blockchain_name = strtolower($blockchain_name);
        if (!array_key_exists($blockchain_name, $this->supported_blockchains)) {
            file_put_contents('debug.log', "Unsupported blockchain: $blockchain_name\n", FILE_APPEND);
            return null;
        }

        if ($blockchain_name === 'bitcoin') {
            return $this->getBitcoinPowerUsage();
        } elseif ($blockchain_name === 'ethereum') {
            return $this->getEthereumPowerUsage();
        } else {
            return $this->getWhatToMinePowerUsage($blockchain_name);
        }

        return null;
    }

    private function getBitcoinPowerUsage() {
        $hashrate_data = $this->fetchBitcoinHashrate();
        if ($hashrate_data === null) {
            file_put_contents('debug.log', "Bitcoin hashrate fetch failed\n", FILE_APPEND);
            return null;
        }

        $energy_data = $this->estimateEnergyUsage($hashrate_data, 'sha256');

        return [
            'currentWattage' => $energy_data['currentWattage'],
            'timestamp' => gmdate('c'),
            'trend' => $this->determineTrend($hashrate_data)
        ];
    }

    private function getEthereumPowerUsage() {
        $node_data = $this->fetchEthereumNodeStats();
        if ($node_data === null) {
            file_put_contents('debug.log', "Ethereum node stats fetch failed\n", FILE_APPEND);
            return null;
        }

        $energy_data = $this->estimateEthereumEnergyUsage($node_data);

        return [
            'currentWattage' => $energy_data['currentWattage'],
            'timestamp' => gmdate('c'),
            'trend' => 'stable'
        ];
    }

    private function getWhatToMinePowerUsage($blockchain_name) {
        $coin_id = $this->supported_blockchains[$blockchain_name];
        $hashrate_data = $this->fetchWhatToMineHashrate($coin_id);
        if ($hashrate_data === null) {
            file_put_contents('debug.log', "$blockchain_name hashrate fetch failed\n", FILE_APPEND);
            return null;
        }

        $algorithm = $this->getAlgorithmForBlockchain($blockchain_name);
        $energy_data = $this->estimateEnergyUsage($hashrate_data, $algorithm);

        return [
            'currentWattage' => $energy_data['currentWattage'],
            'timestamp' => gmdate('c'),
            'trend' => $this->determineTrend($hashrate_data)
        ];
    }

    private function fetchBitcoinHashrate() {
        $url = $this->bitcoin_hashrate_api;
        $response = $this->makeApiCall($url);

        if ($response !== false) {
            $data = json_decode($response, true);
            if ($data && isset($data['data']['hashrate_24h'])) {
                return [
                    'hashrate' => (float)$data['data']['hashrate_24h'],
                    'previous_hashrate' => (float)$data['data']['hashrate_24h'] * 0.98
                ];
            }
        }

        file_put_contents('debug.log', "Blockchair Bitcoin API failed, trying WhatToMine\n", FILE_APPEND);
        return $this->fetchWhatToMineHashrate(1); // Bitcoin ID = 1
    }

    private function fetchWhatToMineHashrate($coin_id) {
        $url = $this->whattomine_base_url . $coin_id . '.json';
        $response = $this->makeApiCall($url);

        if ($response === false) {
            file_put_contents('debug.log', "WhatToMine API call failed: $url\n", FILE_APPEND);
            return null;
        }

        $data = json_decode($response, true);
        if ($data && isset($data['nethash'])) {
            return [
                'hashrate' => (float)$data['nethash'],
                'previous_hashrate' => (float)$data['nethash'] * 0.98
            ];
        }

        file_put_contents('debug.log', "WhatToMine API invalid response for coin $coin_id: " . print_r($data, true) . "\n", FILE_APPEND);
        return null;
    }

    private function fetchEthereumNodeStats() {
        $url = $this->ethereum_nodes_api;
        $response = $this->makeApiCall($url);

        if ($response === false) {
            file_put_contents('debug.log', "Ethereum API call failed: $url\n", FILE_APPEND);
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['total_nodes'])) {
            file_put_contents('debug.log', "Ethereum API invalid response: " . print_r($data, true) . "\n", FILE_APPEND);
            return null;
        }

        return [
            'active_nodes' => $data['total_nodes']
        ];
    }

    private function makeApiCall($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            file_put_contents('debug.log', "cURL error for $url: $error\n", FILE_APPEND);
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        return $response;
    }

    private function estimateEnergyUsage($hashrate_data, $algorithm) {
        $hashrate_ghs = $hashrate_data['hashrate'] / 1e9; // Convert H/s to GH/s
        $joules_per_gh = $this->energy_per_gh[$algorithm] ?? 0.05; // Default to 0.05 if unknown
        $wattage = $hashrate_ghs * $joules_per_gh;
        $adjusted_wattage = $wattage * 1.35; // 35% overhead

        return [
            'currentWattage' => round($adjusted_wattage)
        ];
    }

    private function estimateEthereumEnergyUsage($node_data) {
        $watts_per_node = 100;
        $active_nodes = $node_data['active_nodes'];
        $wattage = $active_nodes * $watts_per_node;
        $total_validators = 500000;
        $annual_twh = 0.01;
        $watts_total = ($annual_twh * 1e9) / 8760;
        $wattage_scaled = $watts_total * ($active_nodes / $total_validators);

        return [
            'currentWattage' => round($wattage_scaled)
        ];
    }

    private function determineTrend($hashrate_data) {
        $current = $hashrate_data['hashrate'];
        $previous = $hashrate_data['previous_hashrate'];

        if ($current > $previous * 1.05) {
            return 'increasing';
        } elseif ($current < $previous * 0.95) {
            return 'decreasing';
        }
        return 'stable';
    }

    private function getAlgorithmForBlockchain($blockchain_name) {
        switch ($blockchain_name) {
            case 'bitcoin':
                return 'sha256';
            case 'ethereumclassic':
            case 'eticacoin':
                return 'etchash';
            case 'ravencoin':
                return 'kawpow';
            case 'ergo':
                return 'autolykos';
            case 'conflux':
                return 'octopus';
            default:
                return 'unknown';
        }
    }
}
?>
