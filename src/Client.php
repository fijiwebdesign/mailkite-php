<?php

declare(strict_types=1);

namespace MailKite;

/**
 * MailKite SDK for PHP.
 *
 * Shape shared by every MailKite SDK: one low-level request() plus one thin
 * method per API endpoint. Uses ext-curl; no other dependencies.
 *
 *   $mk = new \MailKite\Client(getenv('MAILKITE_API_KEY'));
 *   $res = $mk->send([
 *     'from' => 'hello@app.mailkite.dev',
 *     'to' => 'ada@example.com',
 *     'subject' => 'Hi',
 *     'text' => 'It works.',
 *   ]);
 */
class Client
{
    /** Reject webhook events older than this (ms) to block replays. Pass 0 to disable. */
    public const DEFAULT_TOLERANCE_MS = 5 * 60 * 1000;

    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.mailkite.dev')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Low-level request. Every method below is a one-liner on top of this. */
    public function request(string $method, string $path, $body = null)
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Authorization: Bearer ' . $this->apiKey];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new MailKiteException(0, $err ?: 'request failed');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = $raw === '' ? null : json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && isset($data['error']) ? $data['error'] : "HTTP $status";
            throw new MailKiteException($status, $message, $data);
        }
        return $data;
    }

    // --- Sending ----------------------------------------------------------
    public function send($message)
    {
        return $this->request('POST', '/v1/send', $message);
    }

    // --- Domains ----------------------------------------------------------
    public function listDomains()
    {
        return $this->request('GET', '/api/domains');
    }

    public function createDomain($body)
    {
        return $this->request('POST', '/api/domains', $body);
    }

    public function getDomain(string $id)
    {
        return $this->request('GET', "/api/domains/$id");
    }

    public function deleteDomain(string $id)
    {
        return $this->request('DELETE', "/api/domains/$id");
    }

    public function verifyDomain(string $id)
    {
        return $this->request('POST', "/api/domains/$id/verify");
    }

    public function setWebhook(string $id, $body)
    {
        return $this->request('PUT', "/api/domains/$id/webhook", $body);
    }

    public function deleteWebhook(string $id)
    {
        return $this->request('DELETE', "/api/domains/$id/webhook");
    }

    public function testWebhook(string $id)
    {
        return $this->request('POST', "/api/domains/$id/webhook/test");
    }

    public function checkDomainAvailability(string $domain)
    {
        return $this->request('GET', '/api/domains/register/check?domain=' . rawurlencode($domain));
    }

    public function registerDomain($body)
    {
        return $this->request('POST', '/api/domains/register', $body);
    }

    // --- Routes -----------------------------------------------------------
    public function listRoutes()
    {
        return $this->request('GET', '/api/routes');
    }

    public function createRoute($body)
    {
        return $this->request('POST', '/api/routes', $body);
    }

    // --- Messages & deliveries -------------------------------------------
    public function listMessages()
    {
        return $this->request('GET', '/api/messages');
    }

    public function getMessage(string $id)
    {
        return $this->request('GET', "/api/messages/$id");
    }

    public function retryDelivery(string $id)
    {
        return $this->request('POST', "/api/deliveries/$id/retry");
    }

    // --- Webhooks ---------------------------------------------------------
    /**
     * Verify the `x-mailkite-signature` header on an inbound webhook delivery.
     * Local HMAC-SHA256 check — no network call. Pass the raw, unparsed body.
     *
     * @param int $toleranceMs reject events older than this many ms (0 disables).
     */
    public function verifyWebhook(string $signature, string $payload, string $secret, int $toleranceMs = self::DEFAULT_TOLERANCE_MS): bool
    {
        if ($signature === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $signature) as $seg) {
            $i = strpos($seg, '=');
            if ($i === false) {
                continue;
            }
            $parts[trim(substr($seg, 0, $i))] = trim(substr($seg, $i + 1));
        }
        $t = $parts['t'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($t === null || $v1 === null || $v1 === '' || preg_match('/\A-?\d+\z/', $t) !== 1) {
            return false;
        }
        // The t in the header is milliseconds since the epoch.
        if ($toleranceMs > 0) {
            $nowMs = (int) round(microtime(true) * 1000);
            if (abs($nowMs - (int) $t) > $toleranceMs) {
                return false;
            }
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        return hash_equals($expected, $v1);
    }
}
