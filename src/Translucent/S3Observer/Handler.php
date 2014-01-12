<?php namespace Translucent\S3Observer;

use Illuminate\Database\Eloquent\Model;
use Aws\S3\S3Client as Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class Handler
{

    public $settings = [];

    protected $defaults = [
        'fields' => [],
        'public' => true,
        'acl' => null,
        'base' => null,
        'bucket' => '',
    ];

    protected $modelName;

    /**
     * @var Client
     */
    protected $client;

    public function __construct($modelName, $client, $config = [])
    {
        $this->modelName = $modelName;
        $this->client = $client;
        $this->settings = array_merge($this->defaults, $config);
    }

    /**
     * Callback method
     * @param $model Model
     */
    public function saving($model)
    {
        foreach ($this->settings['fields'] as $field) {
            $file = $model->getAttribute($field);

            if (is_string($file) && $this->checkTempName($file)) {
                // When url is temp name, move to name with primary key.
                $fromKey = $this->keyFromUrl($file);
                $newKey = $this->replaceBasename($fromKey, $model->getKey());
                $newUrl = $this->moveObject($file, $newKey);
                $model->setAttribute($field, $newUrl);

            } else if ($file instanceof UploadedFile) {
                // Upload file
                $key = $this->getTargetKey($model, $field);
                // Get original url
                $original = $model->getOriginal($field);
                if ($original && $original != $key) {
                    $this->deleteObjects([$original]);
                }
                $resource = $this->client->putObject([
                    'Key' => $key,
                    'SourceFile' => $file->getRealPath(),
                    'ACL' => $this->getAcl(),
                    'ContentType' => $file->getMimeType(),
                    'Bucket' => $this->settings['bucket'],
                    ''
                ]);
                $model->setAttribute($field, $resource['ObjectURL']);

            } else if ($file === false) {
                // Delete file
                $original = $model->getOriginal($field);
                $this->deleteObjects([$original]);
                $model->setAttribute($field, null);
            }
        }
    }

    /**
     * Callback method
     * @param $model Model
     */
    public function deleting($model)
    {
        $urls = [];
        foreach ($this->settings['fields'] as $field) {
            $url = $model->getAttribute($field);
            if ($url) {
                $urls[] = $url;
            }
        }
        if (!empty($urls)) {
            $this->deleteObjects($urls);
        }
    }

    /**
     * Delete objects from S3
     * @param $urls
     */
    protected function deleteObjects($urls)
    {
        $objects = array_map(function($url)
        {
            return ['Key' => $this->keyFromUrl($url)];
        }, $urls);

        $this->client->deleteObjects([
            'Bucket' => $this->settings['bucket'],
            'Objects' => $objects,
        ]);
    }

    /**
     * Move object on S3
     * @param $fromKey
     * @param $toKey
     * @return String new url
     */
    protected function moveObject($fromKey, $toKey)
    {
        $bucket = $this->settings['bucket'];
        $object = [
            'Bucket' => $bucket,
            'Key' => $toKey,
            'CopySource' => $fromKey,
            'ACL' => $this->getAcl(),
        ];
        $this->client->copyObject($object);
        $url = $this->client->getObjectUrl($bucket, $toKey);
        $this->deleteObjects([$fromKey]);
        return $url;
    }

    public function putObject()
    {

    }

    /**
     * @param $model Model
     * @param $field String
     * @return String key for S3
     */
    public function getTargetKey($model, $field)
    {
        $dir = $this->getTargetDir($field);
        $ext = $this->getTargetExtension($model->getAttribute($field));
        $primaryKey = $model->getKey();
        if ($primaryKey) {
            return $dir . $primaryKey . '.' . $ext;
        }
        // Get unique key for model without key
        $bucket = $this->settings['bucket'];
        $key = $dir . $this->getTempName($model) . '.' . $ext;
        while ($this->client->doesObjectExist($bucket, $key)) {
            $key = $dir . $this->getTempName($model) . '.' . $ext;
        }
        return $key;
    }

    public function getTargetDir($field)
    {
        $snakedModelName = snake_case($this->modelName);
        $dir = preg_replace('/(\/\_)|(\\\_)/', '/', $snakedModelName);
        $dir = str_finish($this->settings['base'], '/') . str_finish($dir, '/') . snake_case($field) . '/';
        if (!starts_with($dir, '/')) {
            $dir = '/' . $dir;
        }
        return $dir;
    }

    /**
     * @param $file UploadedFile
     * @return String
     */
    public function getTargetExtension($file)
    {
        return $file->guessExtension() ?: $file->guessClientExtension();
    }

    /**
     * Get url if a model has no key.
     * @param $model Model
     * @return String random name
     */
    public function getTempName($model)
    {
        return 'tmp_' . str_random(20);
    }

    /**
     * Check if a url is temp name.
     * @param $url
     * @return bool
     */
    public function checkTempName($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $slugs = explode('/', $path);
        return starts_with(array_pop($slugs), 'tmp_');
    }

    /**
     * @param $url String
     * @return String
     */
    public function keyFromUrl($url)
    {
        $key = parse_url($url, PHP_URL_PATH);
        if ($key === false) {
            return $url;
        }
        if (starts_with($key, '/')) {
            return substr($key, 1);
        }
        return $key;
    }

    public function replaceBasename($target, $replace)
    {
        $dir = dirname($target);
        $basename = basename($target);
        return $dir . '/' . preg_replace('/^[^\.]*/', $replace, $basename);
    }

    public function getAcl()
    {
        $acl = $this->settings['acl'];
        if (is_null($acl)) {
            return $this->settings['public'] ? 'public-read' : 'private';
        }
        return $acl;
    }

}