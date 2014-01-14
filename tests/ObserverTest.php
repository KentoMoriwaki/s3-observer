<?php namespace Translucent\S3Observer;

use Mockery as m;

class ObserverTest extends \PHPUnit_Framework_TestCase
{

    protected $name = 'Translucent\S3Observer\Observer';

    public function tearDown()
    {
        m::close();
        parent::tearDown();
    }

    public function testConfig()
    {
        list($client) = $this->getMocks();
        $globalConfig = [
            'bucket' => 'translucent',
            'base' => null,
            'public' => true,
        ];
        $userModelConfig = [
            'bucket' => 'user-bucket'
        ];
        $itemModelConfig = [
            'base' => 'item'
        ];
        $observer = new Observer($client, $globalConfig);
        $observer->setUp('User', $userModelConfig);

        $observer->config('profile.public', false);
        $result = $observer->fieldConfig('profile');
        $this->assertEquals('user-bucket', $result['bucket']);
        $this->assertNull($result['base']);
        $this->assertFalse($result['public']);

        $observer->setUp('Item', $itemModelConfig);
        $result = $observer->fieldConfig('image');
        $this->assertEquals('item', $result['base']);
    }

    public function testAllFieldsAreChecked()
    {
        list($client, $model) = $this->getMocks();
        $observer = $this->getMock($this->name, ['processField', 'getFields'], [$client]);
        $observer->expects($this->exactly(3))->method('processField');
        $observer->expects($this->once())->method('getFields')->will($this->returnValue(['profile', 'cover_image', 'icon']));

        $observer->saving($model);
    }

    public function testGetTargetDir()
    {
        list($client) = $this->getMocks();

        $observer = new Observer($client);
        $observer->setUp('User');
        $this->assertEquals('/user/profile/', $observer->getTargetDir('profile'));

        $observer->setUp('Translucent/S3Observer/TestModel');
        $this->assertEquals(
            '/translucent/s3_observer/test_model/cover_image/',
            $observer->getTargetDir('cover_image'));

        $this->assertEquals(
            '/translucent/s3_observer/test_model/cover_image/',
            $observer->getTargetDir('coverImage'));

        $observer->setUp('User');
        $this->assertEquals(
            '/hello/user/profile/',
            $observer->getTargetDir('profile', 'hello'));
    }

    public function testGetS3KeyFromObjectURL()
    {
        list($client) = $this->getMocks();
        $observer = new Observer($client);
        $observer->setUp('User');

        $this->assertEquals('user/profile/1.jpg',
            $observer->keyFromUrl('https://s3-ap-northeast-1.amazonaws.com/user/profile/1.jpg'));

        $this->assertEquals('user/profile/1.jpg',
            $observer->keyFromUrl('/user/profile/1.jpg'));
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
        $observer = m::mock($this->name . '[keyFromUrl]', [$client]);
        $observer->shouldReceive('keyFromUrl')->twice()
            ->andReturn('foo', 'bar');

        // Invoke by reflection
        $ref = new \ReflectionClass($observer);
        $method = $ref->getMethod('deleteObjects');
        $method->setAccessible(true);
        $method->invoke($observer, ['foo_url', 'bar_url'], 'translucent');
    }

    public function testDeleting()
    {
        list($client, $model) = $this->getMocks();
        $model->shouldReceive('getAttribute')->twice()->andReturn('foo', 'bar');

        $observer = $this->getMock($this->name, ['deleteObjects'], [$client]);
        $observer->setUp($model, ['bucket' => 'test']);
        $observer->setFields('foo_field', 'bar_field');
        $observer->expects($this->once())->method('deleteObjects')->with(['foo', 'bar']);
        $observer->deleting($model);
    }

    public function testGetAcl()
    {
        list($client) = $this->getMocks();
        $handler = new Observer($client);

        $settings = ['public' => true, 'acl' => null];
        $this->assertEquals('public-read', $handler->getAcl($settings));

        $settings['public'] = false;
        $this->assertEquals('private', $handler->getAcl($settings));

        $settings['acl'] = 'public-read-write';
        $settings['public'] = true;
        $this->assertEquals('public-read-write', $handler->getAcl($settings));
    }

    public function testRenameToFormal()
    {
        list($client) = $this->getMocks();
        $handler = new Observer($client);

        $this->assertEquals('user/profile/1.jpg',
            $handler->renameToFormal('user/profile/tmp_abcdef.jpg', 1));
    }

    public function testGetTempName()
    {
        list($client) = $this->getMocks();
        $handler = new Observer($client);

        $this->assertStringStartsWith('tmp_', $handler->getTempName());
    }

    public function testCheckTempFile()
    {
        list($client) = $this->getMocks();
        $handler = new Observer($client);

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