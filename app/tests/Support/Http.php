<?php

declare(strict_types=1);

namespace LeonV\Pnpvalg\Tests\Support;

/**
 * Minimal HTTP client for end-to-end test requests.
 *
 * Wraps cURL with a single static entry point so that every test
 * file uses the same transport.
 */
final class Http
{
    /**
     * Make an HTTP request.
     *
     * The query array always includes `token` from LOCAL_AUTH_KEY.
     *
     * @param string  $method  HTTP method (GET, POST, etc.).
     * @param string  $url     Base URL (query string is appended automatically).
     * @param mixed   $data    Request body passed directly to CURLOPT_POSTFIELDS.
     *                         Use a string, array, or CURLFile object as needed.
     * @param object  $headers Header name/value pairs to send with the request.
     * @param int     $timeout Seconds before the request times out.
     * @param array   $query   Query parameters (token is always included).
     *
     * @return object{body: string, status: int, headers: object}|null
     */
    public static function request(
        string $method,
        string $url,
        mixed $data = null,
        object $headers = new \stdClass,
        int $timeout = 5,
        array $query = [],
    ): ?object {
        $query['token'] = (string) getenv('LOCAL_AUTH_KEY');
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . http_build_query($query);

        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        if ($headerLines !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        $raw = curl_exec($ch);

        if (!is_string($raw)) {
            curl_close($ch);

            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $body = substr($raw, $headerSize);
        $headerBlock = substr($raw, 0, $headerSize);

        $responseHeaders = new \stdClass;
        $lines = explode("\r\n", $headerBlock);

        foreach ($lines as $line) {
            $pos = strpos($line, ':');

            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $responseHeaders->{$name} = trim(substr($line, $pos + 1));
            }
        }

        return (object) [
            'body'    => $body,
            'status'  => $status,
            'headers' => $responseHeaders,
        ];
    }
}
