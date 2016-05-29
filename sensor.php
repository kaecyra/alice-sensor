#!/usr/bin/env php
<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Alice;

/**
 * sensor.php is a websocket client for the ALICE system.
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package alice-sensor
 * @version 2.0
 */

use \Alice\Daemon\Daemon;

chdir(dirname(__FILE__));
define('APP', 'alice-sensor');
define('APP_ROOT', getcwd());
date_default_timezone_set('UTC');

// Reflect on ourselves for the version
$matched = preg_match('`@version ([\w\d\.-]+)$`im', file_get_contents(__FILE__), $matches);
if (!$matched) {
    echo "Unable to read version\n";
    exit;
}
$version = $matches[1];
define('APP_VERSION', $version);

// Make sure we're root
if (posix_getuid() != 0) {
    echo "Must be root.\n";
    exit;
}

// Include the core autoloader.
$autoloader = __DIR__.'/vendor/autoload.php';
if (!file_exists($autoloader)) {
    echo "Generating autoloader...\n";
    $compose = "sudo composer install --no-dev --prefer-dist --optimize-autoloader";
    exec($compose);

    if (!file_exists($autoloader)) {
        echo "Unable to automatically generate autoloader.\n";
        echo " Try: {$compose}\n";
        exit;
    }
}
require_once $autoloader;

$exitCode = 0;
try {

    Daemon::configure([
        'appVersion'        => APP_VERSION,
        'appDir'            => APP_ROOT,
        'appDescription'    => 'ALICE websocket server.',
        'appNamespace'      => 'Alice',
        'appName'           => 'Sensor',
        'authorName'        => 'Tim Gunter',
        'authorEmail'       => 'tim@vanillaforums.com',
        'appConcurrent'     => false,
        'appLogLevel'       => Daemon::LOG_L_ALL,
        'appLogFile'        => 'log/sensor.log',
        //'sysRunAsGroup'     => 'root',
        //'sysRunAsUser'      => 'root',
        'sysMode'           => Daemon::MODE_SINGLE,
        'sysDaemonize'      => false
    ]);

    $exitCode = Daemon::start($argv);

} catch (\Alice\Daemon\Exception $ex) {

    $exceptionCode = $ex->getCode();
    if ($exceptionCode != 200) {

        // Don't write to file for commandline errors
        if ($exceptionCode == 600) {
            $msgOptions |= Daemon::LOG_O_NOWRITE;
        }

        if ($ex->getFile()) {
            $line = $ex->getLine();
            $file = $ex->getFile();
            Daemon::log(Daemon::LOG_L_FATAL, "Error on line {$line} of {$file}:", $msgOptions);
        }
        Daemon::log(Daemon::LOG_L_FATAL, $ex->getMessage(), $msgOptions);
    }

} catch (\Exception $ex) {
    $exitCode = 1;
    $msgOptions = 0;

    if ($ex->getFile()) {
        $line = $ex->getLine();
        $file = $ex->getFile();
        Daemon::log(Daemon::LOG_L_FATAL, "Error on line {$line} of {$file}:", $msgOptions);
    }
    Daemon::log(Daemon::LOG_L_FATAL, $ex->getMessage(), $msgOptions);
}

exit($exitCode);
