<?php
/* PHP settings overrides
 * Prevents the bot from timing out or running out of memory
 */
set_time_limit(0);
ini_set('memory_limit', '128M');
ini_set('log_errors',false);
ini_set('display_errors', 'on');

/* Composer initialiser */
require_once 'vendor/autoload.php';

use henriwatson\Chimoso as Chimoso;

/* Server connection */
$chimoso = new Chimoso\Core("irc", 6667);

try {
	$chimoso->connect();
} catch (Exception $e) {
	die($e->getMessage());
}

/* Identify to the server */
$chimoso->ident("Chimoso", "Chimoso", "Chimoso");

/* 376: MOTD done
 * runOnce: Drop handler after it's run
 */
$chimoso->registerMessage("376", function($event) {
	$chimoso->join("#test");
}, Array('runOnce' => 1));

/* Listen for messages beginning with !echo
 * Reply with anything sent with the message
 */
$chimoso->registerCommand("!echo", function($event) {
	$event->reply($event->body);
});

/* Start listening for messages */
$chimoso->run(); 