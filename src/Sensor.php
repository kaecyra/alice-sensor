<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice;

use Alice\Daemon\App;
use Alice\Daemon\Daemon;

use Alice\Common\Config;
use Alice\Common\Event;

use Alice\Sensor\MotionClient;

use React\EventLoop\Factory as LoopFactory;

/**
 * ALICE Sensor Daemon
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 */
class Sensor implements App {

    /**
     * ALICE Sensor config
     * @var \Alice\Common\Config
     */
    protected $config;

    /**
     * Socket Client
     * @var \Alice\Socket\SocketClient
     */
    protected $client;

    /**
     * Loop
     * @var \React\EventLoop\LoopInterface
     */
    static $loop;

    /**
     * Alice
     * @var \Alice\Alice
     */
    static $sensor = null;

    public function __construct() {
        rec(sprintf("%s (v%s)", APP, APP_VERSION), Daemon::LOG_L_APP, Daemon::LOG_O_SHOWTIME);
        self::$sensor = $this;

        $appDir = Daemon::option('appDir');

        // Config
        rec(' reading config');
        $this->config = Config::file(paths($appDir, 'conf/config.json'), true);
    }

    /**
     * Get ALICE Sensor reference
     *
     * @return \Alice\Sensor
     */
    public static function go() {
        return self::$sensor;
    }

    /**
     * Get loop reference
     *
     * @return \React\EventLoop\LoopInterface
     */
    public static function loop() {
        return self::$loop;
    }

    /**
     * Execute main app payload
     *
     * @return string
     */
    public function run() {

        rec(' startup');

        // Start the loop
        self::$loop = LoopFactory::create();

        Event::fire('startup');

        rec(' starting client');

        // Start client

        $connectionRetry = $this->config->get('server.retry.delay');

        // Run the server application
        $this->client = new MotionClient();
        $ran = $this->client->run(self::$loop, $connectionRetry);

        rec(' client closed');
        rec($ran);
    }

    /**
     *
     * @return \Alice\Common\Config
     */
    public function config() {
        return $this->config;
    }
}