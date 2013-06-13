<?php

namespace Symfony\Cmf\Bundle\MediaBundle\Gaufrette\Adapter;

use Doctrine\Common\Persistence\ObjectManager;
use Gaufrette\Adapter;
use Gaufrette\Adapter\ChecksumCalculator;
use Gaufrette\Adapter\ListKeysAware;
use Gaufrette\Adapter\MetadataSupporter;
use Gaufrette\Util;
use Symfony\Cmf\Bundle\MediaBundle\DirectoryInterface;
use Symfony\Cmf\Bundle\MediaBundle\FileInterface;

/**
 * Cmf doctrine media adapter
 *
 * The path to a file is: /path/to/file/filename.ext
 *
 * For PHPCR the id is being the path, set "fullPathId" to true.
 * For ORM the file path concatenates the directory identifiers with '/'
 * and ends with the file identifier. For a nice path a slug could be used
 * as identifier, set "identifier" to fe. "slug".
 */
class CmfMediaDoctrine implements Adapter,
                          ChecksumCalculator,
                          ListKeysAware,
                          MetadataSupporter
{
    protected $manager;
    protected $class;
    protected $rootPath;
    protected $create;
    protected $fullPathId;
    protected $dirClass;
    protected $identifier;

    protected $keys;

    /**
     * Constructor
     *
     * @param ObjectManager $manager
     * @param string        $class      fully qualified class name of file
     * @param string        $rootPath   path where the filesystem is located
     * @param boolean       $create     Whether to create the directory if it
     *                                  does not exist (default FALSE)
     * @param boolean       $fullPathId whether the identifier contains the
     *                                  full file path (default FALSE)
     * @param string        $dirClass   fully qualified class name for dirs
     *                                  (default NULL: dir is same as file)
     * @param string        $identifier property used to identify a file and
     *                                  lookup (default NULL: let Doctrine
     *                                  determine the identifier)
     */
    public function __construct(
        ObjectManager $manager,
        $class,
        $rootPath = '/',
        $create = false,
        $fullPathId = false,
        $dirClass = null,
        $identifier = null)
    {
        $this->manager    = $manager;
        $this->class      = $class;
        $this->rootPath   = Util\Path::normalize($rootPath);
        $this->create     = $create;
        $this->dirClass   = $dirClass;
        $this->identifier = $identifier;

        if (!is_subclass_of($class, 'Symfony\Cmf\Bundle\MediaBundle\FileInterface')) {
            throw new \InvalidArgumentException(sprintf(
                'The class "%s" does not implement Symfony\Cmf\Bundle\MediaBundle\FileInterface',
                $class
            ));
        }

        if ($identifier && !$this->manager->getClassMetadata($class)->hasField($identifier)) {
            throw new \InvalidArgumentException(sprintf(
                'The class "%s" does not have the field "%s" to be used as identifier',
                $class,
                $identifier
            ));
        }

        if ($dirClass) {
            if (!is_subclass_of($dirClass, 'Symfony\Cmf\Bundle\MediaBundle\DirectoryInterface')) {
                throw new \InvalidArgumentException(sprintf(
                    'The class "%s" does not implement Symfony\Cmf\Bundle\MediaBundle\DirectoryInterface',
                    $dirClass
                ));
            }

            if ($identifier && !$this->manager->getClassMetadata($dirClass)->hasField($identifier)) {
                throw new \InvalidArgumentException(sprintf(
                    'The class "%s" does not have the field "%s" to be used as identifier',
                    $dirClass,
                    $identifier
                ));
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function read($key)
    {
        $file = $this->find($key);

        return $file ? $file->getContentAsString() : '';
    }

    /**
     * {@inheritDoc}
     */
    public function write($key, $content)
    {
        if ($this->exists($key)) {
            $file = $this->find($key);
        } else {
            $filePath = $this->computePath($key);

            $this->ensureDirectoryExists(dirname($filePath), $this->create);

            $file   = new $this->class();
            $parent = $this->find(dirname($key));

            $this->setFileDefaults($filePath, $file, $parent);
        }

        $file->setContentFromString($content);

        $this->manager->persist($file);
        $this->manager->flush();

        return $file->getSize();
    }

    /**
     * {@inheritDoc}
     */
    public function exists($key)
    {
        return (boolean) $this->find($key) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function keys()
    {
        if (is_null($this->keys)) {
            $keys = array();

            $files = $this->findAll();
            foreach ($files as $file) {
                $keys[] = $this->computeKey($this->getFilePath($file));
            }

            $this->keys = sort($keys);
        }

        return $this->keys;
    }

    /**
     * {@inheritDoc}
     */
    public function mtime($key)
    {
        $file = $this->find($key);

        return $file && $file->getUpdatedAt() ? $file->getUpdatedAt()->getTimestamp() : false;
    }

    /**
     * {@inheritDoc}
     */
    public function delete($key)
    {
        $file = $this->find($key);

        return $file && $this->manager->remove($file);
    }

    /**
     * {@inheritDoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        // not supported, extend for a specific implementation
        //
        // a key is always a path ending with the filename
        // a rename is:
        // (1) a move to another parent directory
        // (2) and/or a filename change
        //
        // (1) can always be supported for files implementing the
        //     DirectoryInterface
        // (2) renaming the filename part is specific:
        //     - ORM: do not support renaming the filename (=identifier) if it
        //       is an auto generated id
        //     - ORM: can support renaming the filename (=identifier) if it is
        //       a slug that can be changed
        //     - PHPCR: can support renaming the filename if the nodename can
        //       be changed
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isDirectory($key)
    {
        if ('/' === $key) {
            return true;
        }

        $file = $this->find($key, true);

        if ($file instanceof DirectoryInterface) {
            return $file->isDirectory();
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function checksum($key)
    {
        $file = $this->find($key);

        return Util\Checksum::fromContent(($file ? $file->getContentAsString() : ''));
    }

    /**
     * {@inheritDoc}
     */
    public function listKeys($prefix = '')
    {
        $dirKeys = $fileKeys = array();
        $files   = $this->findAll($prefix);

        foreach ($files as $file) {
            $key = $this->computeKey($this->getFilePath($file));

            if ($file instanceof DirectoryInterface && $file->isDirectory()) {
                $dirKeys[] = $key;
            } else {
                $fileKeys[] = $key;
            }
        }

        return array(
            'dirs' => sort($dirKeys),
            'keys' => sort($fileKeys),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function setMetadata($key, $metadata)
    {
        $file = $this->find($key);

        if ($file) {
            $file->setMetadata($metadata);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata($key)
    {
        $file = $this->find($key);

        return $file ? $file->getMetadata() : array();
    }

    /**
     * Find a file object for the given key.
     *
     * @param string|int $key Identifier.
     * @param boolean $dir directly try to find a directory
     * @return FileInterface
     */
    protected function find($key, $dir = false)
    {
        if (!isset($key)) {
            return null;
        }

        $id = $this->mapKeyToId($key);
        $file = null;

        // find file
        if (!$dir || ($dir && !$this->dirClass)) {
            if ($this->identifier) {
                $file = $this->manager
                    ->getRepository($this->class)
                    ->findOneBy(array($this->identifier => $id))
                ;
            } else {
                $file = $this->manager->getRepository($this->class)->find($id);
            }
        }

        // find directory from the configured directory repository
        if (!$file && $this->dirClass) {
            if ($this->identifier) {
                $file = $this->manager
                    ->getRepository($this->class)
                    ->findOneBy(array($this->identifier => $id))
                ;
            } else {
                $file = $this->manager->getRepository($this->dirClass)->find($id);
            }
        }

        return $file;
    }

    /**
     * Get all files and directories,
     * extend for a specific and more efficient implementation
     *
     * @param string $prefix
     * @return FileInterface[]
     */
    protected function findAll($prefix = '')
    {
        $filesAndDirs = array();
        $prefix = $this->normalizePath($this->rootPath . '/' . trim($prefix));

        $files = $this->manager->getRepository($this->class)->findAll();
        foreach ($files as $file) {
            if (empty($prefix) || false !== strpos($this->getFilePath($file), $prefix)) {
                $filesAndDirs[] = $file;
            }
        }

        if ($this->dirClass) {
            $dirs = $this->manager->getRepository($this->dirClass)->findAll();
            foreach ($dirs as $dir) {
                if (empty($prefix) || false !== strpos($this->getFilePath($dir), $prefix)) {
                    $filesAndDirs[] = $dir;
                }
            }
        }

        return $filesAndDirs;
    }

    /**
     * Get full file path: /path/to/file/filename.ext
     *
     * For PHPCR the id is being the path.
     * For ORM the file path concatenates the directory identifiers with '/'
     * and ends with the file identifier. For a nice path a slug could be used
     * as identifier.
     *
     * @return string
     */
    protected function getFilePath(FileInterface $file)
    {
        if ($file instanceof DirectoryInterface) {
            $path = $file->getPath();
        } else {
            $path = $file->getId();
        }

        return $path;
    }

    /**
     * Map the key to an id to retrieve the file
     *
     * @param $key
     *
     * @return string
     */
    protected function mapKeyToId($key)
    {
        if ($this->fullPathId) {
            // The path is being the id
            return $this->computePath($key);
        } else {
            // Get filename component of path, that is the id
            return basename($this->computePath($key));
        }
    }

    /**
     * Computes the key from the specified path
     *
     * @param string $path
     *
     * return string
     */
    public function computeKey($path)
    {
        $path = $this->normalizePath($path);

        return ltrim(substr($path, strlen($this->directory)), '/');
    }

    /**
     * Computes the path from the specified key
     *
     * @param string $key The key which for to compute the path
     *
     * @return string A path
     *
     * @throws OutOfBoundsException If the computed path is out of the rootPath
     * @throws RuntimeException If directory does not exists and cannot be
     *                          created
     */
    protected function computePath($key)
    {
        $this->ensureDirectoryExists($this->rootPath, $this->create);

        return $this->normalizePath($this->rootPath . '/' . $key);
    }

    /**
     * Normalizes the given path
     *
     * @param string $path
     *
     * @return string
     *
     * @throws OutOfBoundsException If the computed path is out of the
     *                              rootPath
     */
    protected function normalizePath($path)
    {
        $path = Util\Path::normalize($path);

        if (0 !== strpos($path, $this->rootPath)) {
            throw new \OutOfBoundsException(sprintf('The path "%s" is out of the filesystem.', $path));
        }

        return $path;
    }

    /**
     * Set default values for a new file or directory
     *
     * @param string        $path   Path of the file
     * @param FileInterface $file
     * @param FileInterface $parent Parent directory of the file
     */
    protected function setFileDefaults($path, FileInterface $file, FileInterface $parent = null)
    {
        $setIdentifier = $this->identifier ? 'set'.ucfirst($this->identifier) : false;
        $name          = basename($path);

        if ($setIdentifier) {
            $file->{$setIdentifier}($name);
        }
        $file->setName($name);

        if ($parent && $file instanceof DirectoryInterface) {
            $file->setParentDirectory($parent);
        }
    }

    /**
     * Ensures the specified directory exists, creates it if it does not
     *
     * @param string  $dirPath  Path of the directory to test
     * @param boolean $create   Whether to create the directory if it does
     *                          not exist
     *
     * @throws RuntimeException if the directory does not exists and could not
     *                          be created
     */
    protected function ensureDirectoryExists($dirPath, $create = false)
    {
        if (!$this->find($dirPath, true)) {
            if (!$create) {
                throw new \RuntimeException(sprintf('The directory "%s" does not exist.', $dirPath));
            }

            $this->createDirectory($dirPath);
        }
    }

    /**
     * Creates the specified directory and its parents, like mkdir -p
     *
     * @param string $dirPath Path of the directory to create
     *
     * @return FileInterface The created directory
     *
     * @throws InvalidArgumentException if the directory already exists
     */
    protected function createDirectory($dirPath)
    {
        $parent = null;

        if ($this->isDirectory($dirPath)) {
            throw new \InvalidArgumentException(sprintf(
                'The directory \'%s\' already exists.',
                $dirPath
            ));
        }

        // create parent directory if needed
        $parentPath = dirname($dirPath);
        if (!$this->isDirectory($parentPath)) {
            $parent = $this->createDirectory($parentPath);
        }

        $dirClass = $this->dirClass ? $this->dirClass : $this->class;

        $dir = new $dirClass();
        $this->setFileDefaults($dirPath, $dir, $parent);

        $this->manager->persist($dir);
        $this->manager->flush();

        return $dir;
    }
}