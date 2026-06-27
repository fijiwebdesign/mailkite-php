<?php
// Send an email over a verified domain — the 10-second "it works".
//
// Run:  MAILKITE_API_KEY=mk_live_… php 01-send-email.php
// Deps: composer require mailkite/mailkite

require __DIR__ . '/../vendor/autoload.php';

$mk = new \MailKite\Client(getenv('MAILKITE_API_KEY'));

$res = $mk->send([
    'from' => 'hello@yourdomain.com',  // an address on a domain you've verified
    'to' => 'ada@example.com',
    'subject' => 'Your invoice #1042',
    'html' => '<p>Thanks for your order — receipt attached.</p>',
    // text, cc, bcc, replyTo, attachments, templateId, templateData all supported
]);

echo 'sent: ' . json_encode($res) . "\n";  // → { "id": "msg_…", "status": "queued" }
