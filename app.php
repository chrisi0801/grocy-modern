<?php

use Grocy\Controllers\ExceptionController;
use Grocy\Helpers\SlimBladeView;
use Grocy\Helpers\UrlManager;
use Grocy\Middleware\LocaleMiddleware;
use Psr\Container\ContainerInterface as Container;
use Slim\Factory\AppFactory;

// Load composer dependencies
require_once __DIR__ . '/packages/autoload.php';

// Load config files
require_once GROCY_DATAPATH . '/config.php';
require_once __DIR__ . '/config-dist.php'; // For not in own config defined values we use the default ones

// Grocy Modern: which profile (= which separate household) this request is
// for - before anything touches a database
Grocy\Helpers\Profiles::Bootstrap();

// Error reporting definitions
if (GROCY_MODE === 'dev')
{
	error_reporting(E_ALL);
}
else
{
	error_reporting(E_ALL ^ (E_NOTICE | E_WARNING | E_DEPRECATED));
}

// Definitions for dev/demo/prerelease mode
if ((GROCY_MODE === 'dev' || GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease') && !defined('GROCY_USER_ID'))
{
	define('GROCY_USER_ID', 1);
}

// Definitions for disabled authentication mode
if (GROCY_DISABLE_AUTH === true)
{
	if (!defined('GROCY_USER_ID'))
	{
		define('GROCY_USER_ID', 1);
	}
}

// Check if any invalid entries in config.php have been made
try
{
	(new Grocy\Helpers\ConfigurationValidator())->validateConfig();
}
catch (\Grocy\Helpers\EInvalidConfig $ex)
{
	http_response_code(500);
	exit('Invalid setting in config.php: ' . $ex->getMessage());
}

// Create data/viewcache folder if it doesn't exist
$viewcachePath = GROCY_DATAPATH . '/viewcache';
if (!file_exists($viewcachePath))
{
	mkdir($viewcachePath);
}

// Empty data/viewcache when and trigger database migrations when:
// The version changed (so when an update was done)
// GROCY_BASE_URL OR GROCY_BASE_PATH changed
// Grocy Modern: also when the routes or the set of migrations changed. This
// fork is updated between releases, without version.json changing - and the
// route cache in data/viewcache is index based, so a stale one sends new
// routes to the wrong handlers, while a new migration would only run once
// somebody happens to open the root URL.
$hash = hash('sha256', file_get_contents(__DIR__ . '/version.json') . GROCY_BASE_URL . GROCY_BASE_PATH
	. file_get_contents(__DIR__ . '/routes.php') . implode(',', scandir(__DIR__ . '/migrations')));
if (GROCY_MODE === 'dev')
{
	// For dev mode use routes.php to track changes
	$hash = hash('sha256', file_get_contents(__DIR__ . '/routes.php'));
}
define('GROCY_CODE_HASH', $hash);
$hashCacheFile = $viewcachePath . "/$hash.txt";
if (!file_exists($hashCacheFile))
{
	EmptyFolder($viewcachePath);
	touch($hashCacheFile);

	if (function_exists('opcache_reset'))
	{
		opcache_reset();
	}
}

// Grocy Modern: the view cache is shared, but every profile has a database of
// its own which needs the migrations - so each profile remembers for which
// code it was migrated last. Originally Grocy redirected to the root route
// (which migrates) once after an update; that misses a profile that is only
// ever used through its API key (a Home Assistant integration, say), so the
// migrations now run on the first request of each profile instead, see below.
$migratedMarkerFile = GROCY_PROFILE_DATAPATH . '/migrated.txt';
define('GROCY_PROFILE_NEEDS_MIGRATION', !file_exists($migratedMarkerFile) || file_get_contents($migratedMarkerFile) !== $hash);

// Setup base application
AppFactory::setContainer(new DI\Container());
$app = AppFactory::create();

$container = $app->getContainer();
$container->set('view', function (Container $container)
{
	return new SlimBladeView(__DIR__ . '/views', GROCY_DATAPATH . '/viewcache');
});

$container->set('UrlManager', function (Container $container)
{
	return new UrlManager(GROCY_BASE_URL);
});

$container->set('ApiKeyHeaderName', function (Container $container)
{
	return 'GROCY-API-KEY';
});

// Load routes from separate file
require_once __DIR__ . '/routes.php';

// Set base path if defined
if (!empty(GROCY_BASE_PATH))
{
	$app->setBasePath(GROCY_BASE_PATH);
}

// Grocy Modern: migrate the database of this request's profile if it was
// migrated for older code. Runs after the authentication (added before it -
// Slim runs the last added middleware first), so it is a real, logged in
// request - like the root route, where Grocy itself migrates.
$app->add(function ($request, $handler) use ($migratedMarkerFile)
{
	if (GROCY_PROFILE_NEEDS_MIGRATION && defined('GROCY_USER_ID'))
	{
		// Several requests may arrive at once (an integration polling, a page
		// loading its data) - only one of them migrates, the others wait
		$lock = fopen(GROCY_PROFILE_DATAPATH . '/migrated.lock', 'c');
		flock($lock, LOCK_EX);

		clearstatcache();
		if (!file_exists($migratedMarkerFile) || file_get_contents($migratedMarkerFile) !== GROCY_CODE_HASH)
		{
			Grocy\Services\DatabaseMigrationService::GetInstance()->MigrateDatabase();
			file_put_contents($migratedMarkerFile, GROCY_CODE_HASH);
		}

		flock($lock, LOCK_UN);
		fclose($lock);
	}

	return $handler->handle($request);
});

$app->add(new LocaleMiddleware($container, $app->getResponseFactory()));
$authMiddlewareClass = GROCY_AUTH_CLASS;
$app->add(new $authMiddlewareClass($container, $app->getResponseFactory()));

// Add default middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, false, false);
$errorMiddleware->setDefaultErrorHandler(new ExceptionController($container, $app->getResponseFactory()));

$app->getRouteCollector()->setCacheFile(GROCY_DATAPATH . '/viewcache/route_cache.php');

ob_clean(); // No response output before here
$app->run();
