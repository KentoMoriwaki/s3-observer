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
            if ($file instanceof UploadedFile) {
                // Upload file
                $key = $this->getTargetKey($model, $field);
                $acl = $this->settings['acl'];
                if (is_null($acl)) {
                    $acl = $this->settings['public'] ? 'public-read' : 'private';
                }
                $resource = $this->client->putObject([
                    'Key' => $key,
                    'SourceFile' => $file->getRealPath(),
                    'ACL' => $acl,
                    'ContentType' => $file->getMimeType(),
                    'Bucket' => $this->settings['bucket'],
                ]);
                $model->setAttribute($field, $resource['ObjectURL']);
            }
        }
    }

    /**
     * Callback method
     * @param $model Model
     */
    public function deleting($model)
    {
        $objects = [];
        foreach ($this->settings['fields'] as $field) {
            $url = $model->getAttribute($field);
            if ($url) {
                $objects[] = [
                    'Key' => $this->getKeyFromUrl($url)
                ];
            }
        }
        if (!empty($objects)) {
            $this->client->deleteObjects([
                'Bucket' => $this->settings['bucket'],
                'Objects' => $objects,
            ]);
        }
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
        $alternativeKey = $this->getAlternativeName($model);
        return $dir . $alternativeKey . '.' . $ext;
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
     * @param $model Model
     * @return String random naem
     */
    public function getAlternativeName($model)
    {
        return str_random(20);
    }


    /**
     * @param $url String
     * @return String
     */
    public function getKeyFromUrl($url)
    {
        $key = parse_url($url, PHP_URL_PATH);
        if (starts_with($key, '/')) {
            return substr($key, 1);
        }
        return $key;
    }

}