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

namespace CrowdStar\Tests\SVNAgent\Actions;

use CrowdStar\SVNAgent\Actions\AbstractBulkAction;
use CrowdStar\SVNAgent\Actions\AbstractPathBasedBulkAction;
use CrowdStar\SVNAgent\Actions\BulkCommits;
use CrowdStar\SVNAgent\Actions\BulkReview;
use CrowdStar\SVNAgent\Actions\BulkUpdate;
use CrowdStar\SVNAgent\Actions\Update;
use CrowdStar\SVNAgent\Actions\Create;
use CrowdStar\SVNAgent\Exceptions\ClientException;
use CrowdStar\SVNAgent\SVNHelper;
use CrowdStar\Tests\SVNAgent\AbstractSvnTestCase;

/**
 * Class BulkActionsTest
 *
 * @package CrowdStar\Tests\SVNAgent\Actions
 */
class BulkActionsTest extends AbstractSvnTestCase
{
    /**
     * @inheritdoc
     */
    public static function setUpBeforeClass()
    {
        self::deletePaths('path');
    }

    /**
     * @covers AbstractPathBasedBulkAction::processAction()
     * @covers BulkUpdate
     * @covers Create::processAction()
     * @group svn-server
     * @throws ClientException
     */
    public function testProcessActionOnBulkUpdate()
    {
        // NOTE: those 3 paths have slashes around them in different ways.
        $bulkAction = new BulkUpdate($this->getPathsBasedRequest('path/0', '/path/1', 'path/2/'));
        foreach ($bulkAction->getPaths() as $path) {
            $this->assertFileNotExists((new Update($this->getPathBasedRequest($path)))->getSvnDir());
        }

        // Here we create 3 SVN paths: /path/1, /path/2 and /path/3
        foreach (['path/0', '/path/1', 'path/2/'] as $path) {
            (new Create($this->getPathBasedRequest($path)))->run();
        }

        $bulkResponse = $bulkAction->run()->toArray();
        foreach ($bulkResponse['response'] as $key => $response) {
            $this->assertInternalType('int', $response['revision'], 'revision #s are always integers.');
            $this->assertGreaterThan(0, $response['revision'], 'revision #s are always positive integers.');

            // Hardcode revision numbers for comparison purpose.
            $bulkResponse['response'][$key]['revision'] = 15;
        }
        $this->assertSame(
            [
                'success' => true,
                'response' => [
                    [
                        'success'  => true,
                        'path'     => '/path/0/',
                        'actions'  => [],
                        'revision' => 15,
                    ],
                    [
                        'success'  => true,
                        'path'     => '/path/1/',
                        'actions'  => [],
                        'revision' => 15,
                    ],
                    [
                        'success'  => true,
                        'path'     => '/path/2/',
                        'actions'  => [],
                        'revision' => 15,
                    ],
                ],
            ],
            $bulkResponse
        );
        foreach ($bulkAction->getPaths() as $path) {
            $this->assertTrue(
                SVNHelper::pathExists((new Update($this->getPathBasedRequest($path)))->getSvnDir()),
                ''
            );
        }
    }

    /**
     * @covers AbstractPathBasedBulkAction::processAction()
     * @covers BulkCommits
     * @depends testProcessActionOnBulkUpdate
     * @group svn-server
     */
    public function testProcessActionOnBulkCommits()
    {
        // NOTE: those 3 paths have slashes around them in different ways.
        $bulkAction = new BulkCommits($this->getPathsBasedRequest('path/0/', '/path/1/', 'path/2'));
        foreach ($bulkAction->getPaths() as $key => $path) {
            file_put_contents(
                (new Update($this->getPathBasedRequest($path)))->getSvnDir() . DIRECTORY_SEPARATOR . $key,
                $key
            );
        }
        $this->assertSame(
            [
                'success' => true,
                'response' => [
                    [
                        'success' => true,
                        'path'    => '/path/0/',
                    ],
                    [
                        'success' => true,
                        'path'    => '/path/1/',
                    ],
                    [
                        'success' => true,
                        'path'    => '/path/2/',
                    ],
                ],
            ],
            $bulkAction->run()->toArray(),
            'bulk commits should succeed.'
        );
        foreach ($bulkAction->getPaths() as $path) {
            $this->assertFalse(
                SVNHelper::pathExists((new Update($this->getPathBasedRequest($path)))->getSvnDir()),
                "local working copy under directory '{$path}' is deleted after being committed"
            );
        }
    }

    /**
     * @covers AbstractPathBasedBulkAction::processAction()
     * @covers BulkReview
     * @depends testProcessActionOnBulkCommits
     * @group svn-server
     */
    public function testProcessActionOnBulkReview()
    {
        (new BulkUpdate($this->getPathsBasedRequest('path/0', '/path/1', 'path/2/')))->run();

        // NOTE: those 3 paths have slashes around them in different ways.
        $bulkAction = new BulkReview($this->getPathsBasedRequest('/path/0/', 'path/1/', '/path/2'));

        // First directory: delete file 0; add file 1 and 2.
        chdir((new Update($this->getPathBasedRequest($bulkAction->getPaths()[0])))->getSvnDir());
        unlink('0');
        touch('1');
        touch('2');
        // Second directory: add file 0 and 2; update file 1.
        chdir((new Update($this->getPathBasedRequest($bulkAction->getPaths()[1])))->getSvnDir());
        touch('0');
        file_put_contents('1', 'file content changed');
        touch('2');
        // Third directory: add file 0 and 1; delete file 2.
        chdir((new Update($this->getPathBasedRequest($bulkAction->getPaths()[2])))->getSvnDir());
        touch('0');
        touch('1');
        unlink('2');

        $this->assertSame(
            [
                'success' => true,
                'response' => [
                    [
                        'success' => true,
                        'path'    => '/path/0/',
                        'actions' => [
                            [
                                'type' => '!',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/0/0",
                            ],
                            [
                                'type' => '?',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/0/1",
                            ],
                            [
                                'type' => '?',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/0/2",
                            ],
                        ],
                    ],
                    [
                        'success' => true,
                        'path'    => '/path/1/',
                        'actions' => [
                            [
                                'type' => '?',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/1/0",
                            ],
                            [
                                'type' => 'M',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/1/1",
                            ],
                            [
                                'type' => '?',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/1/2",
                            ],
                        ],
                    ],
                    [
                        'success' => true,
                        'path'    => '/path/2/',
                        'actions' => [
                            [
                                'type' => '?',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/2/0",
                            ],
                            [
                                'type' => '?',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/2/1",
                            ],
                            [
                                'type' => '!',
                                'file' => "${_SERVER['HOME']}/svn-agent/svn/path/2/2",
                            ],
                        ],
                    ],
                ],
            ],
            $bulkAction->run()->toArray(),
            'compare bulk review results.'
        );
    }

    /**
     * @covers AbstractBulkAction::setPaths
     * @group svn-server
     */
    public function testMaxPaths()
    {
        $paths = array_map(
            function (int $i) : string {
                return "/path/${i}";
            },
            range(1, 40)
        );
        ;
        $this->assertCount(
            40,
            (new BulkUpdate($this->getPathsBasedRequest(...$paths)))->getPaths(),
            'up to 40 paths can be handled together'
        );
    }

    /**
     * @covers AbstractBulkAction::setPaths
     * @group svn-server
     * @expectedException \CrowdStar\SVNAgent\Exceptions\ClientException
     * @expectedExceptionMessage up to 40 paths can be handled together
     */
    public function testMaxPathsWithException()
    {
        $paths = array_map(
            function (int $i) : string {
                return "/path/${i}";
            },
            range(1, 41)
        );
        new BulkUpdate($this->getPathsBasedRequest(...$paths));
    }
}
