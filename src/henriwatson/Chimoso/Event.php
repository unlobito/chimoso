<?php
/**
 * Copyright (c) 2014 Henri Watson
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
 * @package		Event
 * @version		1.0
 * @license		http://opensource.org/licenses/MIT	The MIT License
 */
 
namespace henriwatson\Chimoso;

/** Representation of received events. */
class Event {
	/** Raw server data*/
	public $data;
	
	/** Parsed message data from Phergie */
	public $parse;
	
	/** Additional metadata passed in by the runner */
	public $additional;
	
	private $chimoso;
	private $socket;
	
	function __construct($data, &$socket, $chimoso, $additional = Array()) {
		$this->data = $data;
		$this->additional = $additional;
		$this->socket = &$socket;
		$this->chimoso = &$chimoso;
		
		$parser = new \Phergie\Irc\Parser();
		$this->parse = $parser->parse($data);
		
		if (isset($additional['rmFirstWord'])) {
			$this->body = $this->parse['params']['text'];
			$firstWord = explode(" ", $this->body, 2);
			$this->body = substr($this->body, strlen($firstWord[0])+1);
		} else if (isset($this->parse['params']['text'])) {
			$this->body = $this->parse['params']['text'];
		}
	}
	
	
	/** Send raw data to the server
	 * @param string data to send
	*/
	public function put($data) {
		return $this->chimoso->put($data);
	}
	
	/** Reply to data from the server
	* If replying to a PRIVMSG, a PRIVMSG to the source will be sent,
	* otherwise acts the same as put()
	* @param string message to reply with
	*/
	public function reply($msg) {
		if ($this->parse['command'] == "PRIVMSG") {
			/* Check if this is a private message or a channel and set the
			* destination accordingly */
			if (substr($this->parse['params']['receivers'], 0, 1) == "#") {
				$destination = $this->parse['params']['receivers'];
			} else {
				$destination = $this->parse['nick'];
			}

			/* Split message up into 400 character chunks */
			if (strlen($msg) > 400) {
				$chunks = explode("\r\n", chunk_split($msg, 400, "\r\n"));
				unset($chunks[count($chunks)-1]);
				foreach ($chunks as $chunk) {
					$this->put("PRIVMSG ".$destination." :".$chunk);
				}
			} else {
				$this->put("PRIVMSG ".$destination." :".$msg);
			}
		} else {
			$this->put($msg);
		}
	}
}