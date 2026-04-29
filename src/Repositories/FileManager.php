<?php

namespace App\Repositories;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use Psr\Http\Message\UploadedFileInterface;

class FileManager
{
    public function __construct(private BlobRestProxy $storage, private string $blobUrl) {}

    private function getBlobOption(string $fileName, $content): CreateBlockBlobOptions
    {
        $detector = new FinfoMimeTypeDetector();
        $mimeType = $detector->detectMimeType($fileName, $content);

        $options = new CreateBlockBlobOptions();
        $options->setContentType($mimeType);

        return $options;
    }

    private function checkSize(UploadedFileInterface $file, int $maxMb): void
    {
        $size = $file->getSize();
        if ($size <= 0 || $size > $maxMb * 1024 * 1024) {
            throw new \InvalidArgumentException("Ce fichier est trop volumineux, taille maximum {$maxMb}M");
        }
    }

    private function checkType(UploadedFileInterface $file, array $ext, array $mimes): void
    {
        $filename = $file->getClientFilename() ?? '';
        $tmpPath = $file->getStream()->getMetadata('uri');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        if (
            !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $ext) ||
            !in_array($mimeType, $mimes)
        ) {
            throw new \InvalidArgumentException("Ce fichier n'est pas au bon format");
        }
    }

    private function isPicture(UploadedFileInterface $file): void
    {
        $this->checkType($file, ["gif", "heic", "heif", "jpg", "jpeg", "png", "webp", "mp4"], [
            "image/gif", "image/heic", "image/heif", "image/jpeg",
            "image/png", "image/webp", "video/mp4",
        ]);
    }

    private function isSound(UploadedFileInterface $file): void
    {
        $this->checkType($file, ["mp3", "aac", "ogg", "wav", "midi"], [
            "audio/mpeg", "audio/aac", "audio/ogg",
            "audio/wav", "audio/x-wav", "audio/x-midi",
        ]);
    }

    public function uploadPicture(UploadedFileInterface $file): string
    {
        $this->checkSize($file, 7);
        $this->isPicture($file);

        $fileName = uniqid() . '.' . pathinfo($file->getClientFilename() ?? 'file', PATHINFO_EXTENSION);
        $content = $file->getStream()->detach();
        $this->storage->createBlockBlob("images", "charity_stream/$fileName", $content, $this->getBlobOption($fileName, $content));

        return $fileName;
    }

    public function getPictureUrl(string $fileName): string
    {
        return $this->blobUrl . '/images/charity_stream/' . $fileName;
    }

    public function uploadSound(UploadedFileInterface $file): string
    {
        $this->checkSize($file, 7);
        $this->isSound($file);

        $fileName = uniqid() . '.' . pathinfo($file->getClientFilename() ?? 'file', PATHINFO_EXTENSION);
        $content = $file->getStream()->detach();
        $this->storage->createBlockBlob("sounds", "charity_stream/$fileName", $content, $this->getBlobOption($fileName, $content));

        return $fileName;
    }

    public function getSoundUrl(string $fileName): string
    {
        return $this->blobUrl . '/sounds/charity_stream/' . $fileName;
    }
}
