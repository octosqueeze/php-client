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
                'timeout' => 30,
                'handler' => $stack,
            ], $this->httpClientConfig));
        }

        return $this->httpClient;
    }

    protected function retryDecider(): callable
    {
        return function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $exception): bool {
            if ($retries >= 2) {
                return false;
            }

            if ($response && in_array($response->getStatusCode(), [429, 502, 503, 504])) {
                return true;
            }

            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                return true;
            }

            return false;
        };
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
     *   - hash: string - Optional hash to skip duplicate compressions
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
            $response = $this->getHttpClient()->request('GET', $downloadUrl);

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
