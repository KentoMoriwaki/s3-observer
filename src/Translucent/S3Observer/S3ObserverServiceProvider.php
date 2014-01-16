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
        $this->app->bindShared('s3-observer.image', function($app)
        {
            return new ImageProcessor();
        });

        $this->app->bindShared('s3-observer', function($app)
        {
            $aws = $app['aws'];
            $config = $app['config']['s3-observer::config'];
            return new Observer($aws->get('s3'), $app['s3-observer.image'], $config);
        });
        $this->app->alias('s3-observer', 'Translucent\S3Observer\Observer');

	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('s3-observer');
	}

}