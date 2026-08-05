<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Http;

use Avsbhq\Avsb\Version;

/**
 * Adapts a PSR-18 HTTP client the host application already has.
 *
 * Guzzle, Symfony's HttpClient, and every other PSR-18 client work here, which
 * means your existing middleware, proxy configuration, and instrumentation
 * apply to A vs B requests too.
 *
 * The client and factories are typed as `object` and used through duck typing
 * on purpose: psr/http-client and psr/http-factory are NOT dependencies of
 * this package, and requiring two packages to read a JSON file would be a poor
 * trade for a package whose selling point is zero-configuration. Nothing here
 * loads unless you construct it.
 *
 * Usage:
 *
 *   $options = new AvsbServerOptions(
 *       transport: new Psr18Transport($httpClient, $requestFactory, $streamFactory)
 *   );
 *
 * A client that cannot satisfy the contract fails loudly at construction
 * rather than at the first flag evaluation.
 */
final class Psr18Transport implements HttpTransport
{
    /**
     * @param object $client         a PSR-18 ClientInterface
     * @param object $requestFactory a PSR-17 RequestFactoryInterface
     * @param object|null $streamFactory a PSR-17 StreamFactoryInterface,
     *        needed only for POST bodies
     */
    public function __construct(
        private readonly object $client,
        private readonly object $requestFactory,
        private readonly ?object $streamFactory = null,
    ) {
        if (!method_exists($client, 'sendRequest')) {
            throw new \InvalidArgumentException(
                'Psr18Transport needs a PSR-18 client (an object with sendRequest). '
                . 'Got ' . $client::class . '. Pass your Guzzle or Symfony PSR-18 client, '
                . 'or use CurlTransport, which needs nothing.'
            );
        }
        if (!method_exists($requestFactory, 'createRequest')) {
            throw new \InvalidArgumentException(
                'Psr18Transport needs a PSR-17 request factory (an object with '
                . 'createRequest). Got ' . $requestFactory::class . '.'
            );
        }
    }

    public function get(string $url, float $timeoutSeconds, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, null, $headers);
    }

    public function post(
        string $url,
        string $body,
        float $timeoutSeconds,
        array $headers = []
    ): HttpResponse {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        return $this->send('POST', $url, $body, $headers);
    }

    /**
     * Timeouts are the PSR-18 client's own configuration: the interface has no
     * per-request timeout, so the SDK cannot set one here. Configure it on the
     * client you pass in.
     *
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        try {
            $request = $this->requestFactory->createRequest($method, $url);
            $headers['User-Agent'] = $headers['User-Agent'] ?? Version::userAgent();
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            if ($body !== null) {
                if ($this->streamFactory === null
                    || !method_exists($this->streamFactory, 'createStream')
                ) {
                    return new HttpResponse(
                        0,
                        '',
                        'Psr18Transport was constructed without a PSR-17 stream factory, '
                        . 'so it cannot send a request body. Pass one, or use CurlTransport.'
                    );
                }
                $request = $request->withBody($this->streamFactory->createStream($body));
            }

            $response = $this->client->sendRequest($request);

            $retryAfter = null;
            if (method_exists($response, 'hasHeader') && $response->hasHeader('Retry-After')) {
                $retryAfter = $response->getHeaderLine('Retry-After');
            }

            return new HttpResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
                null,
                $retryAfter
            );
        } catch (\Throwable $e) {
            return new HttpResponse(0, '', $e::class . ': ' . $e->getMessage());
        }
    }
}
