<?php namespace Translucent\S3Observer;

use \Mockery as m;
use \Intervention\Image\Image;

class ImageProcessorTest extends \PHPUnit_Framework_TestCase
{

    public function tearDown()
    {
        m::close();
        $copied = dirname(__FILE__) . '/copied.gif';
        if (file_exists($copied)) {
            unlink($copied);
        }
        copy($this->sample(), $copied);
        parent::tearDown();
    }

    public function testCopyToTemp()
    {
        $path = $this->sample();
        $processor = new ImageProcessor;
        $copied = $processor->copyToTemp($path);
        $this->assertFileExists($copied);
        $this->assertFileExists($path);
        $this->assertFileEquals($copied, $path);
        $this->assertNotEquals($copied, $path);
    }

    public function testSrcIsCopiedBeforeProcessed()
    {
        $processor = $this->getMock('Translucent\S3Observer\ImageProcessor', ['copyToTemp']);
        $path = $this->sample();
        $processor->expects($this->once())->method('copyToTemp')
            ->with($path)->will($this->returnValue(dirname(__FILE__) . '/copied.gif'));
        $processor->process($path);
    }

    public function testCallbackIsCalledAfterResize()
    {
        $processor = new ImageProcessor;
        $called = false;
        $callback = function ($image) use (&$called) {
            $called = true;
        };
        $processor->resize(dirname(__FILE__) . '/copied.gif', [
                'callback' => $callback
            ]);

        $this->assertTrue($called);
    }

    public function testResizeToSquare()
    {
        $processor = new ImageProcessor;
        $path = dirname(__FILE__) . '/copied.gif';
        $image = $processor->resize(new Image($path), [
            'width'=> 100,
            'height' => 100,
        ]);
        $this->assertEquals(100, $image->width);
        $this->assertEquals(100, $image->height);
    }

    protected function sample()
    {
        return dirname(__FILE__) . '/sample.gif';
    }


}