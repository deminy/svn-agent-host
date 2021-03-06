<?php
/**************************************************************************
 * Copyright 2018 Glu Mobile Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *************************************************************************/

namespace CrowdStar\Tests\SVNAgent;

use CrowdStar\SVNAgent\Actions\AbstractPathBasedAction;
use CrowdStar\SVNAgent\Actions\DummyPathBasedAction;
use CrowdStar\SVNAgent\Actions\Update;
use CrowdStar\SVNAgent\Config;
use CrowdStar\SVNAgent\Exceptions\ClientException;
use CrowdStar\SVNAgent\Request;
use CrowdStar\SVNAgent\SVNHelper;
use MrRio\ShellWrap;

/**
 * Class AbstractSvnTestCase
 *
 * @package CrowdStar\Tests\SVNAgent
 */
abstract class AbstractSvnTestCase extends TestCase
{
    /**
     * To store original value of environment variable Config::SVN_AGENT_SVN_ROOT.
     * @var string
     */
    protected static $svnRoot;

    /**
     * @return void
     * @see AbstractSvnTestCase::resetSvnHost()
     */
    public static function setUpInvalidSvnHost()
    {
        self::$svnRoot = getenv(Config::SVN_AGENT_SVN_ROOT, '');
        // Change SVN root from "http://example.com/svn/project1" to "http://example.com" (domain name may vary here).
        putenv(Config::SVN_AGENT_SVN_ROOT . '=' . dirname(Config::singleton()->getSvnRoot(), 2));
    }

    /**
     * @return void
     * @see AbstractSvnTestCase::resetSvnHost()
     */
    public static function setUpUnknownSvnHost()
    {
        self::$svnRoot = getenv(Config::SVN_AGENT_SVN_ROOT, '');
        putenv(Config::SVN_AGENT_SVN_ROOT . '=' . 'http://t6dkr8gkvc6o8bvf97.test');
    }

    /**
     * @return void
     * @see AbstractSvnTestCase::setUpInvalidSvnHost()
     * @see AbstractSvnTestCase::setUpUnknownSvnHost()
     */
    public static function resetSvnHost()
    {
        putenv(Config::SVN_AGENT_SVN_ROOT . '=' . self::$svnRoot);
    }

    /**
     * @param string $path
     * @return AbstractPathBasedAction
     * @throws ClientException
     */
    protected function createSvnUri(string $path): AbstractPathBasedAction
    {
        $requestData = [
            'data' => [
                'path' => $path,
            ]
        ] + self::getBasicRequestData();

        $action = new Update((new Request())->init($requestData));
        $action->run();

        return $action;
    }

    /**
     * @param string $path
     * @throws ClientException
     */
    protected function mkdir(string $path)
    {
        $dummyAction = self::getDummyPathBasedAction($path);
        if (!file_exists($dummyAction->getSvnDir())) {
            mkdir($dummyAction->getSvnDir(), 0755, true);
        }
    }

    /**
     * @param string $svnDir
     */
    protected function addSampleFiles(string $svnDir)
    {
        $i = 1;
        foreach (['.', 'dir1'] as $dir) {
            $dir = $svnDir . DIRECTORY_SEPARATOR . $dir;
            $str = str_pad('', $i - 1, ' '); // make sure our code supports spaces in file names.

            if (!is_dir($dir)) {
                mkdir($dir);
            }

            chdir($dir);
            touch("empty{$str}{$i}.txt");
            file_put_contents("hello{$str}${i}.txt", 'Hello, World!');

            $i++;
        }

        // Add an empty subdirectory.
        $dir = $svnDir . DIRECTORY_SEPARATOR . 'dir2';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
    }

    /**
     * Update files added by method AbstractSvnTestCase::addSampleFiles().
     *
     * @param string $svnDir
     * @see AbstractSvnTestCase::addSampleFiles()
     */
    protected function makeChangesUnderSvnDir(string $svnDir)
    {
        $i = 1;
        foreach (['.', 'dir1'] as $dir) {
            $dir = $svnDir . DIRECTORY_SEPARATOR . $dir;
            $str = str_pad('', $i - 1, ' '); // make sure our code supports spaces in file names.

            chdir($dir);
            unlink("empty{$str}{$i}.txt");
            file_put_contents("hello{$str}${i}.txt", '');
            touch("new{$str}{$i}.txt");

            $i++;
        }
    }

    /**
     * @param string $path
     * @return Request
     */
    protected function getPathBasedRequest(string $path): Request
    {
        return (new Request())->init(['data' => ['path' => $path]] + self::getBasicRequestData());
    }

    /**
     * @param string ...$paths
     * @return Request
     */
    protected function getPathsBasedRequest(string ...$paths): Request
    {
        return (new Request())->init(['data' => ['paths' => $paths]] + self::getBasicRequestData());
    }

    /**
     * @param string $path
     * @throws ClientException
     */
    protected static function deletePath(string $path)
    {
        // Change directory first so that current directory is always valid.
        chdir($_SERVER['HOME']);

        $request = (new Request())->init(self::getBasicRequestData() + ['data' => ['path' => $path]]);
        $action  = self::getDummyPathBasedAction($path);

        if (is_dir($action->getSvnDir())) {
            ShellWrap::rm('-rf', $action->getSvnDir());
        }
        if (SVNHelper::urlExists($action->getSvnUri(), $request)) {
            ShellWrap::svn('delete', $action->getSvnUri(), SVNHelper::getOptions($request, ['m' => 'path deleted']));
        }
    }

    /**
     * @param string ...$paths
     * @throws ClientException
     */
    protected static function deletePaths(string ...$paths)
    {
        foreach ($paths as $path) {
            self::deletePath($path);
        }
    }

    /**
     * @param string $path
     * @return DummyPathBasedAction
     * @throws ClientException
     */
    protected static function getDummyPathBasedAction(string $path = ''): DummyPathBasedAction
    {
        $request = (new Request())->init(
            [
                'data' => [
                    'path' => $path,
                ],
            ] + self::getBasicRequestData()
        );

        return new DummyPathBasedAction($request);
    }

    /**
     * @return array
     */
    protected static function getBasicRequestData(): array
    {
        return [
            'username' => base64_encode(self::getSvnUsername()),
            'password' => base64_encode(self::getSvnPassword()),
            'timeout'  => 30,
        ];
    }

    /**
     * @return array
     */
    protected static function getBasicRequestDataWithIncorrectCredentials(): array
    {
        return [
            'username' => uniqid() . '-',
            'password' => uniqid() . '-',
            'timeout'  => 30,
        ];
    }

    /**
     * @return string
     */
    protected static function getSvnUsername(): string
    {
        return $_ENV['SVN_USERNAME'];
    }

    /**
     * @return string
     */
    protected static function getSvnPassword(): string
    {
        return $_ENV['SVN_PASSWORD'];
    }

    /**
     * @return string
     */
    protected static function getSvnRootDir(): string
    {
        return Config::singleton()->getSvnRootDir();
    }

    /**
     * @return string
     */
    protected static function getSvnRoot(): string
    {
        return Config::singleton()->getSvnRoot();
    }
}
