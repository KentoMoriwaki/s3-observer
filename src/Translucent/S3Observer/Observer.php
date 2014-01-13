<?php namespace Translucent\S3Observer;

use Illuminate\Database\Eloquent\Model;
use Aws\S3\S3Client as Client;

class Observer {

    /***
     * @var Client
     */
    protected $client;

    protected $handler;

    protected static $dispatcher;

    protected static $booted = false;

    public static function boot(Dispatcher $dispatcher)
    {
        if (self::$booted) {
            return null;
        }
        self::$dispatcher = $dispatcher;
        self::$booted = true;
    }

    public function __construct($className = null)
    {
        if ($className) {
            // Register new handler.
            $handler = self::$dispatcher->register($className);
            $this->handler = $handler;
        }
    }

    public function saving(Model $model)
    {
        $handler = $this->getHandler($model);
        return $handler->saving($model);
    }

    public function deleting(Model $model)
    {
        $handler = $this->getHandler($model);
        return $handler->deleting($model);
    }

    public function getHandler($model)
    {
        $class = $this->getClassName($model);
        $handler = self::$dispatcher->get($class);
        return $handler;
    }

    public function getClassName($model)
    {
        return get_class($model);
    }

    /**
     * @param string $field
     * @param string $field,...
     */
    public function setFields($field)
    {
        if ($this->handler) {
            call_user_func_array([$this->handler, 'setFields'], func_get_args());
        }
    }

    public function config($key = null, $val = null)
    {
        if ($this->handler) {
            return $this->handler->config($key, $val);
        }
    }

}