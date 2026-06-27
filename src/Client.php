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
    /**
     * Send a message. Keys: from, to, text/html, etc. `subject` is optional
     * when a template supplies it. Pass `templateId` (a tpl_… or base_…) to
     * render a stored template and `templateData` (an array) to fill its
     * variables.
     */
    public function send($message)
    {
        return $this->request('POST', '/v1/send', $message);
    }

    /**
     * Extension → MIME type map for guessing the Content-Type of a raw upload.
     */
    private const ATTACHMENT_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'json' => 'application/json',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ics' => 'text/calendar',
        'ical' => 'text/calendar',
    ];

    /** Guess a Content-Type from a filename's extension, defaulting to octet-stream. */
    private static function guessContentType(?string $filename): string
    {
        if ($filename === null || $filename === '') {
            return 'application/octet-stream';
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::ATTACHMENT_MIME_TYPES[$ext] ?? 'application/octet-stream';
    }

    /**
     * Upload a file and get back a secure, time-limited URL to reference as a
     * send() attachment ({ filename, url }) or link inline — instead of
     * base64-inlining large files on every send.
     *
     * Provide the file ONE of four ways (checked in this order):
     *   - `url`     — MailKite fetches & re-hosts the file at this URL.
     *   - `bytes`   — raw binary string, uploaded as the request body.
     *   - `path`    — local file path; read off disk then uploaded as raw bytes.
     *   - `content` — base64-encoded string, sent as a JSON body.
     * Optional: `filename`, `contentType`, `retentionDays`.
     */
    public function uploadAttachment($file)
    {
        $filename = $file['filename'] ?? null;
        $contentType = $file['contentType'] ?? null;
        $retentionDays = $file['retentionDays'] ?? null;

        // 1. url → JSON body, MailKite fetches & re-hosts.
        if (isset($file['url'])) {
            $body = ['url' => $file['url']];
            if ($filename !== null) {
                $body['filename'] = $filename;
            }
            if ($contentType !== null) {
                $body['contentType'] = $contentType;
            }
            if ($retentionDays !== null) {
                $body['retentionDays'] = $retentionDays;
            }
            return $this->request('POST', '/v1/attachments', $body);
        }

        // 2. bytes → raw binary upload.
        if (isset($file['bytes'])) {
            return $this->requestBinary($file['bytes'], $filename, $contentType, $retentionDays);
        }

        // 3. path → read off disk, then raw binary upload.
        if (isset($file['path'])) {
            $path = $file['path'];
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException("could not read file: $path");
            }
            if ($filename === null) {
                $filename = basename($path);
            }
            if ($contentType === null) {
                $contentType = self::guessContentType($filename);
            }
            return $this->requestBinary($bytes, $filename, $contentType, $retentionDays);
        }

        // 4. content → base64 JSON body (existing behavior).
        if (isset($file['content'])) {
            $body = ['content' => $file['content']];
            if ($filename !== null) {
                $body['filename'] = $filename;
            }
            if ($contentType !== null) {
                $body['contentType'] = $contentType;
            }
            if ($retentionDays !== null) {
                $body['retentionDays'] = $retentionDays;
            }
            return $this->request('POST', '/v1/attachments', $body);
        }

        throw new \InvalidArgumentException('uploadAttachment needs one of: url, bytes, path, content');
    }

    /**
     * Raw binary upload to /v1/attachments. Body is the raw file bytes (not JSON,
     * not multipart); filename and retentionDays go in the query string.
     */
    private function requestBinary(string $bytes, ?string $filename, ?string $contentType, $retentionDays)
    {
        $query = 'filename=' . rawurlencode((string) $filename);
        if ($retentionDays !== null) {
            $query .= '&retentionDays=' . rawurlencode((string) $retentionDays);
        }
        $contentType = $contentType ?? self::guessContentType($filename);

        $ch = curl_init($this->baseUrl . '/v1/attachments?' . $query);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: ' . $contentType,
        ];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bytes);
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

    /**
     * Hand a message to the agent. Keys: text (required), plus optional
     * subject, from, html, routeId, address, model.
     */
    public function agent($message)
    {
        return $this->request('POST', '/v1/agent', $message);
    }

    /**
     * Route a message. Keys: from (required), plus optional routeId,
     * address, subject, text, html.
     */
    public function route($message)
    {
        return $this->request('POST', '/v1/route', $message);
    }

    // --- Templates --------------------------------------------------------
    public function listTemplates()
    {
        return $this->request('GET', '/api/templates');
    }

    public function listBaseTemplates()
    {
        return $this->request('GET', '/api/templates/base');
    }

    public function getTemplate(string $id)
    {
        return $this->request('GET', "/api/templates/$id");
    }

    public function createTemplate($body)
    {
        return $this->request('POST', '/api/templates', $body);
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

    // --- Contact lists ----------------------------------------------------
    public function listLists()
    {
        return $this->request('GET', '/api/lists');
    }

    public function createList($body)
    {
        return $this->request('POST', '/api/lists', $body);
    }

    public function getList(string $id)
    {
        return $this->request('GET', "/api/lists/$id");
    }

    public function updateList(string $id, $body)
    {
        return $this->request('PATCH', "/api/lists/$id", $body);
    }

    public function deleteList(string $id)
    {
        return $this->request('DELETE', "/api/lists/$id");
    }

    public function listListContacts(string $id)
    {
        return $this->request('GET', "/api/lists/$id/contacts");
    }

    public function addListContacts(string $id, $body)
    {
        return $this->request('POST', "/api/lists/$id/contacts", $body);
    }

    public function removeListContact(string $id, string $contactId)
    {
        return $this->request('DELETE', "/api/lists/$id/contacts/$contactId");
    }

    // --- Broadcasts -------------------------------------------------------
    public function listBroadcasts()
    {
        return $this->request('GET', '/api/broadcasts');
    }

    public function createBroadcast($body)
    {
        return $this->request('POST', '/api/broadcasts', $body);
    }

    public function getBroadcast(string $id)
    {
        return $this->request('GET', "/api/broadcasts/$id");
    }

    public function updateBroadcast(string $id, $body)
    {
        return $this->request('PATCH', "/api/broadcasts/$id", $body);
    }

    public function deleteBroadcast(string $id)
    {
        return $this->request('DELETE', "/api/broadcasts/$id");
    }

    public function sendBroadcast(string $id, $body = null)
    {
        return $this->request('POST', "/api/broadcasts/$id/send", $body);
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

    /**
     * The canonical body an inbound webhook handler returns to ack an event.
     * Local, no network.
     */
    public function replyOk(): string
    {
        return '{"status":"ok"}';
    }

    /**
     * Control-mode reply telling MailKite to mark the message as spam.
     * Local, no network.
     */
    public function replySpam(): string
    {
        return '{"status":"spam"}';
    }

    /**
     * Control-mode reply telling MailKite to drop (discard) the message.
     * Local, no network.
     */
    public function replyDrop(): string
    {
        return '{"status":"drop"}';
    }

    /**
     * Control-mode reply telling MailKite to block the sender.
     * Local, no network.
     */
    public function replyBlockSender(): string
    {
        return '{"status":"ok","actions":[{"type":"block-sender"}]}';
    }

    // --- At-rest encryption ----------------------------------------------
    // Hybrid envelope, byte-compatible with MailKite's WebCrypto scheme:
    //   1. a fresh AES-256-GCM content key encrypts the plaintext,
    //   2. the content key is wrapped with the recipient's RSA-OAEP (SHA-256) public key.
    // Only RSA-OAEP-SHA256 is interoperable here; PHP's openssl_public_encrypt only
    // offers OAEP-SHA1, so we use phpseclib v3 for the key wrap/unwrap.

    /** Strip a PEM wrapper to its base64-decoded DER body. */
    private static function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);
        if ($body === '' || $body === null) {
            throw new \InvalidArgumentException('empty or malformed PEM');
        }
        $der = base64_decode($body, true);
        if ($der === false) {
            throw new \InvalidArgumentException('malformed PEM body');
        }
        return $der;
    }

    /**
     * Encrypt a UTF-8 string to an at-rest envelope (JSON string).
     * `$publicKey` is an RSA public key in SPKI/PEM form. Local, no network.
     */
    public function encrypt(string $plaintext, string $publicKey): string
    {
        $spkiDer = self::pemToDer($publicKey);
        $fp = strtolower(bin2hex(hash('sha256', $spkiDer, true)));

        // 1. Fresh AES-256-GCM content key + 12-byte IV; encrypt the plaintext.
        $rawKey = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('AES-GCM encryption failed');
        }
        // WebCrypto appends the 16-byte auth tag to the ciphertext.
        $ciphertext = $ct . $tag;

        // 2. Wrap the content key with RSA-OAEP (SHA-256 hash + MGF1-SHA256).
        $rsa = \phpseclib3\Crypt\PublicKeyLoader::load($publicKey)
            ->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
        $wrapped = $rsa->encrypt($rawKey);

        return json_encode([
            'v' => 1,
            'keyAlg' => 'RSA-OAEP-256',
            'fp' => $fp,
            'enc' => 'A256GCM',
            'iv' => base64_encode($iv),
            'wrappedKey' => base64_encode($wrapped),
            'ciphertext' => base64_encode($ciphertext),
        ]);
    }

    /**
     * Decrypt an at-rest envelope (JSON string) back to its UTF-8 plaintext.
     * `$privateKey` is the matching RSA private key in PKCS#8/PEM form. Local, no network.
     */
    public function decrypt(string $envelope, string $privateKey): string
    {
        $env = json_decode($envelope, true);
        if (!is_array($env)) {
            throw new \InvalidArgumentException('malformed envelope JSON');
        }

        $iv = base64_decode($env['iv'], true);
        $wrappedKey = base64_decode($env['wrappedKey'], true);
        $ciphertext = base64_decode($env['ciphertext'], true);
        if ($iv === false || $wrappedKey === false || $ciphertext === false) {
            throw new \InvalidArgumentException('malformed envelope fields');
        }
        if (strlen($ciphertext) < 16) {
            throw new \InvalidArgumentException('ciphertext too short for GCM tag');
        }

        // Split the appended 16-byte GCM tag back off the ciphertext.
        $tag = substr($ciphertext, -16);
        $body = substr($ciphertext, 0, -16);

        // Unwrap the content key with RSA-OAEP (SHA-256 hash + MGF1-SHA256).
        $rsa = \phpseclib3\Crypt\PublicKeyLoader::load($privateKey)
            ->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
        $rawKey = $rsa->decrypt($wrappedKey);

        $plaintext = openssl_decrypt($body, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag, '');
        if ($plaintext === false) {
            throw new \RuntimeException('AES-GCM decryption failed');
        }
        return $plaintext;
    }
}
