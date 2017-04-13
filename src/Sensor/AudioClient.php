<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Sensor;

use Alice\Sensor;

use Alice\Socket\SocketClient;
use Alice\Socket\SocketMessage;

use Alice\Service\API\Watson;

use \ZMQ;

/**
 * ALICE Input Client
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 */
class AudioClient extends SocketClient {

    const FORMAT = 'wav';
    const MIMETYPE = 'audio/wav';

    const WATCHPIDFILE = '/tmp/audioinput-watch-%d.pid';

    /**
     * Sound asset path
     * @var string
     */
    protected $assetPath;

    /**
     * ZeroMQ context
     * @var \ZMQContext
     */
    protected $zmqcontext;

    /**
     * ZeroMQ data socket
     * @var \ZMQSocket
     */
    protected $zero;

    /**
     * ZeroMQ sync socket
     * @var \ZMQSocket
     */
    protected $zerosync;

    /**
     * Are we currently listening?
     * @var boolean
     */
    protected $listening;

    /**
     *
     * @var \SPLQueue
     */
    protected $queue;

    /**
     * Construct
     *
     */
    public function __construct($settings) {
        parent::__construct();
        $this->settings = $settings;
        $this->server = Sensor::go()->config()->get('server');
        $this->assetPath = paths(\Alice\Daemon\Daemon::option('appDir'), 'assets');

        $this->queue = new \SplQueue();
        $this->listening = false;
        $this->tickFreq = 0.5;

        // Bind command receipt socket
        try {
            $this->rec("binding zero socket");
            $this->zmqcontext = new \React\ZMQ\Context(Sensor::loop());

            $zmqConfig = Sensor::go()->config()->get('zero');

            $zmqDataDSN = "tcp://{$zmqConfig['host']}:{$zmqConfig['port']}";
            $this->rec(" data dsn: {$zmqDataDSN}");

            $zmqSyncDSN = "tcp://{$zmqConfig['host']}:{$zmqConfig['syncport']}";
            $this->rec(" sync dsn: {$zmqSyncDSN}");

            // Bind receive socket
            $this->zero = $this->zmqcontext->getSocket(ZMQ::SOCKET_SUB);
            $this->zero->bind($zmqDataDSN);
            $this->zero->subscribe('input-keyword');
            $this->zero->on('messages', [$this, 'getMessage']);

            // Bind sync socket
            $this->zerosync = $this->zmqcontext->getSocket(ZMQ::SOCKET_REP);
            $this->zerosync->bind($zmqSyncDSN);
            $this->zerosync->on('message', [$this, 'syncMessage']);

            $this->zero->on('error', function ($e) {
                $this->rec($e);
            });

        } catch (Exception $ex) {
            $this->rec(print_r($ex, true));
        }

        // Bind watchword recycler
        pcntl_signal(SIGUSR1, [$this, 'signal']);
        $this->watch();
    }

    /**
     * Register output client
     *
     */
    public function registerClient() {
        $this->rec("registering client");
        $this->sendMessage('register', $this->settings);
    }

    /**
     * Tick
     *
     */
    public function tick() {
        // Stay connected
        $connected = parent::tick();
        if (!$connected) {
            return;
        }

        // Don't do anything if we're not connected
        if (!$this->isReady()) {
            return;
        }

        // Handle signals
        pcntl_signal_dispatch();

        // Do our stuff here
        if ($this->queue->count()) {
            $task = $this->queue->pop();
            $action = $task[0];
            $payload = val(1, $task, []);

            $method = "action_{$action}";
            if (method_exists($this, $method)) {
                $this->$method($payload);
            }
        }

    }

    /**
     * Inbound ZMQ sync message
     *
     * @param string $message
     */
    public function syncMessage($message) {
        $this->zerosync->send("synced");
    }

    /**
     * Inbound ZMQ message
     *
     * @param string|array $message
     */
    public function getMessage($message, $topic = null) {
        if (is_array($message)) {
            $topic = $message[0];
            $message = $message[1];
        }

        if (is_string($message)) {
            $decoded = json_decode($message, true);
            if (!is_array($decoded)) {
                $message = [
                    'message' => $message
                ];
            } else {
                $message = $decoded;
            }
        }

        if (is_null($topic)) {
            $topic = 'unknown';
        }

        switch ($topic) {
            case 'input-keyword':
                $this->listen();
                break;

            default:
                $this->rec("received message [{$topic}]:");
                $this->rec($message);
                break;
        }
    }

    /**
     * Handle listen commands
     *
     * Propagate a session ID so that commands can be chained
     */
    public function message_listen(SocketMessage $message) {
        $event = $message->getData();
        $sessionID = val('session', $event);
        $listening = $this->listen($sessionID);
    }

    /**
     * Handle signals
     *
     * @param integer $signal
     */
    public function signal($signal) {
        switch ($signal) {
            case SIGUSR1:
                $this->rec('restarting keyphrase sentinel');
                $this->watch();
                break;
        }
    }

    /**
     * Watch for keyphrase
     *
     */
    public function watch() {
        $keyphrase = valr('settings.keyphrase', $this->settings);
        $pid = posix_getpid();
        $watchPidFile = sprintf(self::WATCHPIDFILE, $pid);

        $dir = \Alice\Daemon\Daemon::option('appDir');
        $script = paths($dir, 'scripts/audio-watch.sh');
        $command = [];

        // Don't receive hangups
        $command[] = 'nohup';

        // Command
        $command[] = $script;
        $command[] = $keyphrase;
        $command[] = $pid;

        // Redirect and send into background
        $command[] = "> /dev/null 2>&1 &";
        $command[] = "echo $! > {$watchPidFile}";

        $execCommand = implode(' ', $command);
        exec($execCommand);
        return true;
    }

    /**
     * Begin listening cycle
     *
     * @param string $sessionID command chain session ID
     */
    public function listen($sessionID = null) {
        if ($this->listening) {
            $this->rec('already listening!');
            return false;
        }
        $this->listening = true;

        $this->rec('listening');

        $this->queue('normalize', [
            'session' => $sessionID
        ]);
        return true;
    }

    /**
     * Add job to queue
     *
     * @param string $action
     * @param array $payload
     */
    public function queue($action, $payload = []) {
        $this->queue->push([$action, $payload]);
    }

    /**
     * Detect ambient noise level
     *
     * @param array $payload
     *      session: string|null
     */
    public function action_normalize($payload) {
        $this->rec(' measure ambient noise');

        $sessionID = val('session', $payload, null);

        // Sense audio level
        // rec -n stat trim 0 .25 2>&1
        $output = [];
        exec('rec -n stat trim 0 .25 2>&1', $output);
        $stat = [];
        foreach ($output as $outline) {
            $matches = [];
            if (preg_match('`^([\w \(\)]+):\s+([\w\d \.-]+)$`', $outline, $matches)) {
                $label = strtolower($matches[1]);
                $label = str_replace(' ','',trim($label));
                $stat[$label] = strtolower($matches[2]);
            }
        }
        $meanRMS = $stat['rmsamplitude'];
        $detectedSilencePercent = round(($meanRMS / 1) * 100, 0);
        $silencePadding = valr('settings.record.silence.pad', $this->settings, 5);
        $silencePercent = $detectedSilencePercent + $silencePadding;
        $this->rec(" silence at: {$silencePercent}% (boosted by {$silencePadding})");

        $this->queue('cue', [
            'session' => $sessionID,
            'floor' => $silencePercent
        ]);
    }

    /**
     * Send cue to ALICE
     *
     * @param array $payload
     *      session: string|null
     *      floor: integer
     */
    public function action_cue($payload) {
        $this->rec(' send wake cue');

        $sessionID = val('session', $payload, null);

        // Acknowledge keyphrase
        $this->sendMessage('event', [
            'session' => $sessionID,
            'type' => 'cue'
        ]);

        $this->queue('listen', $payload);
    }

    /**
     * Listen and record audio
     *
     * @param array $payload
     *      session: string|null
     *      floor: integer
     */
    public function action_listen($payload) {
        $this->rec(' listening for audio');

        $sessionID = val('session', $payload, null);

        // Record audio
        $recRate = valr('settings.record.rate', $this->settings, '16k');
        $recSilenceTop = valr('settings.record.silence.top', $this->settings, '0.1');
        $recSilenceBottom = valr('settings.record.silence.bottom', $this->settings, '2.0');

        $silencePercent = $payload['floor'];

        // rec /tmp/recording.flac rate 16k silence 1 0.1 3% 1 3.0 3%
        $recID = uniqid('record');
        $recPath = "/tmp/{$recID}.".self::FORMAT;
        @unlink($recPath);
        $command = ['rec'];
        $command[] = $recPath;
        $command[] = "rate {$recRate}";
        $aboveSilence = "1 {$recSilenceTop} {$silencePercent}%";
        $belowSilence = "1 {$recSilenceBottom} {$silencePercent}%";
        $command[] = "silence {$aboveSilence} {$belowSilence}";
        $execCommand = implode(' ', $command);
        $this->rec(" rec: {$execCommand}");

        $recStart = microtime(true);
        $output = [];
        exec($execCommand, $output);
        $recElapsed = microtime(true) - $recStart;
        $recSec = round($recElapsed, 3);
        $this->rec(" recorded for {$recSec} sec");

        $this->listening = false;

        // Send uncue to ALICE
        $this->sendMessage('event', [
            'session' => $sessionID,
            'type' => 'uncue'
        ]);

        $this->queue('recognize', [
            'session' => $sessionID,
            'path' => $recPath
        ]);
    }

    /**
     * Recognize audio
     *
     * @param array $payload
     *      session: string|null
     *      path: string
     */
    public function action_recognize($payload) {
        $this->rec(" recognizing audio");

        $sessionID = val('session', $payload, null);

        // Test file
        $recPath = $payload['path'];
        if (!file_exists($recPath)) {
            $this->rec("  bad file");
            $this->queue('command', [
                'session' => $sessionID,
                'phrase' => null
            ]);
            return;
        }

        // Recognize
        $watson = new Watson(Sensor::go()->config());
        $output = $watson->recognize($recPath, self::MIMETYPE);
        @unlink($recPath);

        $results = val('results', $output);
        if (!count($results)) {
            $this->rec("  unable to recognize");
            $this->queue('command', [
                'session' => $sessionID,
                'phrase' => null
            ]);
            return;
        }

        $index = val('result_index', $output);
        $result = $results[$index];
        $final = valr('alternatives.0', $result);

        $transcript = $final['transcript'];
        $confidence = $final['confidence'];
        $confidencePercent = round($confidence * 100,0);

        $this->rec("  recognized ({$confidencePercent}%): {$transcript}");

        $this->queue('command', [
            'session' => $sessionID,
            'phrase' => $transcript,
            'confidence' => $confidence
        ]);
    }

    /**
     * Send recognized phrase to ALICE
     *
     * @param array $payload
     *      session: string|null
     *      phrase: string|null
     *      confidence: float|null
     */
    public function action_command($payload) {
        $phrase = $payload['phrase'];
        $sessionID = $payload['session'];

        // Send STT result
        if ($phrase) {
            $confidence = val('confidence', $payload, 0);
            $this->sendMessage('event', [
                'type' => 'command',
                'session' => $sessionID,
                'phrase' => $phrase,
                'confidence' => $confidence
            ]);
        } else {
            $this->sendMessage('event', [
                'type' => 'command',
                'session' => $sessionID,
                'phrase' => null
            ]);
        }
    }

    /**
     * Handle daemon shutdown
     *
     */
    public function shutdown() {
        $pid = posix_getpid();
        $watchPidFile = sprintf(self::WATCHPIDFILE,$pid);
        $watchPid = trim(file_get_contents($watchPidFile));

        // Kill forked watcher
        posix_kill($watchPid, SIGKILL);

        // Kill PocketShinx
        exec('sudo pkill -9 pocketsphinx_continuous');

        // Kill audio-keyword.php
        exec('sudo pkill -9 audio-keyword.php');
    }

}