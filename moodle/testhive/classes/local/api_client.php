<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_testhive\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal HTTP client for Testhive Core API.
 */
class api_client {
    /**
     * @return array{base: string, key: string}
     */
    public static function get_config(): array {
        $base = (string) get_config('mod_testhive', 'api_base_url');
        $key = (string) get_config('mod_testhive', 'api_key');
        return [
            'base' => rtrim($base, '/'),
            'key' => $key,
        ];
    }

    public static function is_configured(): bool {
        $config = self::get_config();
        return $config['base'] !== '' && $config['key'] !== '';
    }

    /**
     * @param string $method GET|POST
     * @param string $path e.g. /v1/tests
     * @param array|null $body
     * @return array decoded JSON
     */
    public static function request(string $method, string $path, ?array $body = null): array {
        $config = self::get_config();
        if (!self::is_configured()) {
            throw new \moodle_exception('error_apinotconfigured', 'mod_testhive');
        }

        $url = $config['base'] . $path;
        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $config['key']]);
        $curl->setHeader(['Content-Type: application/json']);
        $curl->setHeader(['Accept: application/json']);

        $options = ['CURLOPT_TIMEOUT' => 60];
        $payload = $body === null ? null : json_encode($body);

        if (strtoupper($method) === 'GET') {
            $raw = $curl->get($url, [], $options);
        } else {
            $raw = $curl->post($url, $payload, $options);
        }

        if ($curl->get_errno()) {
            throw new \moodle_exception('error_apirequest', 'mod_testhive', '', $curl->error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('error_apirequest', 'mod_testhive', '', 'Invalid JSON response');
        }

        if (empty($decoded['success'])) {
            $message = $decoded['message'] ?? 'Unknown API error';
            if (!empty($decoded['errors'][0])) {
                $message = (string) $decoded['errors'][0];
            }
            throw new \moodle_exception('error_apirequest', 'mod_testhive', '', $message);
        }

        return $decoded;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public static function list_questionsets(): array {
        $response = self::request('GET', '/v1/questionsets');
        $items = $response['data'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!empty($item['id'])) {
                $out[] = [
                    'id' => (string) $item['id'],
                    'name' => (string) ($item['name'] ?? $item['id']),
                ];
            }
        }
        return $out;
    }
}
