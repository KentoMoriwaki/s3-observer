<?php namespace Translucent\S3Observer;

use Mockery as m;

class HandlerTest extends \PHPUnit_Framework_TestCase
{

    public function tearDown()
    {
        m::close();
    }


    public function testGetTargetDir()
    {
        list($client) = $this->getMocks();

        $handler = new Handler('User', $client);
        $this->assertEquals('/user/profile/', $handler->getTargetDir('profile'));

        $handler = new Handler('Translucent/S3Observer/TestModel', $client);
        $this->assertEquals(
            '/translucent/s3_observer/test_model/cover_image/',
            $handler->getTargetDir('cover_image'));

        $handler = new Handler('Translucent\S3Observer\TestModel', $client);
        $this->assertEquals(
            '/translucent/s3_observer/test_model/cover_image/',
            $handler->getTargetDir('coverImage'));

        $handler = new Handler('User', $client);
        $handler->settings['base'] = 'hello';
        $this->assertEquals(
            '/hello/user/profile/',
            $handler->getTargetDir('profile'));
    }


    public function testGetTargetKey()
    {
        list($client, $model) = $this->getMocks();
        $model->shouldReceive('getAttribute')
            ->andReturn(null);
        $model->shouldReceive('getKey')
            ->once()
            ->andReturn(10);

        $handler = m::mock('Translucent\S3Observer\Handler[getTargetDir,getTargetExtension]',
            ['User', $client]);
        $handler->shouldReceive('getTargetExtension')->once()->andReturn('jpg');
        $handler->shouldReceive('getTargetDir')->once()->andReturn('user/');

        $key = $handler->getTargetKey($model, 'profile');
        $this->assertEquals('user/10.jpg', $key);
    }


    protected function getMocks()
    {
        return [
            m::mock('Aws\S3\S3Client'),
            m::mock('Illuminate\Database\Eloquent\Model')
        ];
    }

}