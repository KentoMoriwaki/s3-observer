<?php namespace Translucent\S3Observer\Facades;

use Illuminate\Support\Facades\Facade;

class S3Observer extends Facade
{

    protected static function getFacadeAccessor()
    {
        return 's3-observer';
    }

}