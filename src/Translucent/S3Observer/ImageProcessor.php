<?php namespace Translucent\S3Observer;

use Intervention\Image\Image;

class ImageProcessor
{

    public function __construct()
    {

    }

    public function process($src, $config = array())
    {
        $src = $this->copyToTemp($src);
        $image = new Image($src);
        $this->resize($image, $config);
        $image->save();
        return $image->dirname . '/' . $image->basename;
    }

    protected function copyToTemp($src)
    {
        $tmpFile = tempnam(null, null);
        copy($src, $tmpFile);
        return $tmpFile;
    }

    /**
     * @param Image $image
     * @param array $config
     * @return Image processed
     */
    protected function resize($image, $config)
    {
        $defaults = [
            'width' => null,
            'height' => null,
            'callback' => function ($image) {}
        ];
        $config = $config + $defaults;
        if (empty($config['width']) || empty($config['height'])) {

            $image->resize($config['width'], $config['height'], true);

        } else if ($config['width'] && $config['height']) {

            $ratio = $config['width'] / $config['height'];
            $originalRatio = $image->width / $image->height;
            if ($ratio < $originalRatio) {
                $image->crop($ratio * $image->height, $image->height);
                $image->resize($config['width'], $config['height'], true);
            } else {
                $image->crop($image->width, $image->width / $ratio);
                $image->resize($config['width'], $config['height'], true);
            }

        }

        $callback = $config['callback'];
        $image = $callback($image) ?: $image;
        return $image;
    }

}