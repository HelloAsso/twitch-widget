<?php

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;

class FileManager
{
    private $storage = null;
    private $blobUrl = null;

    public function __construct($storage, $blobUrl)
    {
        $this->storage = $storage;
        $this->blobUrl = $blobUrl;
    }

    private function getBlobOption($file, $content)
    {
        $detector = new FinfoMimeTypeDetector();
        $mimeType = $detector->detectMimeType($file, $content);

        $options = new CreateBlockBlobOptions();
        $options->setContentType($mimeType);

        return $options;
    }

    private function checkSize($formdata, $size)
    {
        if ($formdata['size'] <= 0 || $formdata['size'] > $size * 1024 * 1024)
            throw new InvalidArgumentException("Ce fichier est trop volumineux, taille maximum " . $size . "M");
    }

    private function checkType($formdata, $ext, $mimes)
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($formdata['tmp_name']);
        if (
            !in_array(strtolower(pathinfo($formdata["name"], PATHINFO_EXTENSION)), $ext) ||
            !in_array($mimeType, $mimes)
        )
            throw new InvalidArgumentException("Ce fichier n'est pas au bon format");
    }

    private function isPicture($formdata)
    {
        $ext = ["jpg", "jpeg", "png", "gif", "heic", "heif"];
        $mimes = [
            "image/jpeg",
            "image/png",
            "image/gif",
            "image/heic",
            "image/heif"
        ];
        $this->checkType($formdata, $ext, $mimes);
    }

    private function isSound($formdata)
    {
        $ext = ["mp3", "aac", "ogg", "wav", "midi"];
        $mimes = [
            "audio/mpeg",
            "audio/aac",
            "audio/ogg",
            "audio/wav",
            "audio/x-wav",
            "audio/x-midi",
        ];
        $this->checkType($formdata, $ext, $mimes);
    }

    public function uploadPicture($formdata)
    {
        $this->checkSize($formdata, 7);
        $this->isPicture($formdata);

        $fileName = uniqid() . '.' . pathinfo($formdata["name"], PATHINFO_EXTENSION);
        $content = fopen($formdata["tmp_name"], "r");
        $this->storage->createBlockBlob("images", "charity_stream/$fileName", $content, $this->getBlobOption($fileName, $content));

        return $fileName;
    }

    public function getPictureUrl($fileName)
    {
        return $this->blobUrl . '/images/charity_stream/' . $fileName;
    }

    public function uploadSound($formdata)
    {
        $this->checkSize($formdata, 7);
        $this->isSound($formdata);

        $fileName = uniqid() . '.' . pathinfo($formdata["name"], PATHINFO_EXTENSION);
        $content = fopen($formdata["tmp_name"], "r");
        $this->storage->createBlockBlob("sounds", "charity_stream/$fileName", $content, $this->getBlobOption($fileName, $content));

        return $fileName;
    }

    public function getSoundUrl($fileName)
    {
        return $this->blobUrl . '/sounds/charity_stream/' . $fileName;
    }
}