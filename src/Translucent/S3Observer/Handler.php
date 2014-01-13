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
            $this->processField($model, $field);
        }
    }

    /**
     * @param $model Model
     * @param $field String
     * @return bool
     */
    protected function processField($model, $field)
    {
        $file = $model->getAttribute($field);
        $original = $model->getOriginal($field);

        if (is_null($file)) {
            return true;
        }

        if ($file === false) {
            // Delete file
            $this->deleteObject($original);
            $model->setAttribute($field, null);

        } else if ($this->checkTempFile($file)) {
            // Rename temp url to regular url
            $regularUrl = $this->tempToFormal($model, $field);
            $model->setAttribute($field, $regularUrl);

        } else {
            // Update field
            $url = $this->updateField($model, $field);
            $model->setAttribute($field, $url);
        }

        return true;
    }

    /**
     * @param $model Model
     * @param $field string
     * @return string
     */
    protected function updateField($model, $field)
    {
        $file = $model->getAttribute($field);
        if (!($file instanceof UploadedFile)) {
            return $file;
        }
        $key = $this->getTargetKey($model, $field);
        $original = $model->getOriginal($field);
        if ($original && $original != $key) {
            $this->deleteObject($original);
        }
        $resource = $this->client->putObject([
            'Key' => $key,
            'SourceFile' => $file->getRealPath(),
            'ACL' => $this->getAcl(),
            'ContentType' => $file->getMimeType(),
            'Bucket' => $this->settings['bucket'],
        ]);
        return $resource['ObjectURL'];
    }

    /**
     * @param $model Model
     * @param $field string
     * @return string
     */
    protected function tempToFormal($model, $field)
    {
        $file = $model->getAttribute($field);
        $tempKey = $this->keyFromUrl($file);
        $formalKey = $this->renameToFormal($tempKey, $model->getKey());
        return $this->moveObject($file, $formalKey);
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

    protected function deleteObject($url)
    {
        $this->deleteObjects([$url]);
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
     * @param $source string url of s3 object
     * @param $key string target key
     * @return String new url
     */
    protected function moveObject($source, $key)
    {
        $bucket = $this->settings['bucket'];
        $object = [
            'Bucket' => $bucket,
            'Key' => $key,
            'CopySource' => $source,
            'ACL' => $this->getAcl(),
        ];
        $this->client->copyObject($object);
        $url = $this->client->getObjectUrl($bucket, $key);
        $this->deleteObjects($source);
        return $url;
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
     * Get formal name
     */


    /**
     * Get url if a model has no key.
     * @param $model Model
     * @return String random name
     */
    public function getTempName($model = null)
    {
        return 'tmp_' . str_random(20);
    }

    /**
     * Check if a file is temporary file.
     * @param $file
     * @return bool
     */
    public function checkTempFile($file)
    {
        if (!is_string($file)) {
            return false;
        }
        $path = parse_url($file, PHP_URL_PATH);
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

    /**
     * @param $target string
     * @param $primaryKey integer|string
     * @return string
     */
    public function renameToFormal($target, $primaryKey)
    {
        $dir = dirname($target);
        $basename = basename($target);
        return $dir . '/' . preg_replace('/^[^\.]*/', $primaryKey, $basename);
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