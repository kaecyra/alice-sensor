<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Audio;

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
     * Construct
     *
     */
    public function __construct($settings) {
        parent::__construct();
        $this->settings = $settings;
        $this->server = Sensor::go()->config()->get('server');
        $this->assetPath = paths(\Alice\Daemon\Daemon::option('appDir'), 'assets');

        $this->listening = false;

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
     * Listen for audio command
     *
     */
    public function listen() {
        if ($this->listening) {
            $this->rec('already listening!');
            return;
        }
        $this->listening = true;

        $this->rec('listening');

        // Acknowledge keyphrase
        $this->rec(' send wake cue');
        $this->sendMessage('event', [
            'type' => 'cue'
        ]);

        // Sense audio level
        // rec -n stat trim 0 .25 2>&1
        $this->rec(' measure ambient noise');
        exec('rec -n stat trim 0 .25 2>&1', $output);
        $stat = [];
        foreach ($output as $outline) {
            if (preg_match('`^([\w \(\)]+):\s+([\w\d \.-]+)$`', $outline, $matches)) {
                $label = strtolower($matches[1]);
                $label = str_replace(' ','',trim($label));
                $stat[$label] = strtolower($matches[2]);
            }
        }
        $meanRMS = $stat['rmsamplitude'];
        $detectedSilencePercent = round(($meanRMS / 1) * 100, 0);
        $silencePadding = valr('record.silence.pad', $this->settings, 5);
        $silencePercent = $detectedSilencePercent + $silencePadding;
        $this->rec(" silence at: {$silencePercent}%");

        // Record audio
        $recRate = valr('record.rate', $this->settings, '16k');
        $recSilenceTop = valr('record.silence.top', $this->settings, '0.1');
        $recSilenceBottom = valr('record.silence.top', $this->settings, '2.0');

        // rec /tmp/recording.flac rate 16k silence 1 0.1 3% 1 3.0 3%
        $recID = uniqid('record');
        $recPath = "/tmp/{$recID}.flac";
        unlink($recPath);
        $command = ['rec'];
        $command[] = $recPath;
        $command[] = "rate {$recRate}";
        $command[] = "silence 1 {$recSilenceTop} {$silencePercent}% 1 {$recSilenceBottom} {$silencePercent}%";
        $execCommand = implode(' ', $command);
        exec($execCommand, $output);

        // Recognize audio
        $this->sendMessage('event', [
            'type' => 'uncue'
        ]);

        $phrase = 'test phrase';

        // Send STT result
        if ($phrase) {
            $this->sendMessage('event', [
                'type' => 'command',
                'phrase' => 'test phrase'
            ]);
        } else {
            $this->sendMessage('event', [
                'type' => 'command',
                'phrase' => null
            ]);
        }

        $this->listening = false;
    }

}