<?php

namespace OctoSqueeze\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OctoSqueeze
{
    protected string $apiKey;
    protected string $endpointUri = 'https://app.octosqueeze.com/api/v1';
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
            $this->httpClient = new Client(array_merge([
                'timeout' => 30,
            ], $this->httpClientConfig));
        }

        return $this->httpClient;
    }

    /**
     * Build full URL from endpoint + path.
     */
    protected function url(string $path): string
    {
        return $this->endpointUri . '/' . ltrim($path, '/');
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
                'error' => $e->getMessage(),
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
                $multipart[] = ['name' => 'format', 'contents' => $merged['formats'][0]];
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
                'error' => $e->getMessage(),
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
                'error' => $e->getMessage(),
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
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ];
        }
    }

    /**
     * Download compressed image from OctoSqueeze
     */
    public function download(string $downloadUrl): ?string
    {
        try {
            $response = $this->getHttpClient()->request('GET', $downloadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);

            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return null;
        }
    }
}
