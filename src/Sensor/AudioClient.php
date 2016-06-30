<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Sensor;

use Alice\Sensor;

use Alice\Socket\SocketClient;
use Alice\Socket\SocketMessage;

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
        $this->rec("received zerosync: {$message}");
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

        $this->rec("received zeromessage: {$topic}");
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
     * Begin listening cycle
     *
     */
    public function listen() {
        if ($this->listening) {
            $this->rec('already listening!');
            return;
        }
        $this->listening = true;

        $this->rec('listening');

        $this->queue('normalize');
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
     */
    public function action_normalize($payload) {
        // Sense audio level
        // rec -n stat trim 0 .25 2>&1
        $this->rec(' measure ambient noise');
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
            'floor' => $silencePercent
        ]);
    }

    /**
     * Send cue to ALICE
     *
     * @param array $payload
     */
    public function action_cue($payload) {
        // Acknowledge keyphrase
        $this->rec(' send wake cue');
        $this->sendMessage('event', [
            'type' => 'cue'
        ]);

        $this->queue('listen', $payload);
    }

    /**
     * Listen and record audio
     *
     * @param array $payload
     */
    public function action_listen($payload) {

        // TEMPORARY

        $f = '/tmp/record57748e4581566.wav';

        $this->listening = false;

        // Send uncue to ALICE
        $this->sendMessage('event', [
            'type' => 'uncue'
        ]);

        $this->queue('recognize', [
            'path' => $f
        ]);

        return;

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

        exec("play {$recPath}");

        $this->listening = false;

        // Send uncue to ALICE
        $this->sendMessage('event', [
            'type' => 'uncue'
        ]);

        $this->queue('recognize', [
            'path' => $recPath
        ]);
    }

    /**
     * Recognize audio
     *
     * @param array $payload
     */
    public function action_recognize($payload) {
        // Recognize audio
        $recPath = $payload['path'];
        //@unlink($recPath);

        $watson = new API\Watson();
        $output = $watson->recognize($recPath, self::MIMETYPE);

        $results = val('results', $output);
        if (!count($results)) {
            $this->queue('command', [
                'phrase' => null
            ]);
        }

        $index = val('result_index', $output);
        $result = $results[$index];
        $final = valr('alternatives.0', $result);

        $transcript = $final['transcript'];
        $confidence = $final['confidence'];
        $confidencePercent = round($confidence * 100,0);

        $this->rec("recognized ({$confidencePercent}%): {$transcript}");

        $this->queue('command', [
            'phrase' => $transcript,
            'confidence' => $confidence
        ]);
    }

    /**
     * Send recognized phrase to ALICE
     *
     * @param array $payload
     */
    public function action_command($payload) {
        $phrase = $payload['phrase'];

        // Send STT result
        if ($phrase) {
            $confidence = val('confidence', $payload, 0);
            $this->sendMessage('event', [
                'type' => 'command',
                'phrase' => $phrase,
                'confidence' => $confidence
            ]);
        } else {
            $this->sendMessage('event', [
                'type' => 'command',
                'phrase' => null
            ]);
        }
    }

}