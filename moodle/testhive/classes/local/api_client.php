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
                    'type' => (string) ($item['type'] ?? ''),
                    'count' => (int) ($item['count'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /**
     * @param string $id
     * @return array{id: string, name: string, type: string, count: int}|null
     */
    public static function find_questionset(string $id): ?array {
        foreach (self::list_questionsets() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Label for a question-set option: name, size, and kind.
     *
     * @param array{id: string, name: string, type?: string, count?: int} $item
     * @return string
     */
    public static function questionset_label(array $item): string {
        $name = (string) ($item['name'] ?? $item['id']);
        $details = [];
        if (!empty($item['count'])) {
            $details[] = get_string('questioncount', 'mod_testhive', (int) $item['count']);
        }
        $type = (string) ($item['type'] ?? '');
        $typekey = 'questionsettype_' . $type;
        if ($type !== '' && get_string_manager()->string_exists($typekey, 'mod_testhive')) {
            $details[] = get_string($typekey, 'mod_testhive');
        }
        if (count($details) === 0) {
            return $name;
        }
        return $name . ' — ' . implode(', ', $details);
    }

    /**
     * Question set types accepted by the Core wizard.
     *
     * @return string[]
     */
    public static function questionset_type_options(): array {
        return ['mcq', 'truefalse', 'descriptive', 'assignment'];
    }

    /**
     * Queue async question generation (Core wizard).
     *
     * @param array $intent numQuestions, answeringMode, optional totalMarks and difficulty
     * @param array $source kind text|url plus text or url field
     * @return array decoded API response including jobId in data
     */
    public static function wizard_generate(array $intent, array $source): array {
        return self::request('POST', '/v1/wizard/questionsets/generate', [
            'intent' => $intent,
            'source' => $source,
        ]);
    }

    /**
     * Poll wizard generation state for the configured service key.
     *
     * @return array|null data payload or null when no state
     */
    public static function wizard_get_state(): ?array {
        $response = self::request('GET', '/v1/wizard/questionsets/state');
        $data = $response['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    /**
     * Persist wizard state as a question set.
     *
     * @param string $name
     * @return array{id: string, name: string}
     */
    public static function wizard_finalize_questionset(string $name): array {
        $response = self::request('POST', '/v1/wizard/questionsets', [
            'name' => $name,
        ]);
        $data = $response['data'] ?? [];
        if (empty($data['id'])) {
            throw new \moodle_exception('error_apirequest', 'mod_testhive', '', 'Missing question set id in API response');
        }
        return [
            'id' => (string) $data['id'],
            'name' => (string) ($data['name'] ?? $name),
        ];
    }
}
