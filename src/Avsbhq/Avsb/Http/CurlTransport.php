<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Http;

use Avsbhq\Avsb\Version;

/**
 * The zero-dependency transport: ext-curl when it is loaded, streams when it
 * is not.
 *
 * Every request carries a timeout. A datafile read with no timeout turns a
 * slow CDN into a stalled PHP-FPM worker, and PHP's default socket timeout is
 * measured in minutes.
 */
final class CurlTransport implements HttpTransport
{
    public function get(string $url, float $timeoutSeconds, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, null, $timeoutSeconds, $headers);
    }

    public function post(
        string $url,
        string $body,
        float $timeoutSeconds,
        array $headers = []
    ): HttpResponse {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        return $this->send('POST', $url, $body, $timeoutSeconds, $headers);
    }

    /** @param array<string, string> $headers */
    private function send(
        string $method,
        string $url,
        ?string $body,
        float $timeoutSeconds,
        array $headers
    ): HttpResponse {
        $headers['User-Agent'] = $headers['User-Agent'] ?? Version::userAgent();

        return extension_loaded('curl')
            ? $this->sendWithCurl($method, $url, $body, $timeoutSeconds, $headers)
            : $this->sendWithStreams($method, $url, $body, $timeoutSeconds, $headers);
    }

    /** @param array<string, string> $headers */
    private function sendWithCurl(
        string $method,
        string $url,
        ?string $body,
        float $timeoutSeconds,
        array $headers
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            return new HttpResponse(0, '', 'curl_init failed for ' . $url);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            return new HttpResponse(0, '', 'curl: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        return new HttpResponse(
            $status,
            $responseBody === false ? '' : $responseBody,
            null,
            $this->retryAfterFrom(preg_split('/\r?\n/', $rawHeaders) ?: [])
        );
    }

    /** @param array<string, string> $headers */
    private function sendWithStreams(
        string $method,
        string $url,
        ?string $body,
        float $timeoutSeconds,
        array $headers
    ): HttpResponse {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headerLines,
                'content' => $body ?? '',
                'timeout' => $timeoutSeconds,
                // Read the body of a 404 rather than throwing it away: the
                // status is what tells the caller what went wrong.
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $last = error_get_last();

            return new HttpResponse(
                0,
                '',
                'stream: ' . ($last['message'] ?? 'request failed') . ' (' . $url . ')'
            );
        }

        /** @var list<string> $http_response_header populated by the stream wrapper */
        $responseHeaders = $http_response_header ?? [];

        return new HttpResponse(
            $this->statusFrom($responseHeaders),
            $responseBody,
            null,
            $this->retryAfterFrom($responseHeaders)
        );
    }

    /** @param list<string> $headerLines */
    private function statusFrom(array $headerLines): int
    {
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /** @param list<string> $headerLines */
    private function retryAfterFrom(array $headerLines): ?string
    {
        foreach ($headerLines as $line) {
            if (stripos($line, 'retry-after:') === 0) {
                return trim(substr($line, strlen('retry-after:')));
            }
        }

        return null;
    }
}
