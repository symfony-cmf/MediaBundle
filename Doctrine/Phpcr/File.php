<?php

namespace Symfony\Cmf\Bundle\MediaBundle\Doctrine\Phpcr;

use Doctrine\ODM\PHPCR\Document\Resource;
use Symfony\Cmf\Bundle\MediaBundle\BinaryInterface;
use Symfony\Cmf\Bundle\MediaBundle\DirectoryInterface;
use Symfony\Cmf\Bundle\MediaBundle\FileInterface;
use Symfony\Cmf\Bundle\MediaBundle\FileSystemInterface;

/**
 * TODO: create and add cmf:file mixin
 * This class represents a CmfMedia Doctrine Phpcr file.
 */
class File extends Media implements BinaryInterface,
                                    DirectoryInterface
{
    /**
     * @var Resource
     */
    protected $content;

    /**
     * @var int
     */
    protected $size;

    /**
     * @var string
     */
    protected $contentType;

    /**
     * @var string
     */
    protected $extension;

    /**
     * Set the content for this file from the given filename.
     * Calls file_get_contents with the given filename
     *
     * @param string $filename name of the file which contents should be used
     */
    public function setFileContentFromFilesystem($filename)
    {
        $this->getContent();
        $stream = fopen($filename, 'rb');
        if (! $stream) {
            throw new \RuntimeException("File '$filename' not found");
        }

        $this->content->setData($stream);
        $this->content->setLastModified(new \DateTime('@'.filemtime($filename)));

        $finfo = new \finfo();
        $this->content->setEncoding($finfo->file($filename,FILEINFO_MIME_ENCODING));
        $this->content->mimeType($finfo->file($filename,FILEINFO_MIME_TYPE));

        $this->updateDimensionsFromContent();
    }

    /**
     * Set the content for this file from the given Resource.
     *
     * @param Resource $content
     */
    public function setContent(Resource $content)
    {
        $this->content = $content;
    }

    /*
     * Get the resource representing the data of this file.
     *
     * Ensures the content object is created
     *
     * @return Resource
     */
    public function getContent()
    {
        if ($this->content === null) {
            $this->content = new Resource();
            $this->content->setLastModified(new \DateTime());
        }

        return $this->content;
    }

    /**
     * {@inheritDoc}
     */
    public function getContentAsString()
    {
        $stream = $this->getContent()->getData();
        $content = stream_get_contents($stream);
        rewind($stream);

        return $content !== false ? $content : '';
    }

    /**
     * {@inheritDoc}
     */
    public function setContentFromString($content)
    {
        $this->getContent();

        if (!is_resource($content)) {
            $stream = fopen('php://memory', 'rwb+');
            fwrite($stream, $content);
            rewind($stream);
        } else {
            $stream = $content;
        }

        $this->setContentFromStream($stream);
    }

    /**
     * {@inheritDoc}
     */
    public function copyContentFromFile($file)
    {
        if ($file instanceof \SplFileInfo) {
            $this->setFileContentFromFilesystem($file->getPathname());
        } elseif ($file instanceof BinaryInterface) {
            $this->setContentFromStream($file->getContentAsStream());
        } elseif ($file instanceof FileSystemInterface) {
            $this->setFileContentFromFilesystem($file->getFileSystemPath());
        } elseif ($file instanceof FileInterface) {
            $this->setContentFromString($file->getContentAsString());
        } else {
            $type = is_object($file) ? get_class($file) : gettype($file);
            throw new \InvalidArgumentException(sprintf(
                'File is not a valid type, "%s" given.',
                 $type
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getContentAsStream()
    {
        $stream = $this->getContent()->getData();
        rewind($stream);

        return $stream;
    }

    /**
     * {@inheritDoc}
     */
    public function setContentFromStream($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a stream');
        }

        $this->getContent()->setData($stream);
        $this->updateDimensionsFromContent();
    }

    /**
     * {@inheritDoc}
     */
    public function setParentDirectory(DirectoryInterface $parent)
    {
        $this->parent = $parent;
    }

    /**
     * {@inheritDoc}
     */
    public function getParentDirectory()
    {
        return $this->parent instanceof DirectoryInterface ? $this->parent : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath()
    {
        return (string) $this->id;
    }

    /**
     * @param int $size
     */
    public function setSize($size)
    {
        $this->size = $size;
    }

    /**
     * {@inheritDoc}
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @param $mimeType string
     */
    public function setContentType($contentType)
    {
        $this->contentType = $contentType;
        $this->getContent()->setMimeType($contentType);
    }

    /**
     * {@inheritDoc}
     */
    public function getContentType()
    {
        return $this->contentType;
    }

    /**
     * @param string $extension
     */
    public function setExtension($extension)
    {
        $this->extension = $extension;
    }

    /**
     * {@inheritDoc}
     */
    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * Update dimensions like file size after content is set
     */
    protected function updateDimensionsFromContent()
    {
        $stream = $this->getContentAsStream();

        $stat = fstat($stream);
        $this->size = $stat['size'];
        $this->contentType = $this->getContent()->getMimeType();
    }
}