<?php

namespace OctoSqueeze\Client;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OctoSqueeze
{
    protected string $apiKey;
    protected string $endpointUri = 'https://api.octosqueeze.com/api/v1';
    protected array $httpClientConfig = [];
    protected array $options = [];
    protected ?Client $httpClient = null;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public static function client(string $apiKey): self
    {
        return new self($apiKey);
    }

    public function setEndpointUri(string $uri): self
    {
        $this->endpointUri = rtrim($uri, '/');
        $this->httpClient = null; // Reset client to use new URI
        return $this;
    }

    public function setHttpClientConfig(array $config): self
    {
        $this->httpClientConfig = $config;
        $this->httpClient = null; // Reset client to use new config
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    protected function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $stack = HandlerStack::create();
            $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

            $this->httpClient = new Client(array_merge([
                // The API waits up to 120 s for the compression engine; giving up
                // sooner turned a slow compression into a failure the server had
                // already done (and billed)
                'timeout' => 125,
                'connect_timeout' => 10,
                'handler' => $stack,
            ], $this->httpClientConfig));
        }

        return $this->httpClient;
    }

    /**
     * The 429 codes of a limit that does not clear in seconds: retrying one only
     * sleeps (Retry-After) and fails again.
     */
    protected const LIMITS_THAT_DO_NOT_CLEAR = ['usage_limit_exceeded', 'daily_limit_exceeded'];

    protected function retryDecider(): callable
    {
        return function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $exception): bool {
            if ($retries >= 2) {
                return false;
            }

            // A compress call is a POST, and the API bills it once it has run. When
            // it may have run (the connection timed out, or a gateway gave up
            // waiting for it) sending it again compresses and bills it again.
            $idempotent = in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true);

            if ($response) {
                $status = $response->getStatusCode();

                if ($status === 429) {
                    return ! in_array($this->errorCode($response), self::LIMITS_THAT_DO_NOT_CLEAR, true);
                }

                if (in_array($status, [502, 503], true)) {
                    return true;
                }

                if ($status === 504) {
                    return $idempotent;
                }

                return false;
            }

            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                // Could not connect (DNS, refused): the API never saw it. Timed out:
                // it may be compressing it right now.
                return $idempotent || ! $this->timedOut($exception);
            }

            return false;
        };
    }

    /**
     * The API's error code from a response body ({success:false, error:{code}}, or
     * the legacy top-level `code`), leaving the body readable for the caller.
     */
    protected function errorCode(ResponseInterface $response): ?string
    {
        $body = $response->getBody();
        $decoded = json_decode((string) $body, true);

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $code = is_array($decoded) ? ($decoded['error']['code'] ?? $decoded['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    /**
     * Whether a connection failure was a timeout (cURL error 28), as opposed to
     * never reaching the API.
     */
    protected function timedOut(\GuzzleHttp\Exception\ConnectException $exception): bool
    {
        $errno = $exception->getHandlerContext()['errno'] ?? null;

        if ($errno !== null) {
            return (int) $errno === 28;
        }

        return stripos($exception->getMessage(), 'timed out') !== false
            || stripos($exception->getMessage(), 'cURL error 28') !== false;
    }

    /**
     * Whether a failed request may be sent again without compressing (and
     * billing) the same image twice: the retry rules, applied by the caller.
     */
    public function isRetryable(GuzzleException $e, string $method = 'POST'): bool
    {
        $request = $e instanceof RequestException || $e instanceof \GuzzleHttp\Exception\ConnectException
            ? $e->getRequest()->withMethod($method)
            : new \GuzzleHttp\Psr7\Request($method, $this->endpointUri);
        $response = $e instanceof RequestException ? $e->getResponse() : null;

        return ($this->retryDecider())(0, $request, $response, $e);
    }

    protected function retryDelay(): callable
    {
        return function (int $retries, ?ResponseInterface $response): int {
            if ($response && $response->getStatusCode() === 429) {
                $retryAfter = $response->getHeaderLine('Retry-After');
                if ($retryAfter && is_numeric($retryAfter)) {
                    return (int) ($retryAfter * 1000);
                }
            }

            // Exponential backoff: 1s, 2s
            return (int) pow(2, $retries) * 1000;
        };
    }

    /**
     * Build full URL from endpoint + path.
     */
    protected function url(string $path): string
    {
        return $this->endpointUri . '/' . ltrim($path, '/');
    }

    /**
     * Sanitize exception message to avoid leaking sensitive data (API keys in Guzzle headers).
     */
    protected function sanitizeExceptionMessage(GuzzleException $e): string
    {
        if ($e instanceof RequestException && $e->getResponse()) {
            $status = $e->getResponse()->getStatusCode();
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);
            $serverMessage = $decoded['error']['message'] ?? $decoded['message'] ?? null;

            if ($serverMessage) {
                return $serverMessage;
            }

            return "HTTP {$status} error from API";
        }

        // Generic messages without internals
        return 'Request to OctoSqueeze API failed: ' . $e->getCode();
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Compress a single image from URL
     */
    public function compressUrl(string $url, array $options = []): array
    {
        return $this->squeezeUrl([
            [
                'url' => $url,
                'options' => array_merge($this->options, $options),
            ]
        ]);
    }

    /**
     * Compress multiple images from URLs
     *
     * @param array $items Array of items, each containing:
     *   - url: string - The image URL
     *   - image_id: mixed - Optional identifier for your system
     *   - hash: string - Optional; passed through, the API does not deduplicate on it
     *   - name: string - Optional filename
     *   - options: array - Optional per-image options
     */
    public function squeezeUrl(array $items): array
    {
        try {
            $response = $this->getHttpClient()->request('POST', $this->url('compress-batch'), [
                'headers' => $this->getHeaders(),
                'json' => [
                    'items' => $items,
                    'options' => $this->options,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'state' => true,
                'items' => $body['data']['items'] ?? [],
                'usage' => $body['data']['usage'] ?? null,
            ];
        } catch (GuzzleException $e) {
            return [
                'state' => false,
                'error' => $this->sanitizeExceptionMessage($e),
                'code' => $e->getCode(),
                // Safe to send again? False when the API may already have
                // compressed (and billed) it: a timeout, a gateway timeout, or a
                // limit that will not clear. Queued jobs retry only when true.
                'retryable' => $this->isRetryable($e, 'POST'),
            ];
        }
    }

    /**
     * Compress a file from local path
     */
    public function compressFile(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath)) {
            return [
                'state' => false,
                'error' => 'File not found: ' . $filePath,
            ];
        }

        try {
            $merged = array_merge($this->options, $options);

            $multipart = [
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ],
            ];

            if (!empty($merged['mode'])) {
                $multipart[] = ['name' => 'mode', 'contents' => $merged['mode']];
            }

            if (!empty($merged['formats'])) {
                $formats = (array) $merged['formats'];
                $multipart[] = ['name' => 'format', 'contents' => $formats[0]];
            }

            $response = $this->getHttpClient()->request('POST', $this->url('compress'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'multipart' => $multipart,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'state' => true,
                'data' => $body['data'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'state' => false,
                'error' => $this->sanitizeExceptionMessage($e),
                'code' => $e->getCode(),
                // Safe to send again? False when the API may already have
                // compressed (and billed) it: a timeout, a gateway timeout, or a
                // limit that will not clear. Queued jobs retry only when true.
                'retryable' => $this->isRetryable($e, 'POST'),
            ];
        }
    }

    /**
     * Get compression status by job ID
     */
    public function getStatus(string $jobId): array
    {
        try {
            $response = $this->getHttpClient()->request('GET', $this->url('status/' . $jobId), [
                'headers' => $this->getHeaders(),
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'state' => true,
                'data' => $body['data'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'state' => false,
                'error' => $this->sanitizeExceptionMessage($e),
                'code' => $e->getCode(),
            ];
        }
    }

    /**
     * Get usage statistics
     */
    public function getUsage(): array
    {
        try {
            $response = $this->getHttpClient()->request('GET', $this->url('usage'), [
                'headers' => $this->getHeaders(),
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'state' => true,
                'data' => $body['data'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'state' => false,
                'error' => $this->sanitizeExceptionMessage($e),
                'code' => $e->getCode(),
            ];
        }
    }

    /**
     * Download compressed image from OctoSqueeze
     *
     * @return array{state: bool, data?: string, error?: string, code?: int}
     */
    public function download(string $downloadUrl): array
    {
        try {
            $response = $this->getHttpClient()->request('GET', $downloadUrl, [
                'headers' => $this->downloadHeaders($downloadUrl),
            ]);

            // A page or an error body is never the compressed file. Callers write
            // whatever comes back over the original, so an HTML login page or a JSON
            // error returned with a 200 must fail here instead of destroying an image.
            $contentType = strtolower($response->getHeaderLine('Content-Type'));

            if (str_starts_with($contentType, 'text/html') || str_starts_with($contentType, 'application/json')) {
                return [
                    'state' => false,
                    'error' => 'Download did not return a file (Content-Type: ' . $contentType . ')',
                    'code' => $response->getStatusCode(),
                ];
            }

            return [
                'state' => true,
                'data' => $response->getBody()->getContents(),
            ];
        } catch (GuzzleException $e) {
            return [
                'state' => false,
                'error' => $this->sanitizeExceptionMessage($e),
                'code' => $e->getCode(),
            ];
        }
    }

    /**
     * Headers for a download: the bearer goes only to the API's own host (a signed
     * download URL needs none, and a CDN or third-party URL must never see the key).
     */
    protected function downloadHeaders(string $downloadUrl): array
    {
        // Auth failures come back as a JSON 401, not a redirect to the web login page
        $headers = ['Accept' => 'application/json'];

        $host = parse_url($downloadUrl, PHP_URL_HOST);

        if ($host && strcasecmp($host, (string) parse_url($this->endpointUri, PHP_URL_HOST)) === 0) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $headers;
    }

    /**
     * Download compressed image from OctoSqueeze (legacy convenience method)
     *
     * Returns the raw content string or null on failure.
     * Prefer download() for error context.
     */
    public function downloadRaw(string $downloadUrl): ?string
    {
        $result = $this->download($downloadUrl);

        return $result['state'] ? $result['data'] : null;
    }
}
