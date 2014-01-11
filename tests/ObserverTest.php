<?php namespace Translucent\S3Observer;

use Mockery as m;

class ObserverTest extends \PHPUnit_Framework_TestCase
{

    public function tearDown()
    {
        m::close();
    }

    public function testInstantiation()
    {
        $modelMock = m::mock('Illuminate\Database\Eloquent\Model');
        $modelMock->shouldReceive('save')
            ->andReturn('hello world');
        $clientMock = m::mock('Aws\S3\S3Client');
        $clientMock->shouldReceive('putObject')
            ->andReturn('wow');
        $observer = new Observer($clientMock);
        $result = $observer->saving($modelMock);
        $this->assertEquals('wow', $result);
    }

}