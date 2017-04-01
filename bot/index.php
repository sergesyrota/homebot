<?php

require_once __DIR__ . '/vendor/autoload.php';

// CRON setup and re-run protection
$pidFile = getRequiredEnv('HOMEBOT_PID_FILE');
if (!is_writeable(dirname($pidFile))) {
    throw new Exception("PID file should be in writeable folder");
}

// Exit if the process is running.
if (isProcessRunning($pidFile)) {
    exit(0);
}

file_put_contents($pidFile, posix_getpid());
function removePidFile() {
    unlink(getRequiredEnv('HOMEBOT_PID_FILE'));
}
register_shutdown_function('removePidFile');
// END CRON setup

require_once '/var/www/home/dashboard/include/rs485.php';
$rs = new rs485;

use Aws\Sqs\SqsClient;
use pimax\FbBotApp;
use pimax\Messages\Message;
use pimax\Messages\ImageMessage;

$queueUrl = getRequiredEnv('SQS_URL');

$token = getRequiredEnv('FB_PAGE_TOKEN');
$bot = new FbBotApp($token);

$client = SqsClient::factory(array(
    'profile' => 'homebot-queue',
    'region'  => 'us-east-1',
    'version' => 'latest'
));

$start = time();
while (true) {
    $result = $client->receiveMessage(array(
        'QueueUrl' => $queueUrl,
        'MaxNumberOfMessages' => 1,
        'WaitTimeSeconds' => 20
    ));

    foreach ($result as $message) {
        if (!isset($message[0]['ReceiptHandle'])) {
          continue;
        }
        $client->deleteMessage([
          'QueueUrl' => $queueUrl,
          'ReceiptHandle' => $message[0]['ReceiptHandle'],
        ]);

        handleMessage(json_decode($message[0]['Body']), $bot);
    // Do something with the message
//    echo "Next message in queue: \n";
//    print_r($message[0]);
//    $body = json_decode($message[0]['Body']);
//    print_r($body);
//    $bot->send(new Message($body->sender, $body->text));
    }
    if ((time() - $start) > 3600*24*7) {
        // Reboot after a week, as it seems it stops responding normally after that time frame
        exit();
    }
}

function getRequiredEnv($var)
{
  $data = getenv($var);
  if (empty($data)) {
    throw new Exception("Please specify $var as environment variable to run.");
  }
  return $data;
}

function isProcessRunning($pidFile) {
    if (!file_exists($pidFile) || !is_file($pidFile)) return false;
    $pid = file_get_contents($pidFile);
    return posix_kill($pid, 0);
}

function handleMessage($body, $bot) {
    if (
        (stristr($body->text, 'temperature') && stristr($body->text, 'master')) ||
        (stristr($body->text, 'temp') && stristr($body->text, 'master'))
    ) {
        return handleMasterTemp($body, $bot);
    }
    
    if (
        (stristr($body->text, 'temperature') && stristr($body->text, 'leah')) ||
        (stristr($body->text, 'temp') && stristr($body->text, 'leah'))
    ) {
        return handleLeahTemp($body, $bot);
    }
    
    if (
        (stristr($body->text, 'show') && stristr($body->text, 'leah')) ||
        (stristr($body->text, 'crib') && stristr($body->text, 'leah')) ||
        (stristr($body->text, 'sleeping') && stristr($body->text, 'leah'))
    ) {
        return handleLeahCrib($body, $bot);
    }
    
    if (
        (stristr($body->text, 'garage open')) ||
        (stristr($body->text, 'garage closed'))
    ) {
        return handleGarageStatus($body, $bot);
    }
    
    if (
        (stristr($body->text, 'open garage'))
    ) {
        return handleGarageOpen($body, $bot);
    }
    
    if (
        (stristr($body->text, 'close garage'))
    ) {
        return handleGarageClose($body, $bot);
    }
    
    if (
        (stristr($body->text, 'wake pc'))
    ) {
        return handleWakePc($body, $bot);
    }
    
    return handleUnknown($body, $bot);
}

function handleUnknown($body, $bot) {
    $bot->send(new Message($body->sender, "Hmm, I'm not sure what that means yet."));
}

function handleMasterTemp($body, $bot) {
    $current = json_decode(tryCmd('EnvMaster', 'getDht', 1));
    $target = `php /home/sergey/temps/hvac-zoning/server/cron-bedroom-controller.php getTarget`;
    $msg = sprintf("Current temp in master: %.01fF; Target: %.01fF", round(ctof($current->t/100),1), ctof($target));
    $bot->send(new Message($body->sender, $msg));
    sendGraph($body, $bot);
}

function handleLeahTemp($body, $bot) {
    $targetTemp;
    $currentTemp;
    $systemMode;
    $systemOn;
    // Middle Thermostat data
    $therm = json_decode(`curl -m 15 http://192.168.8.90/tstat/ 2>/dev/null`);
    if ($therm->tmode==2) {
        $targetTemp = $therm->t_cool;
        $systemMessage = "AC is " . ($therm->tstate ? "on" : "off") . " right now.";
    } else {
        $targetTemp = $therm->t_heat;
        $systemMessage = "Heat is " . ($therm->tstate ? "on" : "off") . " right now.";
    }
    $msg = sprintf("Current temp in Leah's room: %.01fF; Target: %dF", $therm->temp, $targetTemp);
    $bot->send(new Message($body->sender, $msg));
    $bot->send(new Message($body->sender, $systemMessage));
    sendGraph($body, $bot);
}

function sendGraph($body, $bot) {
    $url = 'http://munin.syrota.com/munin-cgi/munin-cgi-graph/local/srv1.local/bedroom_temperature-pinpoint='.(time()-(24*3600)).','.time().'.png?&lower_limit=&upper_limit=&size_x=800&size_y=400';
    sendImage($body, $bot, $url);
}

function handleLeahCrib($body, $bot) {
    $url = "http://cam-living.syrota.com/cgi-bin/CGIProxy.fcgi?cmd=snapPicture2&usr=view&pwd=view";
    sendImage($body, $bot, $url);
}

function sendImage($body, $bot, $url) {
    $img = tempnam('/tmp', 'homebot-image');
    file_put_contents($img, file_get_contents($url));
    $token = getRequiredEnv('FB_PAGE_TOKEN');
    $curlCommand = "curl  \
      -F 'recipient={\"id\":\"{$body->sender}\"}' \
      -F 'message={\"attachment\":{\"type\":\"image\", \"payload\":{}}}' \
      -F 'filedata=@{$img};type=".mime_content_type($img)."' \
      \"https://graph.facebook.com/v2.6/me/messages?access_token={$token}\" 2>/dev/null";
     `$curlCommand`;
//    print_r($bot->send(new ImageMessage($body->sender, $img)));
    unlink($img);
}

function handleGarageStatus($body, $bot) {
    $data = json_decode(tryCmd('GarageSens', 'getDoors', 1));
    $msg = sprintf("Garage is %s.", ($data->garage ? "open" : "closed"));
    $bot->send(new Message($body->sender, $msg));
}

function handleGarageOpen($body, $bot) {
    $data = json_decode(tryCmd('GarageSens', 'getDoors', 1));
    if (0 == $data->garage) {
        $status = tryCmd('GarageSens', 'openGarage', 1);
        if ('OK' == $status) {
            $bot->send(new Message($body->sender, "Button pressed"));
        }
    } else {
        handleGarageStatus($body, $bot);
    }
}

function handleGarageClose($body, $bot) {
    $data = json_decode(tryCmd('GarageSens', 'getDoors', 1));
    if (1 == $data->garage) {
        $status = tryCmd('GarageSens', 'closeGarage', 1);
        if ('OK' == $status) {
            $bot->send(new Message($body->sender, "Button pressed"));
        }
    } else {
        handleGarageStatus($body, $bot);
    }
}

function handleWakePc($body, $bot) {
    exec('wakelan BC:5F:F4:65:A8:13', $out, $retval);
    if ($retval != 0) {
        $bot->send(new Message($body->sender, "Not able to wake: " . $out));
    }
    $bot->send(new Message($body->sender, "Wake command sent"));
}


// Makes a few attempts to get results from RS485;
function tryCmd($device, $command, $attempts=3) {
    $rs = new rs485();
    $lastException = new Exception('Unknown error?');
    for ($i=0; $i<$attempts; $i++) {
        try {
            $out = $rs->command($device, $command);
            return $out;
        } catch(Exception $e) {
            $lastException = $e;
        }
    }
    throw $lastException;
}

function ctof($c) {
    return(($c * 9/5) + 32);
}
