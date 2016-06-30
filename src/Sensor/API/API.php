<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Sensor\API;

use Alice\Sensor;

use Garden\Http\HttpClient;

/**
 * ALICE API Base
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 */
abstract class API {

    /**
     * API client config
     * @var array
     */
    protected $clientconfig;

    /**
     * API settings
     * @var array
     */
    protected $settings;

    /**
     *
     */
    public function __construct($settings = []) {
        $this->clientconfig = Sensor::go()->config()->get('api.client');
        $this->settings = $settings;
    }

    /**
     * Get API client instance
     *
     * @return Garden\Http\HttpClient
     */
    protected function getClient() {
        $client = new HttpClient();

        $userAgent = val('useragent', $this->clientconfig, 'kaecyra/alice-server');
        if ($userAgent) {
            $userAgent .= '/'.APP_VERSION;
            $client->setDefaultHeader('User-Agent', $userAgent);
        }

        return $client;
    }

    /**
     * Record API specific message
     *
     * @param mixed $message
     * @param integer $level
     * @param integer $options
     */
    public static function rec($message, $level = \Alice\Daemon\Daemon::LOG_L_APP, $options = \Alice\Daemon\Daemon::LOG_O_SHOWTIME) {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        rec(sprintf("[api: %s] %s", static::API, $message), $level, $options);
    }

}