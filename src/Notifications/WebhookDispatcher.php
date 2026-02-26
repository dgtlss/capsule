<?php

namespace Dgtlss\Capsule\Notifications;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WebhookDispatcher
{
    protected Client $client;
    protected int $maxRetries;
    protected int $baseBackoffMs;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
        $this->maxRetries = (int) config('capsule.notifications.webhook_retries', 3);
        $this->baseBackoffMs = (int) config('capsule.notifications.webhook_backoff_ms', 1000);
    }

    /**
     * Send a webhook with retry and exponential backoff.
     * Returns delivery result.
     */
    public function dispatch(string $url, array $payload, string $channel): array
    {
        $attempts = 0;
        $lastError = null;

        while ($attempts <= $this->maxRetries) {
            $attempts++;

            try {
                $response = $this->client->post($url, [
                    'json' => $payload,
                    'timeout' => 15,
                    'connect_timeout' => 5,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    Log::debug("Capsule webhook delivered to {$channel}", [
                        'attempts' => $attempts,
                        'status_code' => $statusCode,
                    ]);

                    return [
                        'delivered' => true,
                        'channel' => $channel,
                        'attempts' => $attempts,
                        'status_code' => $statusCode,
                        'error' => null,
                    ];
                }

                $lastError = "HTTP {$statusCode}";

                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    break;
                }
            } catch (RequestException $e) {
                $lastError = $e->getMessage();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                break;
            }

            if ($attempts <= $this->maxRetries) {
                $backoff = $this->baseBackoffMs * pow(2, $attempts - 1);
                usleep($backoff * 1000);
            }
        }

        Log::error("Capsule webhook delivery failed for {$channel} after {$attempts} attempt(s)", [
            'channel' => $channel,
            'error' => $lastError,
        ]);

        return [
            'delivered' => false,
            'channel' => $channel,
            'attempts' => $attempts,
            'status_code' => null,
            'error' => $lastError,
        ];
    }
}
