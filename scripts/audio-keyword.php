#!/usr/bin/env php
<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice;

use Alice\Common\Config;

use \ZMQ;
use \ZMQContext;

/**
 * ALICE Audio Keyword Detector
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 */

// Include the core autoloader.
$autoloader = __DIR__.'/../vendor/autoload.php';
require_once $autoloader;

// Read configuration
$config = Config::file(paths(__DIR__, '/../conf/config.json'), true);

while ($keyword = fgets(STDIN)) {

    $keyword = trim($keyword);
    if (!strlen($keyword)) {
        continue;
    }

    echo "INPUT:\n";
    echo "> {$keyword}\n";

    $arguments = $argv;
    array_shift($arguments);
    $keyword = implode(' ', $arguments);
    $message = [
        'time' => time(),
        'keyword' => $arguments
    ];

    try {

        $zmqConfig = $config->get('zero');

        $context = new ZMQContext();

        // Publisher socket
        $zmqDataDSN = "tcp://{$zmqConfig['host']}:{$zmqConfig['port']}";
        $dataSocket = $context->getSocket(ZMQ::SOCKET_PUB);
        $dataSocket->connect($zmqDataDSN);

        // Synchronize socket
        $zmqSyncDSN = "tcp://{$zmqConfig['host']}:{$zmqConfig['syncport']}";
        $syncSocket = $context->getSocket(ZMQ::SOCKET_REQ);
        $syncSocket->connect($zmqSyncDSN);
        $syncSocket->send('sync');
        $syncSocket->recv();

        // Send message
        $update = json_encode($message);
        $dataSocket->sendMulti(['input-keyword', $update]);

    } catch (Exception $ex) {

        echo "TRANSMIT ERROR:\n";
        print_r($ex);
        continue;

    }

}

exit(0);