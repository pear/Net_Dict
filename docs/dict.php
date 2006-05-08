#!/usr/local/bin/php -q
<?php

// {{{ includes
require 'Console/Getopt.php';
require 'Net/Dict.php';
// }}}
// {{{ get options

$longOptions  = array('help', 'match=', 'databases', 'strategies', 
                        'info=', 'server', 'status');

$con = new Console_Getopt;

$args = $con->readPHPArgv();

// array_shift($args);

$options = $con->getopt($args, '', $longOptions);

if (PEAR::isError($options)) {
	die($options->getMessage());
}

// }}}
// {{{ help!
function help()
{
echo <<<HELP

Usage: dict [OPTIONS] keyword

Options:
    --help          Prints this help screen
    --matches=word  Show DB matches for word 
    --databases     Show DB list
    --strategies    Show Strategy list
    --info=DATABASE Show Info on DB
    --server        Show Server Info	
    --status        Show Status

HELP;
	exit(0);
}

if ('--help' == $options[0][0][0] or ( empty($options[0][0][0]) and empty($options[1][0]) )  ) help();

// }}}
// {{{ connect
$d = new Net_Dict;

$conn = $d->connect();

if (PEAR::isError($conn)) {
	die($conn->getMessage());
}

// }}}
// {{{ define

if (!empty($options[1][0])) {

	foreach ($options[1] as $keyword) {
	
        	$defs = $d->define($keyword);

        	if (PEAR::isError($defs)) {
                	die($defs->getMessage());
        	}

        	foreach ($defs as $def) {
                	echo $def['definition'];
        	}
	}
}

// }}}
// {{{ options 

switch ($options[0][0][0]) {

	case '--help':
		help();
		break;

    case '--match':

        foreach($d->match($options[0][0][1]) as $matches)
            echo $matches['database'] . ' : ' . $matches['word'] . "\n";
   
        break;

    case '--databases':

		foreach ($d->showDatabases() as $db) 
            echo $db['database'] . ' : ' . $db['description']."\n";

		break;

    case '--strategies':
        
        foreach ($d->showStrategies() as $strat)
            echo $strat['strategy'] . ' : ' . $strat['description'] . "\n";

        break;

	case '--info':

		$info = $d->showInfo($options[0][0][1]);

		if (PEAR::isError($info))
			die($info->getMessage());

		echo $info;

		break;

	case '--server':
		
		$server = $d->showServer();

		if (PEAR::isError($server)) 
			die($server->getMessage());

		echo $server;

		break;

	case '--status':
		
		echo $d->status();

		break;

	default:
		break;
}

// }}}
