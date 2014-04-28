<?php
/* PHP settings overrides
 * Prevents the bot from timing out or running out of memory
 */
set_time_limit(0);
ini_set('memory_limit', '128M');
ini_set('log_errors',false);
ini_set('display_errors', 'on');

require_once 'src/henriwatson/Chimoso/Core.php';
require_once 'src/henriwatson/Chimoso/Event.php';

require_once 'vendor/autoload.php';

use henriwatson\Chimoso as Chimoso;

/* Server connection */
$chimoso = new Chimoso\Core('irc', 6667);

$chimoso->debug(true); // Output outgoing/incoming messages.

try {
    $chimoso->connect();
} catch (Exception $e) {
    die($e->getMessage());
}

/* Identify to the server */
$chimoso->ident('Chimoso', 'Chimoso', 'Chimoso');

/* 376: MOTD done
 * runOnce: Drop handler after it's run
 */
$chimoso->registerMessage('376', function ($event) use ($chimoso) {
    $chimoso->join('#test');
}, Array('runOnce' => 1));

/* Listen for messages beginning with !echo
 * Reply with anything sent with the message
 */
$chimoso->registerCommand('!echo', function ($event) {
    $event->reply($event->body);
});

/* Listen for URIs with a chimoso scheme
 * and echo the URI and the host component
 * Example: chimoso://test
 */
$chimoso->registerURI('scheme', 'chimoso', function ($event) {
    $event->reply('scheme: '.$event->additional['uri'].': '.$event->additional['components']['host']);
});

/* Listen for URIs with a chimoso hostname
 * and echo the URI
 * Example: http://chimoso/blah
 */
$chimoso->registerURI('hostname', 'chimoso', function ($event) {
    $event->reply('hostname: '.$event->additional['uri']);
});

/* Listen for URIs that look like a tweet
 * and echo the URI
 * Example: https://twitter.com/moonpolysoft/status/456079501315674112
 */
$chimoso->registerURI('regex', '/^http(s)?:\/\/twitter\.com\/(?:#!\/)?(\w+)\/status(es)?\/(\d+)$/', function ($event) {
    $event->reply('tweet:'. $event->additional['uri']);
});

/* Listen for URIs that don't match
 * any of the above listeners
 * Example: http://www.zombo.com/
 */
$chimoso->registerURIFallback(function ($event) {
    $event->reply('fallback:'. $event->additional['uri']);
});

/* Start listening for messages */
$chimoso->run();
