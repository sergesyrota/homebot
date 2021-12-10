<?php

if (time() < strtotime('2018-08-16 14:00:00')) {
	die();
}
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
// subscriber => FB id, checkCondition => bool function, message => static text
$notifySubscribers = [];
while (true) {
    touch($pidFile);
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
    echo "Next message in queue: \n";
    print_r($message[0]);
//    $body = json_decode($message[0]['Body']);
//    print_r($body);
//    $bot->send(new Message($body->sender, $body->text));
    }
    // Handle notifications
    foreach ($notifySubscribers as $key=>$notify) {
        if ($notify['checkCondition']()) {
            unset($notifySubscribers[$key]);
            $bot->send(new Message($notify['subscriber'], $notify['message']));
        }
    }
    if ((time() - $start) > 3600*24*7) {
        // Reboot after a week, as it seems it stops responding normally after some time
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
    // Check if process is dead
    if (time() - filemtime($pidFile) > 300) {
        posix_kill($pid, SIGKILL);
        return false;
    }
    return posix_kill($pid, 0);
}

function handleMessage($body, $bot) {
    // OUCH... Bad hack!
    global $notifySubscribers;
    
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
    
    if ((stristr($body->text, 'notify') && stristr($body->text, 'sleeping') && stristr($body->text, 'leah'))
    ) {
        /*
        $notifySubscribers[] = ['subscriber' => $body->sender, 'checkCondition' => function() {
                $data = analyzeLeahData();
                if ($data['inBedProb'] > 0.6 && !$data['moving']) {
                    return true;
                } else {
                    return false;
                }
            }, 'message' => 'Looks like Leah fell asleep'];
        */
        $bot->send(new Message($body->sender, "Sorry, don't have info on this right now."));
    }
    
    if ((stristr($body->text, 'notify') && stristr($body->text, 'garage') && stristr($body->text, 'open'))
    ) {
        $notifySubscribers[] = ['subscriber' => $body->sender, 'checkCondition' => function() {
                $data = getGarageDoors();
                if ($data->garage) {
                    return true;
                } else {
                    return false;
                }
            }, 'message' => 'Garage was opened.'];
        $bot->send(new Message($body->sender, "Ok, will let you know."));
    }
    
    if (
        (stristr($body->text, 'show') && stristr($body->text, 'leah')) ||
        (stristr($body->text, 'crib') && stristr($body->text, 'leah')) ||
        (stristr($body->text, 'sleeping') && stristr($body->text, 'leah'))
    ) {
        return handleLeahCrib($body, $bot);
    }
    
    if (
        (stristr($body->text, 'show') && stristr($body->text, 'sam')) ||
        (stristr($body->text, 'crib') && stristr($body->text, 'sam')) ||
        (stristr($body->text, 'sleeping') && stristr($body->text, 'sam'))
    ) {
        return handleSamCrib($body, $bot);
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
    
    if (
        (stristr($body->text, 'ack sump pump'))
    ) {
        return handleSumpPumpAck($body, $bot);
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
    // Get status from neural net
    /*
    $data = analyzeLeahData();
    if ($data['age'] < 300) {
        if ($data['inBedProb'] >= 0.4 && $data['inBedProb'] <= 0.6) {
            $message = 'Not sure, check out the pic';
        } else if ($data['inBedProb'] > 0.6 && !$data['moving']) {
            $message = 'Looks sleeping to me';
        } else if ($data['inBedProb'] > 0.6 && $data['moving']) {
            $message = 'Does not seem like she\'s sleeping';
        } else if ($data['inBedProb'] < 0.4) {
            $message = 'Seems nobody\'s there';
        }
        $bot->send(new Message($body->sender, $message));
    }*/
    //$url = "http://cam-living.syrota.com/cgi-bin/CGIProxy.fcgi?cmd=snapPicture2&usr=view&pwd=view";
    $url = getenv('LEAH_CAMERA');
    if (!empty($url)) {
        sendImage($body, $bot, $url, __DIR__.'/leah-images/', true);
    } else {
        $bot->send(new Message($body->sender, 'Sorry, no camera configured.'));
    }
}

function handleSamCrib($body, $bot) {
    $url = getenv('SAM_CAMERA');
    if (!empty($url)) {
        sendImage($body, $bot, $url, __DIR__.'/sam-images/', true);
    } else {
        $bot->send(new Message($body->sender, 'Sorry, no camera configured.'));
    }
}

function sendImage($body, $bot, $url, $path='/tmp', $preserve=false) {
    $img = $path . time() . ".jpg";
    file_put_contents($img, file_get_contents($url));
    $token = getRequiredEnv('FB_PAGE_TOKEN');
    $curlCommand = "curl  \
      -F 'recipient={\"id\":\"{$body->sender}\"}' \
      -F 'message={\"attachment\":{\"type\":\"image\", \"payload\":{}}}' \
      -F 'filedata=@{$img};type=".mime_content_type($img)."' \
      \"https://graph.facebook.com/v2.6/me/messages?access_token={$token}\" 2>/dev/null";
     `$curlCommand`;
//    print_r($bot->send(new ImageMessage($body->sender, $img)));
    if (!$preserve) {
        unlink($img);
    }
}

function handleGarageStatus($body, $bot) {
    $data = getGarageDoors();
    $msg = sprintf("Garage is %s.", ($data->garage ? "open" : "closed"));
    $bot->send(new Message($body->sender, $msg));
}

function handleGarageOpen($body, $bot) {
    $data = getGarageDoors();
    if (0 == $data->garage) {
        $status = tryCmd('GarageSens', 'openGarage', 1);
        if ('OK' == $status) {
            $bot->send(new Message($body->sender, "Button pressed"));
        }
    } else {
        handleGarageStatus($body, $bot);
    }
}

function handleSumpPumpAck($body, $bot) {
    $status = tryCmd('SumpPump', 'ackAlert');
    if ('OK' == $status) {
        $bot->send(new Message($body->sender, "Done"));
    } else {
        $bot->send(new Message($body->sender, "Didn't work: " . $status));
    }
}

function handleGarageClose($body, $bot) {
    $data = getGarageDoors();
    if (1 == $data->garage) {
        $status = tryCmd('GarageSens', 'closeGarage', 1);
        if ('OK' == $status) {
            $bot->send(new Message($body->sender, "Button pressed"));
        }
    } else {
        handleGarageStatus($body, $bot);
    }
}

function getGarageDoors($retry=1) {
    $data = json_decode(tryCmd('GarageSens', 'getDoors', $retry));
    return $data;
}

function handleWakePc($body, $bot) {
    exec('wakelan 24:4b:fe:e0:db:4f', $out, $retval);
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

function analyzeLeahData() {
    $data = json_decode(file_get_contents('/home/sergey/git-source/leah-in-crib-machine-learning/history.json'), true);
    $moving = false;
    $inBedProb = 0;
    $j=0;
    for ($i=count($data)-1; $i>=0; $i--) {
        // Motion is only relevant for last 5 samples
        if ($j<10 && $data[$i]["motion"] == 1) {
            $moving = true;
        }
        $j++;
        if ($j>=10) {
            break;
        }
    }
    $inBedProb = $inBedProb/$j;
    $last = $data[count($data)-1];
    return (['moving' => $moving, 'inBedProb' => $last['prediction'], 'age' => (time()-$last['time'])]);
}
