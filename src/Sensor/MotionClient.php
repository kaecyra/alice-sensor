<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice\Sensor;

use Alice\Sensor;

use Alice\Client\SocketClient;

use PhpGpio\Gpio;

/**
 * ALICE Sensor Socket Client
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
    public function __construct() {
        parent::__construct();
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
        $sensor = Sensor::go()->config()->get('sensor');
        $this->sendMessage('register', $sensor);
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
            $this->sendMessage('sensor', $sensorState);
        }
    }

}