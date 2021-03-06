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

namespace CrowdStar\SVNAgent\Actions;

use Closure;
use CrowdStar\SVNAgent\Config;
use CrowdStar\SVNAgent\Error;
use CrowdStar\SVNAgent\Exceptions\ClientException;
use CrowdStar\SVNAgent\Exceptions\Exception;
use CrowdStar\SVNAgent\PathHelper;
use CrowdStar\SVNAgent\Request;
use CrowdStar\SVNAgent\Responses\AbstractResponse;
use CrowdStar\SVNAgent\Responses\ErrorResponse;
use CrowdStar\SVNAgent\Responses\PathBasedErrorResponse;
use CrowdStar\SVNAgent\SVNHelper;
use CrowdStar\SVNAgent\Traits\LoggerTrait;
use CrowdStar\SVNAgent\Traits\PathTrait;
use CrowdStar\SVNAgent\WindowsCompatibleInterface;
use MrRio\ShellWrap;
use MrRio\ShellWrapException;
use NinjaMutex\Lock\FlockLock;
use NinjaMutex\Mutex;
use Psr\Log\LoggerInterface;

/**
 * Class AbstractAction
 *
 * @package CrowdStar\SVNAgent\Actions
 */
abstract class AbstractAction
{
    use LoggerTrait, PathTrait;

    const DIR_MODE = 0755;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var AbstractResponse
     */
    protected $response;

    /**
     * @var string
     */
    protected $message;

    /**
     * AbstractAction constructor.
     *
     * @param Request $request
     * @param LoggerInterface|null $logger
     * @throws ClientException
     */
    public function __construct(Request $request, LoggerInterface $logger = null)
    {
        $this
            ->setConfig(Config::singleton())
            ->setLogger(($logger ?: $request->getLogger()))
            ->setRequest($request)
            ->init();
    }

    /**
     * @return AbstractResponse
     * @throws ClientException
     */
    public function run(): AbstractResponse
    {
        try {
            $this->process()->getResponse();
        } catch (ClientException $e) {
            $this->setError($e->getMessage());
        } catch (Exception $e) {
            $this->setError('Backend issue. Please check with Home backend developers for helps.');
            $this->getLogger()->error(get_class($e) . ': ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->setError('Unknown issue. Please check with Home backend developers for helps.');
            $this->getLogger()->error(get_class($e) . ': ' . $e->getMessage());
        }

        return $this->getResponse();
    }

    /**
     * @return AbstractAction
     * @throws ClientException
     */
    public function process(): AbstractAction
    {
        if ($this instanceof LocklessActionInterface) {
            $this->processAction();
        } else {
            set_time_limit($this->getRequest()->getTimeout());

            $mutex = $this->getMutex();
            if ($mutex->acquireLock(0)) {
                $this->processAction();
                $mutex->releaseLock();
            } else {
                $this->setError(Error::LOCK_FAILED);
            }
        }

        $this->getLogger()->info('response: ' . $this->getResponse());

        // Process post actions once current action is processed.
        if (!$this->hasError() && !empty($this->getPostActions())) {
            foreach ($this->getPostActions() as $action) {
                $action->process();

                // don't process rest actions when error happens.
                if ($action->hasError()) {
                    break;
                }
            }

            // Send response back from last executed post action instead.
            $this->setResponse($action->getResponse());
        }

        return $this;
    }

    /**
     * @return AbstractAction
     */
    abstract public function processAction(): AbstractAction;

    /**
     * @param Closure $closure
     * @return AbstractAction
     * @throws Exception
     */
    protected function exec(Closure $closure): AbstractAction
    {
        if (!empty($this->getMessage())) {
            $this->getLogger()->info("now executing command: {$this->getMessage()}");
        } else {
            $this->getLogger()->info("now executing command defined in class " . __CLASS__);
        }

        $sh = new ShellWrap();
        try {
            $closure();
            $this->prepareResponse((string) $sh);
        } catch (ShellWrapException $e) {
            $this->setError($e->getMessage());
        }

        return $this;
    }

    /**
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * @param Config $config
     * @return $this
     */
    public function setConfig(Config $config): AbstractAction
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @param Request $request
     * @return $this
     */
    public function setRequest(Request $request): AbstractAction
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @return AbstractResponse
     */
    public function getResponse(): AbstractResponse
    {
        return $this->response;
    }

    /**
     * @param AbstractResponse $response
     * @return $this
     */
    public function setResponse(AbstractResponse $response): AbstractAction
    {
        $this->response = $response;

        return $this;
    }

    /**
     * @param string $output
     * @return $this
     * @throws Exception
     */
    protected function prepareResponse(string $output): AbstractAction
    {
        $this->getResponse()->process($output);

        return $this;
    }

    /**
     * @return string
     */
    public function getSvnUri(): string
    {
        return $this->getConfig()->getSvnRoot() . $this->getPath();
    }

    /**
     * @return string
     */
    public function getSvnDir(): string
    {
        $svnDir = $this->getConfig()->getSvnRootDir() . $this->getPath();
        if (($this instanceof WindowsCompatibleInterface) && $this->getConfig()->onWindows()) {
            return PathHelper::toWindowsPath($svnDir);
        }

        return $svnDir;
    }

    /**
     * @return AbstractAction
     */
    public function getBackupDir(): string
    {
        $dir = join(DIRECTORY_SEPARATOR, [$this->getConfig()->getRootDir(), 'backup', uniqid(date('YmdHis-'))]);

        if (!file_exists($dir)) {
            mkdir($dir, self::DIR_MODE, true);
        }

        return $dir;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message): AbstractAction
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return Mutex
     */
    protected function getMutex(): Mutex
    {
        return new Mutex(getenv(Config::SVN_AGENT_MUTEX_NAME), new FlockLock($this->getConfig()->getRootDir()));
    }

    /**
     * @param string $error
     * @return AbstractAction
     * @throws ClientException
     */
    protected function setError(string $error): AbstractAction
    {
        if ($this instanceof PathBasedActionInterface) {
            $this->setResponse((new PathBasedErrorResponse($error))->setPath($this->getPath()));
        } else {
            $this->setResponse(new ErrorResponse($error));
        }

        return $this;
    }

    /**
     * @return bool
     */
    protected function hasError(): bool
    {
        return ($this->getResponse() instanceof ErrorResponse);
    }

    /**
     * @return AbstractAction
     */
    abstract protected function initResponse(): AbstractAction;

    /**
     * @return $this
     * @throws ClientException
     */
    protected function init(): AbstractAction
    {
        if (!($this instanceof PathNotRequiredActionInterface)) {
            $this->setPath($this->getRequest()->get('path'));
        }

        return $this->validate()->initResponse();
    }

    /**
     * @return $this
     * @throws ClientException
     */
    protected function validate(): AbstractAction
    {
        if (!$this->getRequest()->getUsername() || !$this->getRequest()->getPassword()) {
            throw new ClientException('SVN credential missing');
        }

        if (!$this->path && !($this instanceof PathNotRequiredActionInterface)) {
            throw new ClientException('field "path" not passed in as should');
        }

        return $this;
    }

    /**
     * @return AbstractAction
     * @throws ClientException
     */
    protected function initializeSvnPathWhenNeeded(): AbstractAction
    {
        if (!SVNHelper::pathExists($this->getSvnDir())) {
            //TODO: better error handling when calling other actions from current action.
            (new Create($this->getRequest(), $this->getLogger()))->processAction();
            (new Update($this->getRequest(), $this->getLogger()))->processAction();
        }

        return $this;
    }

    /**
     * @return AbstractAction[]
     */
    protected function getPostActions(): array
    {
        return [];
    }

    /**
     * @param string $filename
     * @return string
     * @throws Exception
     */
    protected function getFullBinPath(string $filename): string
    {
        return PathHelper::getFullBinPath($filename);
    }
}
