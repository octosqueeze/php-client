# OctoSqueeze PHP Client

Official PHP client for the OctoSqueeze API. Compress images, convert to WebP/AVIF, and optimize for the web.

## Installation

```bash
composer require octosqueeze/php-client
```

## Requirements

- PHP 8.0 or higher
- Guzzle HTTP client

## Quick Start

```php
use OctoSqueeze\Client\OctoSqueeze;

$client = OctoSqueeze::client('your-api-key');

// Compress from URL
$result = $client->compressUrl('https://example.com/image.jpg');

if ($result['state']) {
    $compressed = $result['items'][0];
    echo "Original: {$compressed['original_size']} bytes\n";
    echo "Compressed: {$compressed['compressed_size']} bytes\n";
    echo "Savings: {$compressed['savings_percent']}%\n";
}
```

## Usage

### Initialize Client

```php
use OctoSqueeze\Client\OctoSqueeze;

$client = OctoSqueeze::client('your-api-key');

// Optional: Set custom endpoint (for self-hosted or testing)
$client->setEndpointUri('https://api.octosqueeze.com/api/v1');

// Optional: Set default options
$client->setOptions([
    'mode' => 'balanced',      // 'quality', 'balanced', or 'size'
    'formats' => ['webp', 'avif'],
]);
```

### Compress from URL

```php
// Single URL
$result = $client->compressUrl('https://example.com/image.jpg');

// With options
$result = $client->compressUrl('https://example.com/image.jpg', [
    'mode' => 'quality',
    'formats' => ['webp'],
]);
```

### Compress from File

```php
$result = $client->compressFile('/path/to/image.jpg');

if ($result['state']) {
    echo "Compression successful!\n";
    print_r($result['data']);
}
```

### Batch Compression

```php
$items = [
    ['url' => 'https://example.com/image1.jpg'],
    ['url' => 'https://example.com/image2.jpg'],
    ['url' => 'https://example.com/image3.jpg', 'options' => ['mode' => 'size']],
];

$result = $client->squeezeUrl($items);

if ($result['state']) {
    foreach ($result['items'] as $item) {
        echo "{$item['name']}: {$item['savings_percent']}% saved\n";
    }
}
```

### Download Compressed Image

```php
$result = $client->download($downloadUrl);

if ($result['state']) {
    file_put_contents('/path/to/output.webp', $result['data']);
}

// Or use the convenience method that returns raw content or null:
$content = $client->downloadRaw($downloadUrl);

if ($content) {
    file_put_contents('/path/to/output.webp', $content);
}
```

### Check Usage

```php
$usage = $client->getUsage();

if ($usage['state']) {
    echo "Images this month: {$usage['data']['images_this_month']}\n";
    echo "Limit: {$usage['data']['monthly_limit']}\n";
}
```

### Get Compression Status

```php
$status = $client->getStatus($jobId);

if ($status['state']) {
    echo "Status: {$status['data']['status']}\n";
}
```

## Compression Modes

| Mode | Description | Typical Savings |
|------|-------------|-----------------|
| `quality` | Maximum quality, minimal compression | 40-55% |
| `balanced` | Optimal balance (recommended) | 60-75% |
| `size` | Maximum compression | 70-85% |

## Output Formats

- `jpeg` - Standard JPEG output
- `png` - PNG output (with lossless option)
- `webp` - WebP format (30% smaller than JPEG)
- `avif` - AVIF format (50% smaller than JPEG)

## Error Handling

```php
$result = $client->compressUrl('https://example.com/image.jpg');

if (!$result['state']) {
    echo "Error: {$result['error']}\n";
    echo "Code: {$result['code']}\n";
}
```

## HTTP Client Configuration

```php
// Disable SSL verification (for development only)
$client->setHttpClientConfig([
    'verify' => false,
]);

// Set custom timeout (default 125 s: the API waits up to 120 s for the engine)
$client->setHttpClientConfig([
    'timeout' => 180,
]);
```

### Retries and billing

A request that never reached the API (DNS failure, refused connection), a
per-minute throttle (`429 rate_limited`), a busy account (`429 too_many_requests`)
and a `502`/`503` are retried up to twice. A compress call that **timed out** or
got a `504` is not: the API may already have compressed it, and it bills every
compression. A monthly or daily limit (`usage_limit_exceeded`,
`daily_limit_exceeded`) is not retried either — it will not clear in seconds.

A failed `compressFile()` / `compressUrl()` result carries `retryable`: `true`
only when sending it again cannot compress (and bill) the same image twice.

```php
$result = $client->compressFile($path);

if (! $result['state'] && $result['retryable']) {
    // safe to try again later
}
```

## Links

- [OctoSqueeze Website](https://octosqueeze.com)
- [API Documentation](https://octosqueeze.com/api)
- [Get API Key](https://octosqueeze.com/pricing)

## License

MIT License - see LICENSE file for details.
