<?php

namespace OctoSqueeze\Client\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use OctoSqueeze\Client\OctoSqueeze;
use PHPUnit\Framework\TestCase;

class OctoSqueezeTest extends TestCase
{
    private const TEST_API_KEY = 'test-api-key-abc123';
    private const TEST_ENDPOINT = 'https://api.test.com/api/v1';

    /**
     * Create an OctoSqueeze instance wired to a Guzzle MockHandler.
     *
     * @param  array  $responses  Queue of Response/Exception objects
     * @param  array  &$history   Container that will collect request history
     */
    private function createMockedClient(array $responses, array &$history = []): OctoSqueeze
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return OctoSqueeze::client(self::TEST_API_KEY)
            ->setEndpointUri(self::TEST_ENDPOINT)
            ->setHttpClientConfig(['handler' => $stack]);
    }

    // ---------------------------------------------------------------
    //  Constructor & Configuration
    // ---------------------------------------------------------------

    public function test_constructor_stores_api_key(): void
    {
        $client = new OctoSqueeze('my-secret-key');

        $reflection = new \ReflectionProperty(OctoSqueeze::class, 'apiKey');
        $reflection->setAccessible(true);

        $this->assertSame('my-secret-key', $reflection->getValue($client));
    }

    public function test_static_factory_returns_instance(): void
    {
        $client = OctoSqueeze::client('key-123');

        $this->assertInstanceOf(OctoSqueeze::class, $client);
    }

    public function test_set_endpoint_uri_returns_self(): void
    {
        $client = new OctoSqueeze('key');
        $result = $client->setEndpointUri('https://example.com/api/v1');

        $this->assertSame($client, $result);
    }

    public function test_set_endpoint_uri_resets_http_client(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        // First call creates the HTTP client
        $client->getUsage();

        // Changing endpoint should reset the cached HTTP client
        $reflection = new \ReflectionProperty(OctoSqueeze::class, 'httpClient');
        $reflection->setAccessible(true);
        $this->assertNotNull($reflection->getValue($client));

        $client->setEndpointUri('https://new-api.example.com/api/v2');
        $this->assertNull($reflection->getValue($client));
    }

    public function test_set_options_merges_options(): void
    {
        $client = new OctoSqueeze('key');
        $client->setOptions(['mode' => 'lossy']);
        $client->setOptions(['formats' => ['webp']]);

        $reflection = new \ReflectionProperty(OctoSqueeze::class, 'options');
        $reflection->setAccessible(true);
        $options = $reflection->getValue($client);

        $this->assertSame('lossy', $options['mode']);
        $this->assertSame(['webp'], $options['formats']);
    }

    public function test_set_http_client_config_returns_self(): void
    {
        $client = new OctoSqueeze('key');
        $result = $client->setHttpClientConfig(['timeout' => 60]);

        $this->assertSame($client, $result);
    }

    public function test_set_http_client_config_resets_http_client(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ], $history);

        // Trigger client creation
        $client->getUsage();

        $reflection = new \ReflectionProperty(OctoSqueeze::class, 'httpClient');
        $reflection->setAccessible(true);
        $this->assertNotNull($reflection->getValue($client));

        $client->setHttpClientConfig(['timeout' => 60]);
        $this->assertNull($reflection->getValue($client));
    }

    // ---------------------------------------------------------------
    //  URL Building
    // ---------------------------------------------------------------

    public function test_url_builds_correct_full_url(): void
    {
        $client = new OctoSqueeze('key');
        $client->setEndpointUri('https://api.test.com/api/v1');

        $reflection = new \ReflectionMethod(OctoSqueeze::class, 'url');
        $reflection->setAccessible(true);

        $this->assertSame('https://api.test.com/api/v1/compress-batch', $reflection->invoke($client, 'compress-batch'));
    }

    public function test_url_handles_trailing_slash_on_endpoint(): void
    {
        $client = new OctoSqueeze('key');
        $client->setEndpointUri('https://api.test.com/api/v1/');

        $reflection = new \ReflectionMethod(OctoSqueeze::class, 'url');
        $reflection->setAccessible(true);

        // setEndpointUri trims trailing slash
        $this->assertSame('https://api.test.com/api/v1/status/abc', $reflection->invoke($client, 'status/abc'));
    }

    public function test_url_handles_leading_slash_on_path(): void
    {
        $client = new OctoSqueeze('key');
        $client->setEndpointUri('https://api.test.com/api/v1');

        $reflection = new \ReflectionMethod(OctoSqueeze::class, 'url');
        $reflection->setAccessible(true);

        // ltrim strips leading slash from path
        $this->assertSame('https://api.test.com/api/v1/usage', $reflection->invoke($client, '/usage'));
    }

    // ---------------------------------------------------------------
    //  compressUrl
    // ---------------------------------------------------------------

    public function test_compress_url_sends_post_to_compress_batch(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $client->compressUrl('https://example.com/image.jpg');

        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame(self::TEST_ENDPOINT . '/compress-batch', (string) $history[0]['request']->getUri());
    }

    public function test_compress_url_sends_authorization_bearer_header(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $client->compressUrl('https://example.com/image.jpg');

        $authHeader = $history[0]['request']->getHeaderLine('Authorization');
        $this->assertSame('Bearer ' . self::TEST_API_KEY, $authHeader);
    }

    public function test_compress_url_sends_accept_and_content_type_headers(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $client->compressUrl('https://example.com/image.jpg');

        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function test_compress_url_sends_correct_payload(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $client->compressUrl('https://example.com/photo.png', ['mode' => 'lossy']);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);

        $this->assertCount(1, $body['items']);
        $this->assertSame('https://example.com/photo.png', $body['items'][0]['url']);
        $this->assertSame('lossy', $body['items'][0]['options']['mode']);
    }

    public function test_compress_url_merges_instance_options_with_call_options(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $client->setOptions(['mode' => 'lossy']);
        $client->compressUrl('https://example.com/photo.png', ['formats' => ['webp']]);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);

        // Per-item options = merged instance + call options
        $this->assertSame('lossy', $body['items'][0]['options']['mode']);
        $this->assertSame(['webp'], $body['items'][0]['options']['formats']);

        // Top-level options = instance options only
        $this->assertSame('lossy', $body['options']['mode']);
    }

    public function test_compress_url_returns_success_response(): void
    {
        $client = $this->createMockedClient([
            new Response(200, [], json_encode([
                'data' => [
                    'items' => [
                        ['id' => 'job-1', 'status' => 'completed', 'url' => 'https://cdn.octosqueeze.com/abc.webp'],
                    ],
                    'usage' => ['credits' => 5],
                ],
            ])),
        ]);

        $result = $client->compressUrl('https://example.com/image.jpg');

        $this->assertTrue($result['state']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('job-1', $result['items'][0]['id']);
        $this->assertSame(5, $result['usage']['credits']);
    }

    public function test_compress_url_returns_failure_on_http_error(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Server error',
                new Request('POST', self::TEST_ENDPOINT . '/compress-batch'),
                new Response(500, [], json_encode(['message' => 'Internal Server Error']))
            ),
        ]);

        $result = $client->compressUrl('https://example.com/image.jpg');

        $this->assertFalse($result['state']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    // ---------------------------------------------------------------
    //  squeezeUrl
    // ---------------------------------------------------------------

    public function test_squeeze_url_sends_items_array(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $items = [
            ['url' => 'https://example.com/a.jpg', 'image_id' => 'img-1'],
            ['url' => 'https://example.com/b.png', 'image_id' => 'img-2', 'hash' => 'abc123'],
        ];

        $client->squeezeUrl($items);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);

        $this->assertCount(2, $body['items']);
        $this->assertSame('https://example.com/a.jpg', $body['items'][0]['url']);
        $this->assertSame('img-1', $body['items'][0]['image_id']);
        $this->assertSame('abc123', $body['items'][1]['hash']);
    }

    public function test_squeeze_url_includes_top_level_options(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ], $history);

        $client->setOptions(['mode' => 'lossless', 'quality' => 80]);
        $client->squeezeUrl([['url' => 'https://example.com/a.jpg']]);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);

        $this->assertSame('lossless', $body['options']['mode']);
        $this->assertSame(80, $body['options']['quality']);
    }

    // ---------------------------------------------------------------
    //  compressFile
    // ---------------------------------------------------------------

    public function test_compress_file_returns_error_for_nonexistent_file(): void
    {
        // Should NOT make any HTTP call
        $history = [];
        $client = $this->createMockedClient([], $history);

        $result = $client->compressFile('/nonexistent/file.jpg');

        $this->assertFalse($result['state']);
        $this->assertSame('File not found: /nonexistent/file.jpg', $result['error']);
        $this->assertCount(0, $history);
    }

    public function test_compress_file_sends_multipart_post_to_compress(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'octo_test_');
        file_put_contents($tmpFile, 'fake image content');

        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['id' => 'job-99']])),
        ], $history);

        try {
            $client->compressFile($tmpFile);

            $this->assertCount(1, $history);
            $this->assertSame('POST', $history[0]['request']->getMethod());
            $this->assertSame(self::TEST_ENDPOINT . '/compress', (string) $history[0]['request']->getUri());

            // Multipart request has Content-Type with boundary
            $contentType = $history[0]['request']->getHeaderLine('Content-Type');
            $this->assertStringContainsString('multipart/form-data', $contentType);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_compress_file_sends_authorization_header(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'octo_test_');
        file_put_contents($tmpFile, 'fake image content');

        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ], $history);

        try {
            $client->compressFile($tmpFile);

            $authHeader = $history[0]['request']->getHeaderLine('Authorization');
            $this->assertSame('Bearer ' . self::TEST_API_KEY, $authHeader);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_compress_file_includes_file_content_in_body(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'octo_test_');
        file_put_contents($tmpFile, 'fake image content');

        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ], $history);

        try {
            $client->compressFile($tmpFile);

            $body = (string) $history[0]['request']->getBody();
            $this->assertStringContainsString('fake image content', $body);
            $this->assertStringContainsString(basename($tmpFile), $body);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_compress_file_sends_mode_and_format_in_multipart(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'octo_test_');
        file_put_contents($tmpFile, 'fake image content');

        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ], $history);

        try {
            $client->compressFile($tmpFile, ['mode' => 'lossy', 'formats' => ['webp']]);

            $body = (string) $history[0]['request']->getBody();
            // Multipart fields are embedded in the body
            $this->assertStringContainsString('lossy', $body);
            $this->assertStringContainsString('webp', $body);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_compress_file_uses_instance_options_when_no_call_options(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'octo_test_');
        file_put_contents($tmpFile, 'fake image content');

        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ], $history);

        try {
            $client->setOptions(['mode' => 'lossless']);
            $client->compressFile($tmpFile);

            $body = (string) $history[0]['request']->getBody();
            $this->assertStringContainsString('lossless', $body);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_compress_file_returns_success_response(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'octo_test_');
        file_put_contents($tmpFile, 'fake image content');

        $client = $this->createMockedClient([
            new Response(200, [], json_encode([
                'data' => ['id' => 'job-99', 'status' => 'completed'],
            ])),
        ]);

        try {
            $result = $client->compressFile($tmpFile);

            $this->assertTrue($result['state']);
            $this->assertSame('job-99', $result['data']['id']);
        } finally {
            @unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    //  getStatus
    // ---------------------------------------------------------------

    public function test_get_status_sends_get_to_status_endpoint(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['status' => 'processing']])),
        ], $history);

        $client->getStatus('job-abc-123');

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame(self::TEST_ENDPOINT . '/status/job-abc-123', (string) $history[0]['request']->getUri());
    }

    public function test_get_status_includes_auth_headers(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ], $history);

        $client->getStatus('job-1');

        $this->assertSame('Bearer ' . self::TEST_API_KEY, $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
    }

    public function test_get_status_returns_success_response(): void
    {
        $client = $this->createMockedClient([
            new Response(200, [], json_encode([
                'data' => [
                    'status' => 'completed',
                    'original_size' => 102400,
                    'compressed_size' => 51200,
                ],
            ])),
        ]);

        $result = $client->getStatus('job-xyz');

        $this->assertTrue($result['state']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(102400, $result['data']['original_size']);
    }

    // ---------------------------------------------------------------
    //  getUsage
    // ---------------------------------------------------------------

    public function test_get_usage_sends_get_to_usage_endpoint(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => ['credits' => 100]])),
        ], $history);

        $client->getUsage();

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame(self::TEST_ENDPOINT . '/usage', (string) $history[0]['request']->getUri());
    }

    public function test_get_usage_returns_success_response(): void
    {
        $client = $this->createMockedClient([
            new Response(200, [], json_encode([
                'data' => ['credits' => 100, 'used' => 42],
            ])),
        ]);

        $result = $client->getUsage();

        $this->assertTrue($result['state']);
        $this->assertSame(100, $result['data']['credits']);
        $this->assertSame(42, $result['data']['used']);
    }

    // ---------------------------------------------------------------
    //  download
    // ---------------------------------------------------------------

    public function test_download_sends_get_to_provided_url(): void
    {
        $history = [];
        $client = $this->createMockedClient([
            new Response(200, [], 'binary-image-data'),
        ], $history);

        $client->download('https://cdn.octosqueeze.com/compressed/abc.webp');

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('https://cdn.octosqueeze.com/compressed/abc.webp', (string) $history[0]['request']->getUri());
    }

    public function test_download_returns_raw_content_on_success(): void
    {
        $binaryContent = random_bytes(64);

        $client = $this->createMockedClient([
            new Response(200, ['Content-Type' => 'image/webp'], $binaryContent),
        ]);

        $result = $client->download('https://cdn.octosqueeze.com/abc.webp');

        $this->assertTrue($result['state']);
        $this->assertSame($binaryContent, $result['data']);
    }

    public function test_download_returns_failure_on_http_error(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Not Found',
                new Request('GET', 'https://cdn.octosqueeze.com/missing.webp'),
                new Response(404, [], json_encode(['message' => 'File not found']))
            ),
        ]);

        $result = $client->download('https://cdn.octosqueeze.com/missing.webp');

        $this->assertFalse($result['state']);
        $this->assertArrayHasKey('error', $result);
    }

    // ---------------------------------------------------------------
    //  downloadRaw
    // ---------------------------------------------------------------

    public function test_download_raw_returns_string_on_success(): void
    {
        $content = 'raw-binary-image-bytes';

        $client = $this->createMockedClient([
            new Response(200, [], $content),
        ]);

        $result = $client->downloadRaw('https://cdn.octosqueeze.com/abc.webp');

        $this->assertSame($content, $result);
    }

    public function test_download_raw_returns_null_on_failure(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Server Error',
                new Request('GET', 'https://cdn.octosqueeze.com/error.webp'),
                new Response(500)
            ),
        ]);

        $result = $client->downloadRaw('https://cdn.octosqueeze.com/error.webp');

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    //  Error Handling
    // ---------------------------------------------------------------

    public function test_http_401_returns_state_false_with_sanitized_message(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', self::TEST_ENDPOINT . '/usage'),
                new Response(401, [], json_encode(['message' => 'Invalid API key']))
            ),
        ]);

        $result = $client->getUsage();

        $this->assertFalse($result['state']);
        $this->assertSame('Invalid API key', $result['error']);
        $this->assertSame(401, $result['code']);
    }

    public function test_http_500_returns_state_false(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Server Error',
                new Request('POST', self::TEST_ENDPOINT . '/compress-batch'),
                new Response(500, [], '')
            ),
        ]);

        $result = $client->compressUrl('https://example.com/image.jpg');

        $this->assertFalse($result['state']);
        $this->assertSame('HTTP 500 error from API', $result['error']);
    }

    public function test_http_422_returns_server_error_message(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', self::TEST_ENDPOINT . '/compress-batch'),
                new Response(422, [], json_encode([
                    'error' => ['message' => 'The url field is required.'],
                ]))
            ),
        ]);

        $result = $client->compressUrl('');

        $this->assertFalse($result['state']);
        $this->assertSame('The url field is required.', $result['error']);
    }

    public function test_connection_timeout_returns_state_false(): void
    {
        $client = $this->createMockedClient([
            new ConnectException(
                'Connection timed out after 30000ms',
                new Request('POST', self::TEST_ENDPOINT . '/compress-batch')
            ),
        ]);

        $result = $client->compressUrl('https://example.com/image.jpg');

        $this->assertFalse($result['state']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_exception_message_does_not_leak_api_key(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Client error: `POST https://api.test.com/api/v1/compress-batch` resulted in 403 with headers Authorization: Bearer ' . self::TEST_API_KEY,
                new Request('POST', self::TEST_ENDPOINT . '/compress-batch'),
                new Response(403, [], json_encode(['message' => 'Forbidden']))
            ),
        ]);

        $result = $client->compressUrl('https://example.com/image.jpg');

        $this->assertFalse($result['state']);
        // sanitizeExceptionMessage extracts the server message, not the raw Guzzle exception
        $this->assertSame('Forbidden', $result['error']);
        $this->assertStringNotContainsString(self::TEST_API_KEY, $result['error']);
    }

    public function test_exception_without_response_returns_generic_message(): void
    {
        $client = $this->createMockedClient([
            new ConnectException(
                'cURL error 28: Connection timed out with Bearer ' . self::TEST_API_KEY,
                new Request('GET', self::TEST_ENDPOINT . '/usage')
            ),
        ]);

        $result = $client->getUsage();

        $this->assertFalse($result['state']);
        // ConnectException has no response, so sanitizeExceptionMessage returns generic message
        $this->assertStringStartsWith('Request to OctoSqueeze API failed:', $result['error']);
        $this->assertStringNotContainsString(self::TEST_API_KEY, $result['error']);
    }

    public function test_request_exception_with_nested_error_message(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Client error',
                new Request('POST', self::TEST_ENDPOINT . '/compress-batch'),
                new Response(400, [], json_encode([
                    'error' => ['message' => 'Invalid image format provided'],
                ]))
            ),
        ]);

        $result = $client->squeezeUrl([['url' => 'https://example.com/file.txt']]);

        $this->assertFalse($result['state']);
        $this->assertSame('Invalid image format provided', $result['error']);
    }

    public function test_response_without_error_message_returns_http_status(): void
    {
        $client = $this->createMockedClient([
            new RequestException(
                'Server Error',
                new Request('GET', self::TEST_ENDPOINT . '/usage'),
                new Response(502, [], 'Bad Gateway')
            ),
        ]);

        $result = $client->getUsage();

        $this->assertFalse($result['state']);
        $this->assertSame('HTTP 502 error from API', $result['error']);
    }

    // ---------------------------------------------------------------
    //  Retry Behavior (using real retry stack, not mocked handler)
    // ---------------------------------------------------------------

    public function test_retry_decider_retries_on_429(): void
    {
        $decider = $this->invokeRetryDecider();

        $request = new Request('GET', 'https://api.test.com/usage');
        $response = new Response(429);

        $this->assertTrue($decider(0, $request, $response, null));
        $this->assertTrue($decider(1, $request, $response, null));
        $this->assertFalse($decider(2, $request, $response, null)); // max 2 retries
    }

    public function test_retry_decider_retries_on_502_503_504(): void
    {
        $decider = $this->invokeRetryDecider();
        $request = new Request('GET', 'https://api.test.com/usage');

        foreach ([502, 503, 504] as $statusCode) {
            $response = new Response($statusCode);
            $this->assertTrue($decider(0, $request, $response, null), "Should retry on HTTP {$statusCode}");
        }
    }

    public function test_retry_decider_retries_on_connect_exception(): void
    {
        $decider = $this->invokeRetryDecider();
        $request = new Request('GET', 'https://api.test.com/usage');
        $exception = new ConnectException('Connection refused', $request);

        $this->assertTrue($decider(0, $request, null, $exception));
    }

    public function test_retry_decider_does_not_retry_on_400(): void
    {
        $decider = $this->invokeRetryDecider();
        $request = new Request('GET', 'https://api.test.com/usage');
        $response = new Response(400);

        $this->assertFalse($decider(0, $request, $response, null));
    }

    public function test_retry_decider_does_not_retry_on_401(): void
    {
        $decider = $this->invokeRetryDecider();
        $request = new Request('GET', 'https://api.test.com/usage');
        $response = new Response(401);

        $this->assertFalse($decider(0, $request, $response, null));
    }

    public function test_retry_decider_stops_after_max_retries(): void
    {
        $decider = $this->invokeRetryDecider();
        $request = new Request('GET', 'https://api.test.com/usage');
        $response = new Response(503);

        $this->assertFalse($decider(2, $request, $response, null));
        $this->assertFalse($decider(3, $request, $response, null));
    }

    public function test_retry_delay_uses_exponential_backoff(): void
    {
        $delay = $this->invokeRetryDelay();

        $this->assertSame(1000, $delay(0, null)); // 2^0 * 1000 = 1000ms
        $this->assertSame(2000, $delay(1, null)); // 2^1 * 1000 = 2000ms
    }

    public function test_retry_delay_respects_retry_after_header_on_429(): void
    {
        $delay = $this->invokeRetryDelay();

        $response = new Response(429, ['Retry-After' => '5']);

        $this->assertSame(5000, $delay(0, $response)); // 5 * 1000 = 5000ms
    }

    public function test_retry_delay_uses_exponential_backoff_for_non_429(): void
    {
        $delay = $this->invokeRetryDelay();

        $response = new Response(503);

        $this->assertSame(1000, $delay(0, $response)); // Not 429, so exponential
    }

    // ---------------------------------------------------------------
    //  Headers
    // ---------------------------------------------------------------

    public function test_get_headers_returns_correct_structure(): void
    {
        $client = new OctoSqueeze('my-api-key-789');

        $reflection = new \ReflectionMethod(OctoSqueeze::class, 'getHeaders');
        $reflection->setAccessible(true);
        $headers = $reflection->invoke($client);

        $this->assertSame('Bearer my-api-key-789', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    // ---------------------------------------------------------------
    //  Edge Cases
    // ---------------------------------------------------------------

    public function test_compress_url_handles_empty_data_response(): void
    {
        $client = $this->createMockedClient([
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $result = $client->compressUrl('https://example.com/image.jpg');

        $this->assertTrue($result['state']);
        $this->assertSame([], $result['items']);
        $this->assertNull($result['usage']);
    }

    public function test_get_status_handles_empty_data(): void
    {
        $client = $this->createMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $result = $client->getStatus('job-123');

        $this->assertTrue($result['state']);
        $this->assertSame([], $result['data']);
    }

    public function test_default_endpoint_uri(): void
    {
        $client = new OctoSqueeze('key');

        $reflection = new \ReflectionProperty(OctoSqueeze::class, 'endpointUri');
        $reflection->setAccessible(true);

        $this->assertSame('https://api.octosqueeze.com/api/v1', $reflection->getValue($client));
    }

    public function test_fluent_chaining(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['data' => ['items' => []]])),
        ]);
        $stack = HandlerStack::create($mock);

        $result = OctoSqueeze::client('key')
            ->setEndpointUri('https://api.test.com/api/v1')
            ->setHttpClientConfig(['handler' => $stack])
            ->setOptions(['mode' => 'lossy'])
            ->compressUrl('https://example.com/img.jpg');

        $this->assertTrue($result['state']);
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    /**
     * Get the retry decider callable from a fresh OctoSqueeze instance.
     */
    private function invokeRetryDecider(): callable
    {
        $client = new OctoSqueeze('key');
        $reflection = new \ReflectionMethod(OctoSqueeze::class, 'retryDecider');
        $reflection->setAccessible(true);

        return $reflection->invoke($client);
    }

    /**
     * Get the retry delay callable from a fresh OctoSqueeze instance.
     */
    private function invokeRetryDelay(): callable
    {
        $client = new OctoSqueeze('key');
        $reflection = new \ReflectionMethod(OctoSqueeze::class, 'retryDelay');
        $reflection->setAccessible(true);

        return $reflection->invoke($client);
    }
}
