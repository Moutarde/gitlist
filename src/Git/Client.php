<?php

namespace GitList\Git;

use Gitter\Client as BaseClient;

class Client extends BaseClient
{
    protected $defaultBranch;
    protected $hidden;
    protected $projects;

    public function __construct($options = null)
    {
        parent::__construct($options['path']);
        $this->setDefaultBranch($options['default_branch']);
        $this->setHidden($options['hidden']);
        $this->setProjects($options['projects'] ?? array());
    }

    public function getRepositoryFromName($paths, $repo)
    {
        $repositories = $this->getRepositories($paths);
        $path = $repositories[$repo]['path'];

        return $this->getRepository($path);
    }

    /**
     * Searches for valid repositories on the specified path
     *
     * @param  array $paths Array of paths where repositories will be searched
     * @return array Found repositories, containing their name, path and description sorted
     *               by repository name
     */
    public function getRepositories($paths)
    {
        $allRepositories = array();

        foreach ($paths as $path) {
            $repositories = $this->recurseDirectory($path, $path);

            if (empty($repositories)) {
                throw new \RuntimeException('There are no GIT repositories in ' . $path);
            }

            /**
             * Use "+" to preserve keys, only a problem with numeric repos
             */
            $allRepositories = $allRepositories + $repositories;
        }

        $allRepositories = array_unique($allRepositories, SORT_REGULAR);
        uksort($allRepositories, function($k1, $k2) {
            return strtolower($k2)<strtolower($k1);
        });

        return $allRepositories;
    }

    private function recurseDirectory($path, $rootPath, $topLevel = true)
    {
        $dir = new \DirectoryIterator($path);

        $repositories = array();

        foreach ($dir as $file) {
            if ($file->isDot()) {
                continue;
            }

            if (strrpos($file->getFilename(), '.') === 0) {
                continue;
            }

            if (!$file->isReadable()) {
                continue;
            }

            if ($file->isDir()) {
                $isBare = file_exists($file->getPathname() . '/HEAD');
                $isRepository = file_exists($file->getPathname() . '/.git/HEAD');

                if ($isRepository || $isBare) {
                    if (in_array($file->getPathname(), $this->getHidden())) {
                        continue;
                    }

                    if ($isBare) {
                        $description = $file->getPathname() . '/description';
                    } else {
                        $description = $file->getPathname() . '/.git/description';
                    }

                    if (file_exists($description)) {
                        $description = file_get_contents($description);
                    } else {
                        $description = null;
                    }

                    if (!$topLevel) {
                        $repoName = $file->getPathInfo()->getFilename() . '/' . $file->getFilename();
                    } else {
                        $repoName = $file->getFilename();
                    }

                    if (is_array($this->getProjects()) && !in_array($repoName, $this->getProjects())) {
                        continue;
                    }

                    $trimedPathName = trim(preg_replace('/^'.str_replace('/', '\/', $rootPath).'/', '', $file->getPathname()), '/');

                    $repositories[$trimedPathName] = array(
                        'name' => $repoName,
                        'path' => $file->getPathname(),
                        'trimed_path' => $trimedPathName,
                        'description' => $description
                    );

                    continue;
                } else {
                    $repositories = array_merge($repositories, $this->recurseDirectory($file->getPathname(), $rootPath, false));
                }
            }
        }

        return $repositories;
    }

    /**
     * Set default branch as a string.
     *
     * @param string $branch Name of branch to use when repo's HEAD is detached.
     * @return object
     */
    protected function setDefaultBranch($branch)
    {
        $this->defaultBranch = $branch;

        return $this;
    }

    /**
     * Return name of default branch as a string.
     */
    public function getDefaultBranch()
    {
        return $this->defaultBranch;
    }

    /**
     * Get hidden repository list
     *
     * @return array List of repositories to hide
     */
    protected function getHidden()
    {
        return $this->hidden;
    }

    /**
     * Set the hidden repository list
     *
     * @param array $hidden List of repositories to hide
     * @return object
     */
    protected function setHidden($hidden)
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Get project list
     *
     * @return array List of repositories to show
     */
    protected function getProjects()
    {
        return $this->projects;
    }

    /**
     * Set the shown repository list
     *
     * @param array $projects List of repositories to show
     */
    protected function setProjects($projects)
    {
        $this->projects = $projects;

        return $this;
    }

    /**
     * Overloads the parent::createRepository method for the correct Repository class instance
     * 
     * {@inheritdoc}
     */
    public function createRepository($path, $bare = null)
    {
        if (file_exists($path . '/.git/HEAD') && !file_exists($path . '/HEAD')) {
            throw new \RuntimeException('A GIT repository already exists at ' . $path);
        }

        $repository = new Repository($path, $this);

        return $repository->create($bare);
    }

    /**
     * Overloads the parent::getRepository method for the correct Repository class instance
     * 
     * {@inheritdoc}
     */
    public function getRepository($path)
    {
        if (!file_exists($path) || !file_exists($path . '/.git/HEAD') && !file_exists($path . '/HEAD')) {
            throw new \RuntimeException('There is no GIT repository at ' . $path);
        }

        return new Repository($path, $this);
    }




    public function getRepositoriesTree($paths, $filter)
    {
        $allRepositories = array();

        foreach ($paths as $path) {
            $repository = $this->recurseDirectoryTree($path, $filter, $path);

            if (!empty($repository['repositories']) || !empty($repository['subdirs']))
            {
                /**
                 * Use "+" to preserve keys, only a problem with numeric repos
                 */
                $allRepositories[$repository['name']] = $repository;
            }
        }

        $allRepositories = array_unique($allRepositories, SORT_REGULAR);
        uksort($allRepositories, function($k1, $k2) {
            return strtolower($k2)<strtolower($k1);
        });

        return $allRepositories;
    }

    private function recurseDirectoryTree($path, $filter, $rootPath)
    {
        $dir = new \DirectoryIterator($path);

        $repositories = array();
        $subdirs = array();

        foreach ($dir as $file) {
            if ($file->isDot()) {
                continue;
            }

            if (strrpos($file->getFilename(), '.') === 0) {
                continue;
            }

            if (!$file->isReadable()) {
                continue;
            }

            if ($file->isDir()) {
                $isBare = file_exists($file->getPathname() . '/HEAD');
                $isRepository = file_exists($file->getPathname() . '/.git/HEAD');

                if ($isRepository || $isBare) {
                    if (in_array($file->getPathname(), $this->getHidden())) {
                        continue;
                    }

                    if ($isBare) {
                        $description = $file->getPathname() . '/description';
                    } else {
                        $description = $file->getPathname() . '/.git/description';
                    }

                    if (file_exists($description)) {
                        $description = file_get_contents($description);
                    } else {
                        $description = null;
                    }

                    $repoName = $file->getFilename();
                    $trimedPathName = trim(preg_replace('/^'.str_replace('/', '\/', $rootPath).'/', '', $file->getPathname()), '/');

                    if ($filter == "" || stristr($repoName, $filter) || stristr($description, $filter))
                    {
                        $repositories[$repoName] = array(
                            'name' => $repoName,
                            'path' => $file->getPathname(),
                            'trimed_path' => $trimedPathName,
                            'description' => $description
                        );
                    }

                    continue;
                } else {
                    $subdir = $this->recurseDirectoryTree($file->getPathname(), $filter, $rootPath);
                    if (!empty($subdir['repositories']) || !empty($subdir['subdirs']))
                    {
                        $subdirs[$file->getFilename()] = $subdir;
                    }
                }
            }
        }

        $subdirs = array_unique($subdirs, SORT_REGULAR);
        uksort($subdirs, function($k1, $k2) {
            return strtolower($k2)<strtolower($k1);
        });

        $repositories = array_unique($repositories, SORT_REGULAR);
        uksort($repositories, function($k1, $k2) {
            return strtolower($k2)<strtolower($k1);
        });

        $repoTree = array(
            'id'            => str_replace("/", "", $path),
            'name'          => str_replace("/", "", (new \SplFileInfo($path))->getFilename()),
            'repositories'  => $repositories,
            'subdirs'       => $subdirs
        );

        return $repoTree;
    }
}

