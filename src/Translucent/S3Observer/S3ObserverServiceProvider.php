<?php namespace Translucent\S3Observer;

use Illuminate\Support\ServiceProvider;

class S3ObserverServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

    public function boot()
    {
        $this->package('translucent/s3-observer', 's3-observer');
    }

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{

        $this->app->bindShared('s3-observer.dispatcher', function($app)
        {
            $aws = $app['aws'];
            $config = $app['config']['s3-observer'] ?: $app['config']['s3-observer::config'];
            return new Dispatcher($aws->get('s3'), $config);
        });

		$this->app->bindShared('s3-observer.factory', function($app)
        {
            Observer::boot($app['s3-observer.dispatcher']);
            return new ObserverFactory();
        });

	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('s3-observer.factory');
	}

}