<?php
/**
 * Copyright (c) 2013 Henri Watson
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * @author		Henri Watson
 * @package		Core
 * @version		1.0
 * @license		http://opensource.org/licenses/MIT	The MIT License
 */

use henriwatson\Chimoso\Event;

namespace henriwatson\Chimoso;

/** Handles server connection, identification, and listening */
class Core {
	private $socket;
	private $server;
	private $port;
	private $timeout;
	private $handlersMessage = Array();
	private $handlersCommand = Array();
	
	/** Create a Chimoso object
	 * @param string IRC server hostname
	 * @param string IRC server port
	 * @param int connection timeout
	*/
	function __construct($server, $port, $timeout = 30) {
		$this->server = $server;
		$this->port = $port;
		$this->timeout = $timeout;
	}
	
	/** Send raw data to the server
	 * @param string data to send
	*/
	public function put($data) {
		fputs($this->socket, $data."\n");
		echo "-> ".$data."\n";
	}
	
	/** Connect to specified server */
	public function connect() {
		$this->socket = fsockopen($this->server, $this->port, $error, $errorstr, $this->timeout);
		if (!$this->socket)
			throw new Exception('Unable to connect to server. '.$errorstr);
			return false;
	}
	
	/** Identify to the server
	 * @param string nickname
	 * @param string username
	 * @param string real name
	*/
	public function ident($nick, $user, $realname) {
		$this->put("USER ".$user." 0 * :".$realname);
		$this->put("NICK ".$nick);
	}
	
	/** Join a channel
	 * @param string channel to join
	*/
	public function join($channel) {
		$this->put("JOIN ".$channel);
	}
	
	/** Register a server message callback
	 * @param string message to listen for
	 * @param closure callback function. an Event object will be passed back as the only parameter.
	 * @param array additional parameters
	*/
	public function registerMessage($message, $function, $additional = Array()) {
		$this->handlersMessage[strtoupper($message)][] = array_merge($additional, Array(
			'removable' => true,
			'function' => &$function
		));
	}
	
	/** Register a chat command callback
	 * @param string command to listen for
	 * @param closure callback function. an Event object will be passed back as the only parameter.
	 * @param array additional parameters
	*/
	public function registerCommand($command, $function, $additional = Array()) {
		$this->handlersCommand[strtolower($command)][] = array_merge($additional, Array(
			'removable' => true,
			'function' => &$function
		));
	}
	
	/** Start listening for messages */
	public function run() {
		$this->registerMessage("PING", function($event) {
			$event->put("PONG :".$event->parse['params']['all']);
		});
		
		while (1) {
			$data = fgets($this->socket);
			flush();
			
			if ($data == "") {
				$meta = stream_get_meta_data($this->socket);
				if ($meta['eof'] == 1)
					break;
			}
			
			echo "<- ".$data;
			
			$bits = explode(" ", $data);
			
			if (substr($data, 0, 1) == ":")
				$command = $bits[1];
			else
				$command = $bits[0];
			
			/* General IRC message handling */
			if (isset($this->handlersMessage[strtoupper($command)])) {
				foreach ($this->handlersMessage[strtoupper($command)] as $id => $handler) {
					$handler['function'](new Event($data, $socket, $this));
					
					if (isset($handler['runOnce']) && $handler['runOnce'])
						unset($this->handlersMessage[$command][$id]);
				}
			}
			
			/* PRIVMSG handling */
			if ($command == "PRIVMSG") {
				$parser = new \Phergie\Irc\Parser();
				$parse = $parser->parse($data);
				
				$bits = explode(" ", $parse['params']['text']);
				
				if (isset($this->handlersCommand[strtolower($bits[0])])) {
					foreach ($this->handlersCommand[strtolower($bits[0])] as $id => $handler) {
						$handler['function'](new Event($data, $socket, $this, Array('rmFirstWord' => 1)));
						
						if (isset($handler['runOnce']) && $handler['runOnce'])
							unset($this->handlersCommand[$bits[0]][$id]);
					}
				}
			}
		}
	}
}