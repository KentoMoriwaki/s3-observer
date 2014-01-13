<?php namespace Translucent\S3Observer;

use Aws\S3\S3Client;

class Dispatcher
{

    protected $handlers = [];
    protected $client;

    public function __construct(S3Client $client, $config)
    {
        $this->client = $client;
        Handler::setGlobalConfig($config);
    }

    public function register($modelName)
    {
        if (isset($this->handlers[$modelName])) {
            return $this->handlers[$modelName];
        }
        $handler = new Handler($modelName, $this->client);
        $this->handlers[$modelName] = $handler;
        return $handler;
    }

    public function get($modelName)
    {
        if (!isset($this->handlers[$modelName])) {
            throw new \Exception("No handler is set for ${modelName} model");
        }
        return $this->handlers[$modelName];
    }

}