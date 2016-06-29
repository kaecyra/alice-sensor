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
     * Construct
     *
     */
    public function __construct($settings) {
        parent::__construct();
        $this->settings = $settings;
        $this->server = Sensor::go()->config()->get('server');
        $this->assetPath = paths(\Alice\Daemon\Daemon::option('appDir'), 'assets');

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
            $this->zero->subscribe('sms-message');
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
        $this->rec("received sync: {$message}");
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


    public function listen() {

    }

    /**
     * Receive record
     *
     * @param SocketMessage $message
     */
    public function message_record(SocketMessage $message) {

    }

}