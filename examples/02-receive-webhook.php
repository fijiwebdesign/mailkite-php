<?php
// Receive inbound email as a webhook — and VERIFY the HMAC signature before trusting it.
//
// MailKite POSTs a signed `email.received` event to your URL. Always verify the
// `x-mailkite-signature` header against your webhook secret so inbound mail can't be forged.
//
// Run:  MAILKITE_WEBHOOK_SECRET=whsec_… php -S localhost:3000 02-receive-webhook.php
//       (then point your domain's webhook at http://localhost:3000/ )
// Deps: composer require mailkite/mailkite

require __DIR__ . '/../vendor/autoload.php';

$mk = new \MailKite\Client(getenv('MAILKITE_API_KEY') ?: 'unused-for-verify');
$secret = getenv('MAILKITE_WEBHOOK_SECRET');

$raw = file_get_contents('php://input');  // the RAW body — re-serialized JSON breaks the HMAC
$signature = $_SERVER['HTTP_X_MAILKITE_SIGNATURE'] ?? '';

// verifyWebhook is local HMAC-SHA256 — no network call. Args: signature, raw payload, secret.
if (!$mk->verifyWebhook($signature, $raw, $secret)) {
    http_response_code(401);
    echo 'bad signature';
    return;
}

$event = json_decode($raw, true);
if (($event['type'] ?? null) === 'email.received') {
    $m = $event['message'] ?? $event;
    error_log("📬 {$m['from']} → {$m['to']}: {$m['subject']}");
    // …store it, notify a channel, kick off a workflow…
}

header('Content-Type: application/json');
echo $mk->replyOk();  // 200 acknowledges; replySpam()/replyDrop()/replyBlockSender() are control replies
