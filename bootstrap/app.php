<?php

use BeyondCode\LaravelWebSockets\WebSocketsServiceProvider;

require_once __DIR__.'/../vendor/autoload.php';

try {
    (Dotenv\Dotenv::create(__DIR__ . '/../'))->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    //
}
    
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

$app->withFacades();

$app->withEloquent();
class_alias(\LaravelFCM\Facades\FCM::class, 'FCM');
class_alias(\LaravelFCM\Facades\FCMGroup::class, 'FCMGroup');
/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
|
| Next, we will register the middleware with the application. These can
| be global middleware that run before and after each request into a
| route or middleware that'll be assigned to some specific routes.
|
*/

/*// $app->middleware([
//     App\Http\Middleware\ExampleMiddleware::class
// ]);*/

$app->routeMiddleware([
    'auth' => App\Http\Middleware\Authenticate::class,
]);

$app->routeMiddleware([
    'csrf' => 'Laravel\Lumen\Http\Middleware\VerifyCsrfToken'
]);

/** Added this for notification */
$app->withFacades(true, [
    'Illuminate\Support\Facades\Notification' => 'Notification',
]); 
/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| Here we will register all of the application's service providers which
| are used to bind services into the container. Service providers are
| totally optional, so you are not required to uncomment this line.
|
*/



$app->configure('session');
$app->bind(Illuminate\Session\SessionManager::class, function ($app) {
    return $app->make('session');
});

$app->middleware([
    'Illuminate\Session\Middleware\StartSession'
]);
$app->register(Illuminate\Session\SessionServiceProvider::class);



$app->configure('broadcasting');
$app->register(WebSocketsServiceProvider::class);
//$app->register(\BeyondCode\LaravelWebSockets\WebSocketsServiceProvider::class);

$app->register(App\Providers\MyBroadcastServiceProvider::class);
$app->register(App\Providers\AppServiceProvider::class);
$app->register(App\Providers\AuthServiceProvider::class);
$app->register(App\Providers\EventServiceProvider::class);

$app->register(Tymon\JWTAuth\Providers\LumenServiceProvider::class);
$app->register(Illuminate\Mail\MailServiceProvider::class);

/** Added this for notification */
$app->register(Illuminate\Notifications\NotificationServiceProvider::class);
$app->register(Davibennun\LaravelPushNotification\LaravelPushNotificationServiceProvider::class);
$app->register(LaravelFCM\FCMServiceProvider::class);
/** Added this for mail */
$app->configure('mail');
$app->configure('fcm');
$app->alias('mailer', Illuminate\Mail\Mailer::class);
$app->alias('mailer', Illuminate\Contracts\Mail\Mailer::class);
$app->alias('mailer', Illuminate\Contracts\Mail\MailQueue::class);


$app->configure('push-notification');
$app->alias('PushNotification',Davibennun\LaravelPushNotification\Facades\PushNotification::class);

$app->alias('Notification', Illuminate\Support\Facades\Notification::class);
/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Next we will include the routes file so that they can all be added to
| the application. This will provide all of the URLs the application
| can respond to, as well as the controllers that may handle them.
|
*/

$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    require __DIR__.'/../routes/web.php';
});

return $app;