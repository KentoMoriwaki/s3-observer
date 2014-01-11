<?php namespace Translucent\S3Observer;


class ObserverFactory
{

    public function __construct()
    {

    }

    /***
     * @param $className
     * @param array $options
     * @return Observer
     */
    public function make($className, $options = [])
    {
        $observer = new Observer($className);
        return $observer;
    }

}