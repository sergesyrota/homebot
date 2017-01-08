<?php

require_once __DIR__ . '/vendor/autoload.php';

use Aws\Sqs\SqsClient;
use pimax\FbBotApp;
use pimax\Messages\Message;

$queueUrl = getRequiredEnv('SQS_URL');

$token = getRequiredEnv('FB_PAGE_TOKEN');
$bot = new FbBotApp($token);

$client = SqsClient::factory(array(
    'profile' => 'homebot-queue',
    'region'  => 'us-east-1',
    'version' => 'latest'
));

$result = $client->receiveMessage(array(
    'QueueUrl' => $queueUrl,
    'MaxNumberOfMessages' => 1
));

foreach ($result as $message) {
    if (!isset($message[0]['ReceiptHandle'])) {
      continue;
    }
    // Do something with the message
    echo "Next message in queue: \n";
    print_r($message[0]);
    $body = json_decode($message[0]['Body']);
    print_r($body);
    $bot->send(new Message($body->sender, $body->text));
    $client->deleteMessage([
      'QueueUrl' => $queueUrl,
      'ReceiptHandle' => $message[0]['ReceiptHandle'],
    ]);
}

function getRequiredEnv($var)
{
  $data = getenv($var);
  if (empty($data)) {
    throw new Exception("Please specify $var as environment variable to run.");
  }
  return $data;
}
