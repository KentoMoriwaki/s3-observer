<?php namespace Translucent\S3Observer;

use Mockery as m;

class HandlerTest extends \PHPUnit_Framework_TestCase
{

    protected $name = 'Translucent\S3Observer\Handler';

    public function tearDown()
    {
        m::close();
        parent::tearDown();
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

    public function testGetS3KeyFromObjectURL()
    {
        list($client) = $this->getMocks();
        $handler = new Handler('User', $client);

        $this->assertEquals('user/profile/1.jpg',
            $handler->keyFromUrl('https://s3-ap-northeast-1.amazonaws.com/user/profile/1.jpg'));

        $this->assertEquals('user/profile/1.jpg',
            $handler->keyFromUrl('/user/profile/1.jpg'));
    }

    public function testArgsAreProperlyPassedToDeleteObjectsMethod()
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

        // Invoke by reflection
        $ref = new \ReflectionClass($handler);
        $method = $ref->getMethod('deleteObjects');
        $method->setAccessible(true);
        $method->invoke($handler, ['foo_url', 'bar_url']);
    }

    public function testDeleting()
    {
        list($client, $model) = $this->getMocks();
        $model->shouldReceive('getAttribute')->twice()->andReturn('foo', 'bar');

        $handler = $this->getMock($this->name, ['deleteObjects'], ['User', $client]);
        $handler->settings['fields'] = ['foo_field', 'bar_field'];
        $handler->expects($this->once())->method('deleteObjects')->with(['foo', 'bar']);
        $handler->deleting($model);
    }

    public function testGetAcl()
    {
        list($client) = $this->getMocks();
        $class = $this->name;
        $handler = new $class('User', $client);

        $handler->settings['public'] = true;
        $this->assertEquals('public-read', $handler->getAcl());

        $handler->settings['public'] = false;
        $this->assertEquals('private', $handler->getAcl());

        $handler->settings['acl'] = 'public-read-write';
        $handler->settings['public'] = true;
        $this->assertEquals('public-read-write', $handler->getAcl());
    }

    public function testRenameToFormal()
    {
        list($client) = $this->getMocks();
        $class = $this->name;
        $handler = new $class('User', $client);

        $this->assertEquals('user/profile/1.jpg',
            $handler->renameToFormal('user/profile/tmp_abcdef.jpg', 1));
    }

    public function testGetTempName()
    {
        list($client) = $this->getMocks();
        $class = $this->name;
        $handler = new $class('User', $client);

        $this->assertStringStartsWith('tmp_', $handler->getTempName());
    }

    public function testCheckTempFile()
    {
        list($client) = $this->getMocks();
        $class = $this->name;
        $handler = new $class('User', $client);

        $this->assertTrue($handler->checkTempFile('user/profile/tmp_abcdef.jpg'));
        $this->assertFalse($handler->checkTempFile('user/profile/1.jpg'));
        $this->assertFalse($handler->checkTempFile(null));
    }

    protected function getMocks()
    {
        return [
            m::mock('Aws\S3\S3Client'),
            m::mock('Illuminate\Database\Eloquent\Model')
        ];
    }

}