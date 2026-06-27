<?php
// An AI email agent: inbound email → Claude drafts a reply → MailKite sends it, threaded.
// Give your product an inbox that answers itself.
//
// Flow: MailKite POSTs the inbound `email.received` event → verify it → Claude composes a concise
// reply → send it back with `inReplyTo` so it threads to the sender.
//
// Run:  MAILKITE_API_KEY=mk_live_… MAILKITE_WEBHOOK_SECRET=whsec_… ANTHROPIC_API_KEY=sk-ant-… \
//       php -S localhost:3000 03-agent-reply.php
// Deps: composer require mailkite/mailkite anthropic-ai/sdk

require __DIR__ . '/../vendor/autoload.php';

$mk = new \MailKite\Client(getenv('MAILKITE_API_KEY'));
$claude = new \Anthropic\Client(apiKey: getenv('ANTHROPIC_API_KEY'));
$secret = getenv('MAILKITE_WEBHOOK_SECRET');

$system = 'You are the support agent for Acme. Read the customer\'s email and write a short, friendly '
    . 'reply that directly answers them. Plain text. If you can\'t help, say a human will follow up.';

$raw = file_get_contents('php://input');  // verify against the RAW body — re-serialized JSON breaks the HMAC
$signature = $_SERVER['HTTP_X_MAILKITE_SIGNATURE'] ?? '';

if (!$mk->verifyWebhook($signature, $raw, $secret)) {
    http_response_code(401);
    echo 'bad signature';
    return;
}

$event = json_decode($raw, true);
if (($event['type'] ?? null) !== 'email.received') {
    header('Content-Type: application/json');
    echo $mk->replyOk();
    return;
}
$m = $event['message'] ?? $event;  // { from, to, subject, text, html, messageId, … }

// 1. Claude drafts the reply.
$msg = $claude->messages->create(
    model: 'claude-opus-4-8',  // swap to claude-sonnet-4-6 / claude-haiku-4-5 for lower cost
    maxTokens: 1024,
    system: $system,
    messages: [
        ['role' => 'user', 'content' => "From: {$m['from']}\nSubject: {$m['subject']}\n\n" . ($m['text'] ?? $m['html'] ?? '')],
    ],
);

$reply = 'Thanks — a human will follow up.';
foreach ($msg->content as $block) {
    if ($block->type === 'text') {
        $reply = $block->text;
        break;
    }
}

// 2. Send it back, threaded to the original.
$subject = str_starts_with((string) $m['subject'], 'Re:') ? $m['subject'] : "Re: {$m['subject']}";
$mk->send([
    'from' => $m['to'],  // reply from the address that received the mail
    'to' => $m['from'],
    'subject' => $subject,
    'text' => $reply,
    'inReplyTo' => $m['messageId'],
]);
error_log("🤖 replied to {$m['from']}");

header('Content-Type: application/json');
echo $mk->replyOk();
