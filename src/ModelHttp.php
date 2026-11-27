<?php

declare(strict_types=1);

namespace AgentAutomation;

final class ModelHttp
{
    public static function request(string $url, array $payload, string $token = '', bool $loopbackOnly = false): array
    {
        $target = parse_url($url);
        $scheme = $target['scheme'] ?? '';
        $host = $target['host'] ?? '';
        $local = in_array($host, ['127.0.0.1', '[::1]'], true);
        if (!$target || $host === '' || isset($target['user']) || isset($target['pass']) || isset($target['query']) ||
            isset($target['fragment']) || !str_ends_with($target['path'] ?? '', '/v1/chat/completions') ||
            ($scheme !== 'https' && !($scheme === 'http' && $local)) || ($loopbackOnly && !$local)) {
            return ['state' => 'failed', 'failure' => 'provider-unavailable'];
        }
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $collected = '';
        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => Values::json($payload),
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => 2500,
            CURLOPT_TIMEOUT_MS => 20000,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$collected): int {
                if (strlen($collected) + strlen($chunk) > 65536) {
                    return 0;
                }
                $collected .= $chunk;
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
        curl_close($request);
        if ($success === false) {
            return ['state' => 'unknown', 'failure' => 'provider-response-lost'];
        }
        if ($status !== 200) {
            return ['state' => 'failed', 'failure' => 'provider-http-' . $status];
        }
        return ['state' => 'complete', 'body' => $collected];
    }
}
