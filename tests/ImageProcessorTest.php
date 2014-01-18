<?php namespace Translucent\S3Observer;

use \Mockery as m;

class ImageProcessorTest extends \PHPUnit_Framework_TestCase
{

    public function tearDown()
    {
        m::close();
        parent::tearDown();
    }



}