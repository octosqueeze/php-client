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
$client->setEndpointUri('https://app.octosqueeze.com/api/v1');

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
$content = $client->download($result['items'][0]['download_url']);

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

// Set custom timeout
$client->setHttpClientConfig([
    'timeout' => 60,
]);
```

## Links

- [OctoSqueeze Website](https://octosqueeze.com)
- [API Documentation](https://octosqueeze.com/api)
- [Get API Key](https://octosqueeze.com/pricing)

## License

MIT License - see LICENSE file for details.
