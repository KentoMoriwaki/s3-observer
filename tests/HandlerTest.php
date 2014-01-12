<?php namespace Translucent\S3Observer;

use Mockery as m;

class HandlerForTest extends Handler
{

    public function deleteObjects($urls)
    {
        parent::deleteObjects($urls);
    }

}

class HandlerTest extends \PHPUnit_Framework_TestCase
{

    protected $name = 'Translucent\S3Observer\HandlerForTest';

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

    public function testKeyFromUrl()
    {
        list($client) = $this->getMocks();
        $handler = new Handler('User', $client);

        $this->assertEquals('user/profile/1.jpg',
            $handler->keyFromUrl('https://s3-ap-northeast-1.amazonaws.com/user/profile/1.jpg'));
    }

    public function testDeleteObjects()
    {
        list($client) = $this->getMocks();
        $client->shouldReceive('deleteObjects')->once()
            ->with(m::on(function($args)
            {
                if (!isset($args['Bucket']) || $args['Bucket'] != 'translucent') {
                    return false;
                }
                return $args['Objects'] == [['Key' => 'foo'], ['Key' => 'bar']];
            }));
        $handler = m::mock($this->name . '[keyFromUrl]', ['User', $client]);
        $handler->settings['bucket'] = 'translucent';
        $handler->shouldReceive('keyFromUrl')->twice()
            ->andReturn('foo', 'bar');

        $handler->deleteObjects(['foo_url', 'bar_url']);
    }

    public function testDeleting()
    {
        list($client, $model) = $this->getMocks();
        $model->shouldReceive('getAttribute')->twice()->andReturn('foo', 'bar');

        $handler = m::mock($this->name . '[deleteObjects]', ['User', $client]);
        $handler->settings['fields'] = ['foo_field', 'bar_field'];
        $handler->deleting($model);
    }

    protected function getMocks()
    {
        return [
            m::mock('Aws\S3\S3Client'),
            m::mock('Illuminate\Database\Eloquent\Model')
        ];
    }

}