<?php
declare(strict_types=1);

/**
 * Minimal cURL client for the EmnexAnime API.
 * The API key is added server-side only and never reaches the browser.
 */
final class HttpClient
{
    public function __construct(
        private readonly string $apiKey = '',
        private readonly int $timeout = 25,
        private readonly bool $insecure = false,
    ) {
    }

    /**
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function get(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.8',
            'User-Agent: EmnexAnimeSite/1.0 (+vanilla-php)',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'x-api-key: ' . $this->apiKey;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => !$this->insecure,
            CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts  = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Upstream request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return [
            'status'  => $status,
            'body'    => (string) $body,
            'headers' => $responseHeaders,
        ];
    }
}
