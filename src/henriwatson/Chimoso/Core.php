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
 * @package		Core
 * @version		1.0
 * @license		http://opensource.org/licenses/MIT	The MIT License
 */

namespace henriwatson\Chimoso;

use henriwatson\Chimoso\Event;

/** Handles server connection, identification, and listening */
class Core
{
    private $socket;
    private $server;
    private $port;
    private $timeout;
    private $handlersMessage = Array();
    private $handlersCommand = Array();
    private $handlersURI = Array();
    private $debug = false;

    /** Create a Chimoso object
     * @param string IRC server hostname
     * @param string IRC server port
     * @param int connection timeout
    */
    public function __construct($server, $port, $timeout = 30)
    {
        $this->server = $server;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /** Set debug mode
     * @param bool enable debug mode
    */
    public function debug($enable)
    {
        $this->debug = (bool) $enable;
    }

    /** Send raw data to the server
     * @param string data to send
    */
    public function put($data)
    {
        fputs($this->socket, $data.chr(13).chr(10));

        if ($this->debug) {
            echo '-> '.$data.chr(13).chr(10);
        }
    }

    /** Connect to specified server */
    public function connect()
    {
        $this->socket = fsockopen($this->server, $this->port, $error, $errorstr, $this->timeout);
        if (!$this->socket) {
            throw new \Exception('Unable to connect to server. '.$errorstr);
            return false;
        }
    }

    /** Identify to the server
     * @param string nickname
     * @param string username
     * @param string real name
    */
    public function ident($nick, $user, $realname)
    {
        $this->put('USER '.$user.' 0 * :'.$realname);
        $this->put('NICK '.$nick);
    }

    /** Join a channel
     * @param string channel to join
    */
    public function join($channel)
    {
        $this->put('JOIN '.$channel);
    }

    /** Register a server message callback
     * @param string message to listen for
     * @param closure callback function. an Event object will be passed back as the only parameter.
     * @param array additional parameters
    */
    public function registerMessage($message, $function, $additional = Array())
    {
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
    public function registerCommand($command, $function, $additional = Array())
    {
        $this->handlersCommand[strtolower($command)][] = array_merge($additional, Array(
            'removable' => true,
            'function' => &$function
        ));
    }

    /** Register a URI to listen for
     * @param string match type (scheme, hostname, regex)
     * @param string search string
     * @param closure callback function. an Event object will be passed back as the only parameter.
    */
    public function registerURI($type, $search, $function)
    {
        if ($type == 'scheme' || $type == 'hostname') {
            $this->handlersURI[$type][$search][] = Array(
                'function' => &$function
            );
        } elseif ($type == 'regex') {
            $this->handlersURI[$type][] = Array(
                'regex' => $search,
                'function' => &$function
            );
        } else {
            return false;
        }
    }
    
    /** Register handler to fire if no URI handler succeeds
     * @param closure callback function. an Event object will be passed back as the only parameter.
    */
    public function registerURIFallback($function)
    {
       $this->handlersURI['fallback'][] = Array(
            'function' => &$function
        );
    }

    /** Start listening for messages */
    public function run()
    {
        $this->registerMessage('PING', function ($event) {
            $event->put('PONG :'.$event->parse['params']['all']);
        });

        while (1) {
            $data = fgets($this->socket);
            flush();

            if ($data == '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['eof'] == 1)
                    break;
            }

            if ($this->debug)
                echo '<- '.$data;

            $bits = explode(' ', $data);

            if (substr($data, 0, 1) == ':') {
                $command = $bits[1];
            } else {
                $command = $bits[0];
            }

            /* General IRC message handling */
            if (isset($this->handlersMessage[strtoupper($command)])) {
                foreach ($this->handlersMessage[strtoupper($command)] as $id => $handler) {
                    $handler['function'](new Event($data, $socket, $this));

                    if (isset($handler['runOnce']) && $handler['runOnce']) {
                        unset($this->handlersMessage[$command][$id]);
                    }
                }
            }

            /* PRIVMSG handling */
            if ($command == 'PRIVMSG') {
                $parser = new \Phergie\Irc\Parser();
                $parse = $parser->parse($data);

                /* registered command handling */
                $handledCommand = false;
                $bits = explode(' ', $parse['params']['text']);

                if (isset($this->handlersCommand[strtolower($bits[0])])) {
                    foreach ($this->handlersCommand[strtolower($bits[0])] as $id => $handler) {
                        $return = $handler['function'](
                            new Event(
                                $data,
                                $socket,
                                $this,
                                Array(
                                    'rmFirstWord' => 1
                                )
                            )
                        );

                        if (isset($handler['runOnce']) && $handler['runOnce']) {
                            unset($this->handlersCommand[$bits[0]][$id]);
                        }

                        if ($return !== false) {
                            $handledCommand = true;
                        }
                    }
                }

                if ($handledCommand) {
                    continue;
                }

                /* URI handling */
                preg_match_all('#\b(\w+):\/?\/?([^\s()<>]+)(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $parse['params']['text'], $URImatches, PREG_SET_ORDER);

                foreach ($URImatches as $match) {
                    $handledURI = false;
                    $matchParse = parse_url($match[0]); // URI parsing is hard. Let PHP do it.

                    /* protocol matching */
                    if (isset($this->handlersURI['scheme'][$matchParse['scheme']])) {
                        foreach ($this->handlersURI['scheme'][$matchParse['scheme']] as $id => $handler) {
                            $return = $handler['function'](
                                new Event(
                                    $data,
                                    $socket,
                                    $this,
                                    Array(
                                        'rmFirstWord' => 1,
                                        'uri' => $match[0],
                                        'components' => $matchParse
                                    )
                                )
                            );

                            if (isset($handler['runOnce']) && $handler['runOnce']) {
                                unset($this->handlersURI['scheme'][$matchParse['scheme']][$id]);
                            }
                            
                            if ($return !== false) {
                                $handledURI = true;
                            }
                        }
                    }

                    /* hostname matching */
                    if (isset($this->handlersURI['hostname'][$matchParse['host']])) {
                        foreach ($this->handlersURI['hostname'][$matchParse['host']] as $id => $handler) {
                            $return = $handler['function'](
                                new Event(
                                    $data,
                                    $socket,
                                    $this,
                                    Array(
                                        'rmFirstWord' => 1,
                                        'uri' => $match[0],
                                        'components' => $matchParse
                                    )
                                )
                            );

                            if (isset($handler['runOnce']) && $handler['runOnce']) {
                                unset($this->handlersURI['hostname'][$matchParse['host']][$id]);
                            }
                            
                            if ($return !== false) {
                                $handledURI = true;
                            }
                        }
                    }

                    /* regex matching */
                    foreach ($this->handlersURI['regex'] as $id => $handler) {
                        if (preg_match($handler['regex'], $match[0], $matches)) {
                            $return = $handler['function'](
                                new Event(
                                    $data,
                                    $socket,
                                    $this,
                                    Array(
                                        'rmFirstWord' => 1,
                                        'uri' => $match[0],
                                        'matches' => $matches
                                    )
                                )
                            );

                            if (isset($handler['runOnce']) && $handler['runOnce']) {
                                unset($this->handlersURI['regex'][$id]);
                            }
                            
                            if ($return !== false) {
                                $handledURI = true;
                            }
                        }
                    }
                    
                    if ($handledURI === false) {
                        foreach ($this->handlersURI['fallback'] as $id => $handler) {
                            $handler['function'](
                                new Event(
                                    $data,
                                    $socket,
                                    $this,
                                    Array(
                                        'rmFirstWord' => 1,
                                        'uri' => $match[0],
                                        'components' => $matchParse
                                    )
                                )
                            );
                        }
                    }
                }
            }
        }
    }
}
