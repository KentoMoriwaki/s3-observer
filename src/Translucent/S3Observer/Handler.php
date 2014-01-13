<?php namespace Translucent\S3Observer;

use Illuminate\Database\Eloquent\Model;
use Aws\S3\S3Client as Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class Handler
{

    protected $settings = ['fields' => []];

    protected $fields = [];

    protected $modelName;

    protected static $globalConfig = [];

    /**
     * @var Client
     */
    protected $client;

    public function __construct($modelName, $client)
    {
        $this->modelName = $modelName;
        $this->client = $client;
    }

    /**
     * Callback method
     * @param Model $model
     */
    public function saving($model)
    {
        foreach ($this->fields as $field) {
            $this->processField($model, $field);
        }
    }

    /**
     * @param Model $model
     * @param string $field
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
            $this->deleteObject($original, $this->fieldSettings($field, 'bucket'));
            $model->setAttribute($field, null);

        } else if ($this->checkTempFile($file)) {
            // Rename temp url to regular url
            $regularUrl = $this->tempToFormal($model, $field);
            $model->setAttribute($field, $regularUrl);

        } else {
            // Update field
            $url = $this->upload($model, $field);
            $model->setAttribute($field, $url);
        }

        return true;
    }

    /**
     * @param Model $model
     * @param string $field
     * @return string
     */
    protected function upload($model, $field)
    {
        $file = $model->getAttribute($field);
        if (!($file instanceof UploadedFile)) {
            return $file;
        }
        $settings = $this->fieldSettings($field);
        $key = $this->getTargetKey($model, $field, $settings);
        $original = $model->getOriginal($field);
        if ($original && $original != $key) {
            $this->deleteObject($original, $settings['bucket']);
        }
        $resource = $this->client->putObject([
            'Key' => $key,
            'SourceFile' => $file->getRealPath(),
            'ACL' => $this->getAcl($settings),
            'ContentType' => $file->getMimeType(),
            'Bucket' => $settings['bucket'],
        ]);
        return $resource['ObjectURL'];
    }

    /**
     * @param Model $model
     * @param string $field
     * @return string
     */
    protected function tempToFormal($model, $field)
    {
        $file = $model->getAttribute($field);
        $settings = $this->fieldSettings($field);
        $tempKey = $this->keyFromUrl($file);
        $formalKey = $this->renameToFormal($tempKey, $model->getKey());
        return $this->moveObject($file, $formalKey, $settings);
    }

    /**
     * Callback method
     * @param Model $model
     */
    public function deleting($model)
    {
        $objects = [];
        foreach ($this->fields as $field) {
            $bucket = $this->fieldSettings($field, 'bucket');
            $url = $model->getAttribute($field);
            if ($url) {
                if (isset($objects[$bucket])) {
                    $objects[$bucket][] = $url;
                } else {
                    $objects[$bucket] = [$url];
                }
            }
        }
        foreach ($objects as $bucket => $urls) {
            $this->deleteObjects($urls, $bucket);
        }
    }

    /**
     * Delete object from S3
     * @param string $url
     * @param string $bucket
     */
    protected function deleteObject($url, $bucket)
    {
        $this->deleteObjects([$url], $bucket);
    }

    /**
     * Delete objects from S3
     * @param array $urls
     * @param string $bucket
     */
    protected function deleteObjects($urls, $bucket)
    {
        $objects = array_map(function($url)
        {
            return ['Key' => $this->keyFromUrl($url)];
        }, $urls);
        $this->client->deleteObjects([
            'Bucket' => $bucket,
            'Objects' => $objects,
        ]);
    }

    /**
     * Move object on S3
     * @param string $source url of s3 object
     * @param string $key target key
     * @param array $settings
     * @return string new url
     */
    protected function moveObject($source, $key, $settings)
    {
        $bucket = $settings['bucket'];
        $object = [
            'Bucket' => $bucket,
            'Key' => $key,
            'CopySource' => $source,
            'ACL' => $this->getAcl($settings),
        ];
        $this->client->copyObject($object);
        $url = $this->client->getObjectUrl($bucket, $key);
        $this->deleteObject($source, $settings['bucket']);
        return $url;
    }

    /**
     * @param Model $model
     * @param string $field
     * @param array $settings
     * @return string key for S3
     */
    public function getTargetKey($model, $field, $settings)
    {
        $dir = $this->getTargetDir($field, $settings['base']);
        $ext = $this->getTargetExtension($model->getAttribute($field));
        $primaryKey = $model->getKey();
        if ($primaryKey) {
            return $dir . $primaryKey . '.' . $ext;
        }
        // Get unique key for model without key
        $key = $dir . $this->getTempName($model) . '.' . $ext;
        while ($this->client->doesObjectExist($settings['bucket'], $key)) {
            $key = $dir . $this->getTempName($model) . '.' . $ext;
        }
        return $key;
    }

    /**
     * @param $field
     * @param string $base
     * @return mixed|string
     */
    public function getTargetDir($field, $base = '')
    {
        $snakedModelName = snake_case($this->modelName);
        $dir = preg_replace('/(\/\_)|(\\\_)/', '/', $snakedModelName);
        $dir = str_finish($base, '/') . str_finish($dir, '/') . snake_case($field) . '/';
        if (!starts_with($dir, '/')) {
            $dir = '/' . $dir;
        }
        return $dir;
    }

    /**
     * @param UploadedFile $file
     * @return string
     */
    public function getTargetExtension($file)
    {
        return $file->guessExtension() ?: $file->guessClientExtension();
    }

    /**
     * Get url if a model has no key.
     * @param Model $model
     * @return string random name
     */
    public function getTempName($model = null)
    {
        return 'tmp_' . str_random(20);
    }

    /**
     * Check if a file is temporary file.
     * @param mixed $file
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
     * @param string $url
     * @return string
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
     * @param string $target
     * @param integer|string $primaryKey
     * @return string
     */
    public function renameToFormal($target, $primaryKey)
    {
        $dir = dirname($target);
        $basename = basename($target);
        return $dir . '/' . preg_replace('/^[^\.]*/', $primaryKey, $basename);
    }

    /**
     * @param array $settings
     * @return string
     */
    public function getAcl($settings)
    {
        $acl = $settings['acl'];
        if (is_null($acl)) {
            return $settings['public'] ? 'public-read' : 'private';
        }
        return $acl;
    }

    public static function setGlobalConfig($config)
    {
        static::$globalConfig = $config;
    }

    public function setFields($field)
    {
        $fields = func_get_args();
        $this->fields += $fields;
    }

    public function config($key = null, $val = null)
    {
        if (!$key) {
            return $this->settings;
        }
        if (!$val) {
            return array_get($this->settings, $key);
        }
        array_set($this->settings, $key, $val);
    }

    /**
     * @param string $field
     * @param null $key
     * @return array|mixed
     */
    protected function fieldSettings($field, $key = null)
    {
        $fieldSettings = $this->config('fields.' . $field);
        if (empty($fieldSettings)) {
            $fieldSettings = [];
        }
        $modelSettings = array_except($this->config(), ['fields']);
        $settings = array_merge(static::$globalConfig, $modelSettings, $fieldSettings);
        if ($key) {
            return array_get($settings, $key);
        }
        return $settings;
    }

}