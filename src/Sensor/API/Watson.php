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
class Watson extends API {

    const API = 'watson';

    const RECOGNIZE = 'speech-to-text/api/v1/recognize';
    const SYNTHESIZE = 'text-to-speech/api/v1/synthesize';

    /**
     * API Base URL
     * @var string
     */
    protected $baseURL = 'https://stream.watsonplatform.net/';

    /**
     * Instantiate Watson API
     *
     * @param array $settings
     */
    public function __construct($settings = null) {
        if (!$settings) {
            $settings = Sensor::go()->config()->get('api.watson');
        }
        parent::__construct($settings);

        $this->rec($this->settings);
    }

    /**
     * Prepare HttpClient for making requests
     *
     * @param HttpClient $client
     */
    protected function authenticate(HttpClient $client) {
        $username = valr('api.username', $this->settings);
        $password = valr('api.password', $this->settings);
        $client->setDefaultOption('auth', [$username, $password]);
    }

    /**
     * Recognize text from audio
     *
     * @param string $file path to audio file
     * @param string $type audio file type
     * @param array $options optional options
     * @return array
     */
    public function recognize($file, $type, $options = []) {
        $client = $this->getClient();
        $this->authenticate($client);

        $file = new \CURLFile($file, $type, basename($file));

        $meta = [
            'data_parts_count' => 1,
            'part_content_type' => $type,
            'continuous' => false,
            'smart_formatting' => true,
            'profanity_filter' => false,
        ];

        $meta = json_encode($meta);

        // Prepare request
        $url = paths($this->baseURL, self::RECOGNIZE);
        $request = $client->createRequest('POST', $url, [
            'metadata' => $meta,
            'upload' => $file
        ]);

        $request->addHeader('Content-Type', 'multipart/form-data');
        $response = $request->send();
        if (!$response->isResponseClass('200')) {
            $this->rec($response);
            return false;
        }

        $event = $response->getBody();
        return $event;
    }

    /**
     * Synthesize audio from text
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function synthesize($text, $options = []) {
        $client = $this->getClient();
        $this->authenticate($client);

    }
}