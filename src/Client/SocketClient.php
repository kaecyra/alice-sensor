<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Client;

use Alice\Sensor;

use Alice\Socket\SocketMessage;

use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;

use \Exception;

/**
 * ALICE Sensor Socket Client
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 */
abstract class SocketClient {

    const RETRY_DELAY = 15;

    const STATE_OFFLINE = 'offline';
    const STATE_CONNECTING = 'connecting';
    const STATE_CONNECTED = 'connected';
    const STATE_READY = 'ready';

    /**
     * Sensor settings
     * @var array
     */
    protected $settings;

    /**
     * Retry delay
     * @var integer
     */
    protected $retry;

    /**
     * Sensor name
     * @var string
     */
    protected $name;

    /**
     * Sensor id
     * @var string
     */
    protected $id;

    /**
     *
     * @var \Ratchet\Client\WebSocket
     */
    protected $connection;

    /**
     * Connection state
     * @var string
     */
    protected $state;

    public function __construct() {
        $this->settings = Sensor::go()->config()->get('sensor');
        $this->retry = 0;
    }

    /**
     * Run client
     *
     */
    public function run() {
        Sensor::loop()->addPeriodicTimer(1, [$this, 'tick']);
        Sensor::loop()->run();
    }

    /**
     * Connection ticker
     *
     */
    public function tick() {
        // Stay connected
        if (!($this->connection instanceof WebSocket)) {
            if ($this->retry == -1) {
                $this->retry = self::RETRY_DELAY;
                $this->rec("retrying in {$this->retry} sec");
            }

            if (!$this->retry) {
                $this->connect();
            } else {
                $this->retry--;
            }

            return false;
        }

        return true;
    }

    /**
     * Connect and maintain
     *
     *
     */
    public function connect() {
        $server = Sensor::go()->config()->get('server');
        $mode = val('mode', $server);
        $host = val('host', $server);
        $addr = val('address', $server);
        $port = val('port', $server);
        $path = val('path', $server);

        $this->retry = -1;
        $connectionDSN = "{$mode}://{$addr}:{$port}{$path}";
        $this->rec("connecting: {$connectionDSN}");

        $this->state = self::STATE_CONNECTING;

        $connector = new \Ratchet\Client\Connector(Sensor::loop());
        $connector($connectionDSN, [], [
            'Host' => $host
        ])->then([$this, 'connected'], [$this, 'connectFailed']);
    }

    /**
     * Connected to socket server
     *
     * @param WebSocket $connection
     */
    public function connected(WebSocket $connection) {
        $this->rec("connected");

        $this->connection = $connection;
        $this->connection->on('message', [$this, 'onMessage']);
        $this->connection->on('close', [$this, 'onClose']);
        $this->connection->on('error', [$this, 'onError']);

        $this->state = self::STATE_CONNECTED;

        $this->registerClient();
    }

    /**
     * Handle connection failure
     *
     * @param Exception $ex
     */
    public function connectFailed(Exception $ex) {
        $this->rec("could not connect: {$ex->getMessage()}");
        $this->offline();
    }

    /**
     * Client offline
     *
     */
    public function offline() {
        $this->connection = null;
        $this->state = self::STATE_OFFLINE;
    }

    /**
     * Test if client is ready
     * 
     * @return boolean
     */
    public function isReady() {
        return $this->state == self::STATE_READY;
    }

    /**
     * Send a message to the server
     *
     * @param string $method
     * @param mixed $data
     */
    public function sendMessage($method, $data = null) {
        $this->rec("send message: {$method}");
        $message = SocketMessage::compile($method, $data);
        $this->connection->send($message);
    }

    /**
     * Handle receiving socket message
     *
     * @param MessageInterface $msg
     */
    public function onMessage(MessageInterface $msg) {
        $this->rec("message received");

        try {
            $message = SocketMessage::parse($msg);

            $this->rec("received message: ".$message->getMethod());

            // Route to message handler
            $call = 'message_'.$message->getMethod();
            if (is_callable([$this, $call])) {
                $this->$call($message);
            } else {
                $this->rec(sprintf(" could not handle message: unknown type '{%s}'", $message->getMethod()));
            }
        } catch (\Exception $ex) {
            $this->rec("msg handling error: ".$ex->getMessage());
            return false;
        }
    }

    /**
     *
     * @param SocketMessage $message
     */
    public function message_registered(SocketMessage $message) {
        $this->rec("registered");
        $this->state = self::STATE_READY;
    }

    /**
     * Handle connection closing
     *
     * @param integer $code
     * @param string $reason
     */
    public function onClose($code = null, $reason = null) {
        $this->rec("connection closed ({$code} - {$reason})");
        $this->offline();
    }

    /**
     *
     * @param string $reason
     * @param WebSocket $connection
     */
    public function onError($reason, WebSocket $connection) {
        $this->rec("socket error: {$reason}");
    }

    /**
     * Register client with server
     *
     *
     */
    abstract public function registerClient();

    /**
     * Record socket mesage
     *
     * @param mixed $message
     * @param integer $level
     * @param integer $options
     */
    public function rec($message, $level = \Alice\Daemon\Daemon::LOG_L_APP, $options = \Alice\Daemon\Daemon::LOG_O_SHOWTIME) {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        rec("[socket] ".$message, $level, $options);
    }

}