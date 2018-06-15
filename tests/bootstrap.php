<?php
use CrowdStar\SVNAgent\Config;

use Psr\Log\NullLogger;

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

Config::singleton()
    ->setLogger(new NullLogger())
    ->init(dirname(__DIR__), '.env.example');

// Allow to use customized Subversion for testing purpose.
if (!empty($_ENV['SVN_PATH'])) {
    putenv("PATH={$_ENV['SVN_PATH']}:{$_SERVER['PATH']}");
}
