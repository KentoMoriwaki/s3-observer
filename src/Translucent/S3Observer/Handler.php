<?php namespace Translucent\S3Observer;

use Aws\S3\S3Client as Client;

class Handler
{

    public $settings = [];

    protected $modelName;

    /**
     * @var Client
     */
    protected $client;

    public function __construct($modelName, $client)
    {
        $this->modelName = $modelName;
        $this->client = $client;
    }

    public function saving($model)
    {
        dd($this->settings['fields']);
    }

    public function deleting($model)
    {

    }

}