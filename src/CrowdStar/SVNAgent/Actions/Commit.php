<?php

namespace CrowdStar\SVNAgent\Actions;

use MrRio\ShellWrap;
use MrRio\ShellWrapException;

/**
 * Class Commit
 *
 * @package CrowdStar\SVNAgent\Actions
 */
class Commit extends AbstractAction
{
    /**
     * @inheritdoc
     */
    public function processAction(): AbstractAction
    {
        $dir = $this->getSvnDir();
        if (is_readable($dir)) {
            chdir($dir);

            // @see https://stackoverflow.com/a/11066348/2752269 svn delete removed files
            ShellWrap::svn(
                'status | grep \'^!\' | awk \'{print $2}\' | xargs svn delete'
            );
            try {
                // @see https://stackoverflow.com/a/4046862/2752269 How do I 'svn add' all unversioned files to SVN?
                ShellWrap::svn(
                    'add --force * --auto-props --parents --depth infinity -q'
                );
            } catch (ShellWrapException $e) {
                // This "svn add" command fails if nothing to add.
            }

            $this->setMessage('SVN commit')->exec(
                function () use ($dir) {
                    ShellWrap::svn(
                        'commit',
                        [
                            'username' => $this->getRequest()->getUsername(),
                            'password' => $this->getRequest()->getPassword(),
                            'm'        => 'changes committed through SVN Agent',
                        ]
                    );
                }
            );
        } else {
            $this->setError("Folder '{$dir}' not exist");
        }

        return $this;
    }
}
