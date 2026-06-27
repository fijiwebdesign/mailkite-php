<?php

declare(strict_types=1);

// Conformance runner: drive the PHP SDK through the shared cases and print one
// JSON line per case. Invoked by ../../conformance/run.mjs.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/MailKiteException.php';
require __DIR__ . '/../src/Client.php';

use MailKite\Client;
use MailKite\MailKiteException;

$cases = json_decode(file_get_contents(getenv('MK_CASES')), true)['cases'];
$mk = new Client(getenv('MK_API_KEY'), getenv('MK_BASE_URL'));

function call(Client $mk, string $m, array $a)
{
    switch ($m) {
        case 'send': return $mk->send($a);
        case 'uploadAttachment': return $mk->uploadAttachment($a);
        case 'agent': return $mk->agent($a);
        case 'route': return $mk->route($a);
        case 'listTemplates': return $mk->listTemplates();
        case 'listBaseTemplates': return $mk->listBaseTemplates();
        case 'getTemplate': return $mk->getTemplate($a['id']);
        case 'createTemplate': return $mk->createTemplate($a);
        case 'listDomains': return $mk->listDomains();
        case 'createDomain': return $mk->createDomain($a);
        case 'getDomain': return $mk->getDomain($a['id']);
        case 'deleteDomain': return $mk->deleteDomain($a['id']);
        case 'verifyDomain': return $mk->verifyDomain($a['id']);
        case 'setWebhook': return $mk->setWebhook($a['id'], ['url' => $a['url']]);
        case 'deleteWebhook': return $mk->deleteWebhook($a['id']);
        case 'testWebhook': return $mk->testWebhook($a['id']);
        case 'checkDomainAvailability': return $mk->checkDomainAvailability($a['domain']);
        case 'registerDomain': return $mk->registerDomain($a);
        case 'listRoutes': return $mk->listRoutes();
        case 'createRoute': return $mk->createRoute($a);
        case 'listLists': return $mk->listLists();
        case 'createList': return $mk->createList($a);
        case 'getList': return $mk->getList($a['id']);
        case 'updateList': return $mk->updateList($a['id'], ['name' => $a['name']]);
        case 'deleteList': return $mk->deleteList($a['id']);
        case 'listListContacts': return $mk->listListContacts($a['id']);
        case 'addListContacts': return $mk->addListContacts($a['id'], ['contactIds' => $a['contactIds']]);
        case 'removeListContact': return $mk->removeListContact($a['id'], $a['contactId']);
        case 'listBroadcasts': return $mk->listBroadcasts();
        case 'createBroadcast': return $mk->createBroadcast($a);
        case 'getBroadcast': return $mk->getBroadcast($a['id']);
        case 'updateBroadcast': return $mk->updateBroadcast($a['id'], ['subject' => $a['subject']]);
        case 'deleteBroadcast': return $mk->deleteBroadcast($a['id']);
        case 'sendBroadcast': return $mk->sendBroadcast($a['id'], isset($a['scheduledAt']) ? ['scheduledAt' => $a['scheduledAt']] : null);
        case 'listMessages': return $mk->listMessages();
        case 'getMessage': return $mk->getMessage($a['id']);
        case 'retryDelivery': return $mk->retryDelivery($a['id']);
        case 'verifyWebhook': return $mk->verifyWebhook($a['signature'], $a['payload'], $a['secret'], (int) $a['toleranceMs']);
        case 'replyOk': return $mk->replyOk();
        case 'replySpam': return $mk->replySpam();
        case 'replyDrop': return $mk->replyDrop();
        case 'replyBlockSender': return $mk->replyBlockSender();
        case 'decrypt': return $mk->decrypt($a['envelope'], $a['privateKey']);
        case 'encryptRoundtrip': return $mk->decrypt($mk->encrypt($a['plaintext'], $a['publicKey']), $a['privateKey']);
    }
    throw new \RuntimeException("unknown method $m");
}

foreach ($cases as $c) {
    try {
        $result = call($mk, $c['method'], $c['args']);
        echo json_encode(['name' => $c['name'], 'result' => $result]) . "\n";
    } catch (MailKiteException $e) {
        echo json_encode(['name' => $c['name'], 'error' => ['status' => $e->status, 'message' => $e->getMessage()]]) . "\n";
    }
}
