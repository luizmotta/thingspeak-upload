#!/usr/bin/php
<?php
include 'config.php';

$lastprocessedfile = $logfile . ".lastprocessed";
$lineold = "";
$history = array();

 
// Check for another running instance
$lock_file = fopen(__FILE__.'.pid', 'c');
$got_lock = flock($lock_file, LOCK_EX | LOCK_NB, $wouldblock);
if ($lock_file === false || (!$got_lock && !$wouldblock)) {
    throw new Exception(
        "Unexpected error opening or locking lock file. Perhaps you " .
        "don't  have permission to write to the lock file or its " .
        "containing directory?"
    );
}
else if (!$got_lock && $wouldblock) {
    exit("Another instance is already running; terminating.\n");
}

// Lock acquired; let's write our PID to the lock file for the convenience
// of humans who may wish to terminate the script.
ftruncate($lock_file, 0);
fwrite($lock_file, getmypid() . "\n");


function raise_alert( $msg ) {
	//echo $msg;
	mail( $email_to, $email_subject, $msg );
}

function check_boundaries( $history, $datapoint ) {
	global $channel;
	$sensor = $datapoint[ "sensor" ];
	$field =  $datapoint[ "field" ];
	$data = $datapoint[ "data"];
	@$data_max = $channel[ $sensor ][ $field ][ "limits" ][ "max" ];
	@$data_min = $channel[ $sensor ][ $field ][ "limits" ][ "min" ];
	if ( $data_max && $data_min ) {	
		//echo "Testing $sensor $field $data_max > $data > $data_min\r\n";
		if ( $field == "RHUM" && ( $data < $data_min || $data > $data_max ) ) {
			raise_alert( $datapoint["datetime"] . ": Humidity out of boundaries on sensor $sensor (" . $channel[$sensor]["name"] . "): $data %");
		}
		if ( $field == "TEMP" && ( $data < $data_min || $data > $data_max ) ) {
			raise_alert( $datapoint["datetime"] . ": Temperature out of boundaries on sensor $sensor (" . $channel[$sensor]["name"] . "): $data oC");
		}
	}
}

$log = file( $logfile );

$lastprocessed = @file_get_contents( $lastprocessedfile );
if ( $lastprocessed === false  ) { //If file was not present
        $lastprocessed = -1;
} else {
        if ( $lastprocessed > max( array_keys( $log ) ) ) { //If file was present but log rotated
                $lastprocessed = -1;
        }
}

foreach ( $log as $linenumber => $line ) {

        $lineold = $line;

        $linedata = explode( ",", $line);
        $datetime = $linedata[0];
        $sensor = $linedata[1];
        $cmd = $linedata[2];

        if ( substr( $cmd, 0, 4) == "TEMP" ) {
                $field = "TEMP";
                $data = substr( $cmd, 4, 4);
        } else if ( substr( $cmd, 0, 4) == "RHUM" ) {
                $field = "RHUM";
                $data = substr( $cmd, 4, 4);
        } else if ( substr( $cmd, 0, 4) == "BATT" ) {
                $field = "BATT";
                $data = substr( $cmd, 4, 4);
        } else if ( substr( $cmd, 0, 4) == "LVAL" ) {
                $field = "LVAL";
                $data = substr( $cmd, 4, 4);
        } else {
                continue;
        }
        @$channel_id = $channel[$sensor][$field]["id"];
        @$channel_key = $channel[$sensor][$field]["key"];

        if ( ($channel_id == "") || ($channel_key == "") ) {
                continue;
        }

	$datapoint = array();
	$datapoint[ "line" ] = $line;
	$datapoint[ "datetime" ] = $datetime;
	$datapoint[ "sensor" ] = $sensor;
	$datapoint[ "cmd" ] = $cmd;
	$datapoint[ "field" ] = $field;
	$datapoint[ "data" ] = $data;

	//Check for repeated messages and ignore if found
	foreach( $history as $old_datapoint ) {
		if( $old_datapoint[ "line" ] == $datapoint[ "line" ] ) {
			continue 2;
		}
	}

	//Otherwise, add to history
	$history[] = $datapoint;

	//Ignore everything up to lastprocessed
        if ( $linenumber <= $lastprocessed ) {
                continue;
        }

	check_boundaries( $history, $datapoint );

        if ( isset( $lastupdate[$channel_id] ) && ( time() - $lastupdate[$channel_id] ) < 15 ) {
                //echo "WAITING...\r\n";
                sleep( 20 );
        }

        //print_r("|".$datetime."|");
        $dt = DateTime::createFromFormat( "d M Y H:i:s O", trim($datetime) );
        //print_r(DateTime::getLastErrors());

        $url = "https://api.thingspeak.com/update?api_key=" . $channel_key . "&field1=" . $data . "&created_at=" . $dt->format("Y-m-d\TH:i:s\Z0000");;
	//echo $url . PHP_EOL;
       	$result = @file_get_contents( $url );

        if ( $result == "" ) {
                die("Couldn't update ThingSpeak");
        } else {
                file_put_contents( $lastprocessedfile, $linenumber);
                $lastupdate[$channel_id] = time();
        }
}

// All done; we blank the PID file and explicitly release the lock 
// (although this should be unnecessary) before terminating.
ftruncate($lock_file, 0);
flock($lock_file, LOCK_UN);
?>
