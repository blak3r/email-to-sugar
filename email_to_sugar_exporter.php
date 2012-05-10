
<?php

/** 
 * Simple script to export email_to_sugar
 */


$OUTPUT_FILE = "C:/sugarcrm/htdocs/email_to_sugar-release.php";
$handle = fopen($OUTPUT_FILE, 'w');

$lines = file("C:/sugarcrm/htdocs/email_to_sugar.php");

$mode = "";

foreach($lines as $line)
{
	$skipLine = false;
    //echo($line);
	
	// Check for start of skip condition
	if( preg_match("/.*RELEASE EXCLUDE START.*/i", $line, $matches) ) {
		$mode = "skip";
		$skipLine = true;
		echo ( "skipping begins on : $line\n");
	}
	
	else if( $mode == "skip" && preg_match("/.*RELEASE EXCLUDE END.*/i", $line, $matches) ) {
		$mode = "";
		$skipLine = true;
		echo ( "skipping ends on : $line\n");
	}
	
	else if( preg_match("/.*RELEASE INCLUDE START.*/i", $line, $matches ) ) {
		$skipLine = true;
		$mode = "";
	}
	
		
	else if( preg_match("/.*RELEASE INCLUDE END.*/i", $line, $matches ) ) {
		$skipLine = true;
		$mode = "";
	}
	
	
	if( $mode != "skip" && !$skipLine ) {
		fwrite($handle, $line );
	}
	
	
	

}


fclose($handle);


?>