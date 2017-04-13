<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice;

use Garden\Cli\Cli;

use Alice\Daemon\App;
use Alice\Daemon\Daemon;

use Alice\Common\Config;
use Alice\Common\Event;

use Alice\Sensor\MotionClient;
use Alice\Sensor\AudioClient;

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
     * List of sensors
     * @var array
     */
    protected $sensors;

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

        // Read sensor list
        $this->sensors = [];
        $sensors = $this->config->get('sensors');
        foreach ($sensors as $sensor) {
            $id = $sensor['id'];
            $this->sensors[$id] = $sensor;
        }
    }

    /**
     * Extend CLI
     *
     * @param Cli $cli
     */
    public static function commands($cli) {
        $cli->command('start')
            ->opt('id', "Sensor ID", true, 'string');

        $cli->command('restart')
            ->opt('id', "Sensor ID", true, 'string');
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
     * Get config reference
     *
     * @return \Alice\Common\Config
     */
    public function config() {
        return $this->config;
    }

    /**
     * Execute main app payload
     *
     * @return string
     */
    public function run() {

        rec(' startup');

        // Lookup sensor
        $args = Daemon::getArgs();
        $id = $args->getOpt('id');

        if (!array_key_exists($id, $this->sensors)) {
            rec("  no such sensor: {$id}");
            return Daemon::APP_EXIT_EXIT;
        }
        $sensor = $this->sensors[$id];
        rec("  sensor: {$id} ({$sensor['name']})");

        // Adjust daemon log file
        Daemon::openLog("log/{$id}.log");

        // Startup again
        rec(' startup');

        // Start the loop
        self::$loop = LoopFactory::create();

        Event::fire('startup');

        rec(' starting client');

        // Start client

        $connectionRetry = $this->config->get('server.retry.delay');

        // Run the server application
        switch ($sensor['type']) {
            case 'motion':
                $this->client = new MotionClient($sensor);
                break;

            case 'audio':
                $this->client = new AudioClient($sensor);
                break;

            default:
                rec("  unsupported sensor type: {$sensor['type']}");
                return Daemon::APP_EXIT_EXIT;
                break;
        }

        $ran = $this->client->run(self::$loop, $connectionRetry);

        rec(' client closed');
        rec($ran);
    }

    /**
     * Daemon shutting down
     *
     */
    public function shutdown() {
        rec(' shutting down');

        // Gracefully shutdown client
        if (is_callable([$this->client, 'shutdown'])) {
            $this->client->shutdown();
        }
    }

}