<?php namespace Translucent\S3Observer;

use Translucent\S3Observer\ImageProcessor;
use Illuminate\Database\Eloquent\Model;
use Aws\S3\S3Client as Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class Observer
{

    protected $globalConfig;
    protected $config = [];
    protected $fields = [];
    protected $modelName = null;

    /**
     * Aws S3 client
     * @var Client
     */
    protected $client;

    /**
     * @var ImageProcessor
     */
    protected $imageProcessor;

    /**
     * @param Client $client
     * @param ImageProcessor $processor
     * @param array $config
     */
    public function __construct(Client $client, ImageProcessor $processor, $config = [])
    {
        $this->client = $client;
        $this->imageProcessor = $processor;
        $this->globalConfig = $config;
    }

    /**
     * For facade
     * @param $model
     * @param array $config
     * @return $this
     */
    public function setUp($model, $config = null)
    {
        if (!is_string($model)) {
            $model = get_class($model);
        }
        $this->modelName = $model;

        if ($config) {
            $config['_'] = [];
            $this->config[$model] = $config;
        }
        if (!isset($this->fields[$model])) {
            $this->fields[$model] = [];
        }
        return $this;
    }

    /**
     * Config settings
     */
    public function config($key, $val = null)
    {
        $arrayKey = $this->modelName . '._.' . $key;
        // Getter
        if (is_null($key)) {
            return array_get($this->config, $arrayKey);
        }
        array_set($this->config, $arrayKey, $val);
    }

    public function fieldConfig($field)
    {
        $modelConfig = array_except($this->config[$this->modelName], '_');
        if (isset($this->config[$this->modelName]['_'][$field])) {
            $fieldConfig = $this->config[$this->modelName]['_'][$field];
            return array_merge($this->globalConfig, $modelConfig, $fieldConfig);
        }
        return array_merge($this->globalConfig, $modelConfig);
    }

    /**
     * Set fields of current model
     * @param string $field
     * @param $field,...
     */
    public function setFields($field)
    {
        $fields = func_get_args();
        $this->fields[$this->modelName] = $fields;
    }

    /**
     * Get fields off current model
     */
    public function getFields()
    {
        return $this->fields[$this->modelName];
    }

    /**
     * Callback method
     * @param Model $model
     */
    public function saving($model)
    {
        $this->setUp($model);
        foreach ($this->getFields() as $field) {
            $this->processField($model, $field);
        }
    }

    /**
     * Callback method
     * @param Model $model
     */
    public function deleting($model)
    {
        $this->setUp($model);
        $objects = [];
        foreach ($this->getFields() as $field) {
            $conf = $this->fieldConfig($field);
            $bucket = $conf['bucket'];
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

        if (empty($file) && $original) {
            // Delete file
            $config = $this->fieldConfig($field);
            $this->deleteObject($original, $config['bucket']);
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
        $config = $this->fieldConfig($field);
        // Resize process
        $url = $file->getRealPath();
        if (isset($config['image'])) {
            $url = $this->imageProcessor->process($file->getRealPath(), $config['image']);
        }

        $key = $this->getTargetKey($model, $field, $config);
        $original = $model->getOriginal($field);
        if ($original && $original != $key) {
            $this->deleteObject($original, $config['bucket']);
        }
        $resource = $this->client->putObject([
            'Key' => $key,
            'SourceFile' => $url,
            'ACL' => $this->getAcl($config),
            'ContentType' => $file->getMimeType(),
            'Bucket' => $config['bucket'],
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
        $config = $this->fieldConfig($field);
        $tempKey = $this->keyFromUrl($file);
        $formalKey = $this->renameToFormal($tempKey, $model->getKey());
        return $this->moveObject($file, $formalKey, $config);
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
     * @param array $config
     * @return string key for S3
     */
    public function getTargetKey($model, $field, $config)
    {
        $dir = $this->getTargetDir($field, $config['base']);
        $ext = $this->getTargetExtension($model->getAttribute($field));
        $primaryKey = $model->getKey();
        if ($primaryKey) {
            return $dir . $primaryKey . '.' . $ext;
        }
        // Get unique key for model without key
        $key = $dir . $this->getTempName($model) . '.' . $ext;
        while ($this->client->doesObjectExist($config['bucket'], $key)) {
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
     * @param array $config
     * @return string
     */
    public function getAcl($config)
    {
        $acl = $config['acl'];
        if (is_null($acl)) {
            return $config['public'] ? 'public-read' : 'private';
        }
        return $acl;
    }


}