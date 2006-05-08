<?php

/* vim: set expandtab tabstop=4 shiftwidth=4: */

/**
 * Net_Dict
 *
 * PHP Versions 4 and 5
 *
 * LICENSE: This source file is subject to version 2.02 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/2_02.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Networking
 * @package    Net_Dict
 * @author     Chandrashekhar Bhosle <cnb@freedomink.org>
 * @author     Ian Eure <ieure@php.net>
 * @copyright  (c) 2002 Chandrashekhar Bhosle
 * @copyright  (c) 2005, 2006 Ian Eure
 * @license    http://www.php.net/license/2_02.txt  PHP License 2.02
 * @version    CVS: $Revision$
 * @link       http://pear.php.net/package/Net_Dict
 */

require_once 'PEAR.php';
require_once 'Net/Socket.php';


define('NET_DICT_SERVER', 'dict.org');
define('NET_DICT_PORT',   '2628');

/**
 * The main Net_Dict class
 *
 * Net_Dict is a PHP interface for talking to dictd servers.
 *
 * @package   Net_Dict
 * @category  Networking
 * @link      http://pear.php.net/package/Net_Dict
 * @version   Release: @package_version@
 * @version   CVS:     $Revision$
 * @author    Chandrashekhar Bhosle <cnb@freedomink.org>
 * @license   http://www.php.net/license/2_02.txt PHP License v2.02
 */
class Net_Dict {
    /**
     * Default DICT server name
     *
     * @var string
     */
    var $server = NET_DICT_SERVER;

    /**
     * Default DICT Port
     *
     * @var int
     */
    var $port = NET_DICT_PORT;

    /**
     * Socket object
     *
     * @var object
     */
    var $_socket;

    /**
     * Server Information
     *
     * @var string
     */
    var $servinfo;

    /**
     * if caching is on or off
     *
     * @var boolean
     */
    var $caching = false;

    /**
     * PEAR Cache
     *
     * @var object
     */
    var $cache;


    /**
     * Gets definitions for the specified word in the specified database.
     *
     * @param   string  $word
     * @param   string  $database
     *
     * @return  mixed   Array of definitions if sucessful, else PEAR_Error
     */
    function define($word, $database = '*')
    {
        if ($this->caching) {
            if ($defines = $this->cache->get($word, 'Net_Dict_Defs')) {
                return $defines;
            }
        }

        if (!is_object($_socket)) {
            $this->connect();
        }

        $resp = $this->_sendCmd("DEFINE $database '$word'");

        if (PEAR::isError($resp)) {
            return $resp;
        }

        list($num) = explode(' ', $resp['text'], 2);

        for ($i = 0; $i < $num; $i++) {
            $resp = $this->_socket->readLine();

            preg_match("/(\d{3})\s+?\"(.+)?\"\s+?(\S+)\s+?\"(.+)?\"/",
                                                    $resp, $matches);

            $defines[$i]['response']    = $resp;
            $defines[$i]['word']        = $matches[2];
            $defines[$i]['database']    = $matches[3];
            $defines[$i]['description'] = $matches[4];

            $resp = $this->_getMultiline();

            $defines[$i]['definition'] = $resp['text'];
        }

        $this->readLine(); /* discard status */

        if ($this->caching) {
            $this->cache->save($word, $defines, 0, 'Net_Dict_Defs');
        }

        return $defines;
    }

    /**
     * Searches an index for the dictionary, and reports words
     * which were found using a particular strategy.
     *
     * @param   string  $word
     * @param   string  $strategy
     * @param   string  $database
     *
     * @return  mixed   Array of matches if successful, else PEAR_Error
     */
    function match($word, $strategy = 'substring', $database = '*')
    {
        $resp = $this->_sendCmd("MATCH $database $strategy '$word'");

        if (PEAR::isError($resp)) {
            return $resp;
        }

        $resp = $this->_getMultiLine();

        $this->readLine(); /* discard status */

        preg_match_all("/(\S+)?\s\"(.+?)\"/", $resp['text'], $matches);

        for ($i = 0; $i < count($matches[0]); $i++) {
            $matched[$i]['database'] = $matches[1][$i];
            $matched[$i]['word']     = $matches[2][$i];
        }

        return $matched;
    }

    /**
     * Gets list of available databases
     *
     * @return  mixed  Array of databases if successful, else PEAR_Error
     */
    function showDatabases()
    {
        $resp = $this->_sendCmd('SHOW DB');

        if (PEAR::isError($resp)) {
            return $resp;
        }

        $resp = $this->_getMultiLine();

        $this->readLine(); /* discard status */

        preg_match_all("/(\S+)?\s+?\"(.+?)\"/", $resp['text'], $matches);

        for ($i = 0; $i < count($matches[0]); $i++) {
            $databases[$i]['database']    = $matches[1][$i];
            $databases[$i]['description'] = $matches[2][$i];
        }

        return $databases;
    }

    /**
     * Gets a list of available strategies
     *
     * @return mixed Array of strategies if successful, else PEAR_Error
     */
    function showStrategies()
    {
        $resp = $this->_sendCmd('SHOW STRAT');

        if (PEAR::isError($resp)) {
            return $resp;
        }

        $resp = $this->_getMultiLine();

        $this->readLine(); /* discard status */

        preg_match_all("/(\S+)?\s+?\"(.+?)\"/", $resp['text'], $matches);

        for ($i = 0; $i < count($matches[0]); $i++) {
            $strategies[$i]['strategy']    = $matches[1][$i];
            $strategies[$i]['description'] = $matches[2][$i];
        }

        return $strategies;
    }

    /**
     * Gets source, copyright, and licensing information about the
     * specified database.
     *
     * @param   string  $database
     *
     * @return  mixed   string if successful, else PEAR_Error
     */
    function showInfo($database)
    {
        return $this->simpleQuery('SHOW INFO ' . $database);
    }

    /**
     * Gets local server information written by the local administrator.
     * This could include information about local databases or strategies,
     * or administrative information such as who to contact for access to
     * databases requiring authentication.
     *
     * @return  mixed  string if sucessful, else PEAR_Error
     */
    function showServer()
    {
        return $this->simpleQuery('SHOW SERVER');
    }

    /**
     * Allows the client to provide information about itself
     * for possible logging and statistical purposes.  All clients SHOULD
     * send this command after connecting to the server.  All DICT servers
     * MUST implement this command (note, though, that the server doesn't
     * have to do anything with the information provided by the client).
     *
     * @param   string  $text
     *
     * @return  mixed   string if successful, else PEAR_Error
     */
    function client($text = 'cnb')
    {
        $this->_sendCmd('CLIENT ' . $text);
    }

    /**
     * Display some server-specific timing or debugging information.  This
     * information may be useful in debugging or tuning a DICT server.  All
     * DICT servers MUST implement this command (note, though, that the text
     * part of the response is not specified and may be omitted).
     *
     * @return  mixed  string if successful, else PEAR_Error
     */
    function status()
    {
        $resp = $this->_sendCmd('STATUS');
        return $resp['text'];
    }

    /**
     * Provides a short summary of commands that are understood by this
     * implementation of the DICT server.  The help text will be presented
     * as a textual response, terminated by a single period on a line by
     * itself.  All DICT servers MUST implement this command.
     *
     * @return  mixed  string on success, else PEAR_Error
     */
    function help()
    {
        return $this->simpleQuery('HELP');
    }

    /**
     * This command is used by the client to cleanly exit the server.
     * All DICT servers MUST implement this command.
     *
     * @return  mixed  string on success, else PEAR_Error
     */
    function quit()
    {
        return $this->_sendCmd('QUIT');
    }

    /**
     * Requests that all text responses be prefaced by a MIME header
     * [RFC2045] followed by a single blank line (CRLF).
     *
     * @return  mixed
     * @todo    Implement this method
     */
    function optionMIME()
    {
    }

    /**
     * The client can authenticate itself to the server using a username and
     * password.  The authentication-string will be computed as in the APOP
     * protocol discussed in [RFC1939].
     *
     * @param   string  $user
     * @param   string  $auth
     *
     * @return  mixed
     * @todo    Implement this method.
     */
    function auth($user, $auth)
    {
    }

    /**
     * The Simple Authentication and Security Layer (SASL) is currently
     * being developed [RFC2222].  The DICT protocol reserves the SASLAUTH
     * and SASLRESP commands for this method of authentication.
     *
     * @param   string  $mechanism
     * @param   string  $initial_response
     * @return  mixed
     * @todo    Implement this method.
     */
    function SASLAuth($mechanism, $initial_response)
    {
    }

    /**
     * The client will send all responses using the SASLRESP command and a
     * BASE64-encoded parameter.
     *
     * @param   string  $response
     * @return  mixed
     * @todo    Implement this method.
     */
    function SASLResp($response)
    {
    }

    /**
     * Connects to a dict server and sets up a socket
     *
     * @param   string   $server
     * @param   integer  $port
     * @return  mixed    true on success, else PEAR_Error
     */
    function connect($server = '', $port = 0)
    {
        $s = new Net_Socket;

        if (empty($server)) {
            $server = $this->server;
        }

        if (0 == $port) {
            $port = $this->port;
        }

        $err = $s->connect($server, $port);

        if (PEAR::isError($err)) {
            return $err;
        }

        $banner = $s->readLine();

        preg_match("/\d{3} (.*) <(.*)> <(.*)>/", $banner, &$reg);
        $this->servinfo["signature"]    = $reg[1];
        $this->servinfo["capabilities"] = explode(".", $reg[2]);
        $this->servinfo["msg-id"]       = $reg[3];

        $this->_socket = $s;

        return true;
    }

    /**
     * Sets the server and port of dict server
     *
     * @param   string  $server
     * @param   int     $port
     * @return  void
     */
    function setServer($server, $port = 0)
    {
        $this->server = $server;

        if (0 < $port) {
            $this->port = $port;
        }
    }

    /**
     * Sets caching on or off and provides the cache type and parameters
     *
     * @param   boolean  $cache
     * @param   string   $container
     * @param   array    $container_options
     * @return  void
     */
    function setCache($flag = false, $container = '', $container_options = '')
    {
        $this->caching = $flag;

        if ($this->caching) {
            require_once 'Cache.php';

            if (is_object($this->cache)) {
                unset($this->cache);
            }

            $this->cache = new Cache($container, $container_options);
        }
    }

    /**
     * Sends a command, checks the reponse, and
     * if good returns the reponse, other wise
     * returns false.
     *
     * @param   $cmd   Command to send (\r\n will be appended)
     * @return  mixed  First line of response if successful, otherwise false
     */
    function _sendCmd($cmd)
    {
        $result = $this->_socket->writeLine($cmd);

        if (PEAR::isError($result) && $result) {
            return $result;
        }

        $data = $this->_socket->readLine();

        if (PEAR::isError($data)) {
            return $data;
        }

        $resp['code'] = substr($data, 0, 3);
        $resp['text'] = ltrim(substr($data, 3));

        if (!Net_Dict::isOK($resp)) {
            return new PEAR_Error($resp['text'],
                                  $resp['code']);
        }

        return $resp;
    }

    /**
     * Reads a multiline reponse and returns the data
     *
     * @return mixed string on success or PEAR_Error
     */
    function _getMultiline()
    {
        $data = '';
        while (($tmp = $this->readLine()) != '.') {
            if (substr($tmp, 0, 2) == '..') {
                $tmp = substr($tmp, 1);
            }
            $data .= $tmp . "\r\n";
        }

        $resp['text'] = substr($data, 0, -2);

        return $resp;
    }

    /**
     * Alias to Net_Socket::readLine();
     *
     * @see Net_Socket::readLine();
     */
    function readLine()
    {
        return $this->_socket->readLine();
    }

    /**
     * Runs a generic dict query
     *
     * @param   string  $query
     * @return  mixed   string on success, else PEAR_Error
     */
    function simpleQuery($query)
    {
        $resp = $this->_sendCmd($query);

        if (PEAR::isError($resp)) {
            return $resp;
        }

        $resp = $this->_getMultiLine();

        $this->readLine(); /* discard status */

        return $resp['text'];
    }

    /**
     * Checks if a response code is positive
     *
     * @param   array    $resp
     * @return  boolean
     */
    function isOK($resp)
    {
        $positives = array(1, 2, 3);

        return in_array(substr($resp['code'], 0, 1), $positives);
    }
}

?>