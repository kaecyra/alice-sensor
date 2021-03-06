<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Sensor;

use Alice\Sensor;

use Alice\Socket\SocketClient;
use Alice\Socket\SocketMessage;

use PhpGpio\Gpio;

/**
 * ALICE Sensor Client
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 */
class MotionClient extends SocketClient {

    const SENSOR_MOTION = 'motion';
    const SENSOR_STILL = 'still';

    /**
     * How often to send unchanged sensor data
     */
    const SENSOR_DUPLICATE_UPDATE = 10;

    /**
     * GPIO pin
     * @var integer
     */
    protected $pin;

    /**
     * GPIO
     * @var PhpGpio\Gpio
     */
    protected $gpio;

    /**
     * Last sensor value
     * @var string
     */
    protected $last;

    /**
     * Last sensor update sent
     * @var integer
     */
    protected $lastUpdate;

    /**
     * Construct
     *
     */
    public function __construct($settings) {
        parent::__construct();
        $this->settings = $settings;
        $this->server = Sensor::go()->config()->get('server');
        $this->prepareGPIO();
    }

    /**
     * Prepare GPIO
     *
     * Register GPIO pin directions and calibrate if needed
     */
    public function prepareGPIO() {
        $this->gpio = new Gpio();
        $this->gpio->unexportAll();

        // Wire up pin
        $this->pin = valr('settings.gpio.pin', $this->settings);
        $this->gpio->setup($this->pin, "in");
    }

    /**
     * Register sensor
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
        $connected = parent::tick();
        if (!$connected) {
            return;
        }

        if (!$this->isReady()) {
            return;
        }

        $this->sense();
    }

    /**
     * Sense Motion
     *
     */
    public function sense() {
        $sense = (boolean)trim($this->gpio->input($this->pin));
        $sensorState = $sense ? self::SENSOR_MOTION : self::SENSOR_STILL;

        if ($sensorState != $this->last || (time() - $this->lastUpdate) >= self::SENSOR_DUPLICATE_UPDATE) {
            $this->lastUpdate = time();
            if ($sensorState != $this->last) {
                $this->rec("sensed new state: {$sensorState}");
                $this->last = $sensorState;
            }
            $this->sendMessage('sensor', [
                "sensor" => $sensorState,
                "extra" => [
                    "display" => ($this->isAwake()) ? 'on' : 'off'
                ]
            ]);
        }
    }

    /**
     * Handle screen hibernate message
     *
     * @param SocketMessage $message
     */
    public function message_hibernate(SocketMessage $message) {
        if ($this->isAwake()) {
            $this->rec("hibernating screen");
            exec('/usr/bin/tvservice -o');

            // Update display mode on client
            $this->sendMessage('sensor', [
                "extra" => [
                    "display" => 'off'
                ]
            ]);
        }
    }

    /**
     * Handle screen unhibernate message
     *
     * @param SocketMessage $message
     */
    public function message_unhibernate(SocketMessage $message) {
        if (!$this->isAwake()) {
            $this->rec("unhibernating screen");
            exec('/usr/bin/tvservice -p');

            // Update display mode on client
            $this->sendMessage('sensor', [
                "extra" => [
                    "display" => 'on'
                ]
            ]);
        }
    }

    /**
     * Test if display is off
     *
     * @return boolean
     */
    public function isAwake() {
        exec('/usr/bin/tvservice -s', $out);
        $out = strtolower(trim(implode('', $out)));
        if (preg_match('`tv is off`', $out)) {
            return false;
        }
        return true;
    }

}