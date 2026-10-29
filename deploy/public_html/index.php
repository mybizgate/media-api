<?php

/*
|--------------------------------------------------------------------------
| MyBizGate Media API - production public entry point
|--------------------------------------------------------------------------
|
| Shared-hosting layout (same approach as client-api.mybizgate.com):
|
|   document root : /home/<account>/public_html/media-api   (this file + .htaccess)
|   application   : /home/<account>/app/media-api           (Laravel, outside the web root)
|   media storage : /home/<account>/data/media-api/uploads  (MEDIA_STORAGE_PATH in .env)
|
| The application directory is resolved relative to the account home, so no
| absolute path is hard-coded. Set MEDIA_API_APP_PATH in the server
| environment to override it.
|
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = getenv('MEDIA_API_APP_PATH') ?: dirname(__DIR__, 2).'/app/media-api';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $appPath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
