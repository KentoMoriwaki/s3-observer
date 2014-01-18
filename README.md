# Observer to sync your Eloquent with Amazon S3

You can easily upload and delete images by S3Observer

```php
$user = User::find($id);
$user->fill(Input::all());
if (Input::hasFile('profile_image')) {
    $user->profile_image = Input::file('profile_image');
}
if (Input::has('delete_profile_image')) {
    $user->profile_image = null;
}
$user->save();
```

Now the uploaded file is on Amazon S3!

## How To Use

### Before

You need setup [aws/aws-sdk-php-laravel](https://github.com/aws/aws-sdk-php-laravel) before use S3Observer

###	Installation

Require the `translucent/s3-observer` in your composer.json

```json
{
    "require": {
        "translucent/s3-observer": "0.4.*"
    }
}
```

### Configuration

In order to use S3Observer, update settings files.

```bash
php artisan config:publish translucent/s3-observer
```

Sample configuration

```php
return array(
    'public' => true,
    'bucket' => '',
    'base' => null,
    'acl' => null,
);
```

Add S3Observer provider and facade(optional) to app/config/app.php

```php
'providers' => array(
     // ...
    'Translucent\S3Observer\S3ObserverServiceProvider',
),
'aliases' => array(
    // ...
    'Translucent\S3Observer\Facades\S3Observer',
)
```

## Sample Usage

In your model,

```php
protected static function boot()
{
    parent::boot();
    // Setup observer
    $observer = S3Observer::setUp('User', array(
        'bucket' => 'user-bucket'
    ));
    // Observe fields
    $observer->setFields('profile_image', 'thumbnail');
    // Fields configuration
    $observer->config('thumbnail.image', array(
        'width' => 150,
        'height' => 150
    ));
    static::observe($observer);
}
```

And your controller

```php
public function postEdit($id)
{
    $user = User::findOrFail($id);
    $user->fill(Input::all());
    if (Input::hasFile('profile_image')) {
        $user->profile_image = Input::file('profile_image');
        $user->thumbnail = Input::file('profile_image');
    }
    if (Input::has('delete_profile_image')) {
        $user->profile_image = null;
				$user->thumbnail = null;
    }
    $user->save();
    return Redirect::to('/');
}
```

## More information

To see more information, please check [Wiki](https://github.com/KentoMoriwaki/s3-observer/wiki)!
