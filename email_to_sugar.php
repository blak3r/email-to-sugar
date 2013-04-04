<?php
/**
 * email_to_sugar.php
 *
 * @author Blake Robertson, http://www.blakerobertson.com --
 *         POST QUESTIONS HERE: http://www.sugarcrm.com/forums/f19/email-sugar-project-78755/
 * @copyright Copyright (C) 2002-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version 1.3.0, 2011/05/20 
 *          1.2 - added ability to send emails you received.
 *          1.3 - added MSSQL support
 *                Changed LIMIT to TOP (when using mssql), and replaced all double quotes with single quote in WHERE clauses.
 *                Special thanks to Caleb Skurdal Vitech Group for his help porting to MSSQL database.
 *          1.3.1 - Multiple bug fixes, broke To mode regex in 1.3.0, fixed PHP warnings about mysql_connect, added mssql_error method.
 *                  Please contact me if you've successfully used with MSSQL so i can take out of BETA.
 * 
 */
 
	// ------[ RELEASE EXCLUDE START ]-----
	// CONSTANTS 
	$MAILBOX_NAME = "Email Archiver";
	$MAILBOX_EMAIL_ADDR = "CRM@ALERTUS.COM";
	$CONFIG_FILE_PATH = "./sugarcrm/config.php"; // Path if you put in the 
	$ENABLE_LOGGING = true;
	$VERBOSE_LOGGING = false;
	$LOG_FILE_PATH = "c:/sugarcrm/htdocs/email-to-sugar.log";
	$SUCCESS_LOG_FILE_PATH = "c:/sugarcrm/htdocs/email-to-sugar-archived.log";
	$LIMIT_PROCESSING_TO = 500; // in case your archive email box gets really full of emails which don't have any matches... this limits to the X most recent records.
	
	$IGNORE_DOMAIN1 = "ALERTUS.COM";  // You must have something, specified for these.
	$IGNORE_DOMAIN2 = "ALERTUSTECH.COM"; 
	// ------[ RELEASE EXCLUDE END ]-----
	
    /** <<<<< RELEASE INCLUDE START >>>>>>
	// CONSTANTS 
	$MAILBOX_NAME = "Email Archiver";
	$MAILBOX_EMAIL_ADDR = "crm@yourdomain.com"; // NEW in v1.2 -- Set to the email address you use to archive emails.
	$CONFIG_FILE_PATH = "./sugarcrm/config.php"; // Path if you put in the root htdocs folder, make it ./config.php if in the sugarcrm folder
	$ENABLE_LOGGING = false;
	$VERBOSE_LOGGING = false; // Only enable to debug prints a lot of stuff!
	$LOG_FILE_PATH = "email-to-sugar.log"; 
	$SUCCESS_LOG_FILE_PATH = "email-to-sugar-archived.log"; // THIS IS USED EVEN IF LOGGING IS DISABLED, makes an entry for each thing logged.
	$LIMIT_PROCESSING_TO = 100; // in case your archive email box gets really full of emails which don't have any matches... this limits to the X most recent records.
	
	$IGNORE_DOMAIN1 = "YOURDOMAIN1.COM";  // You must have something, specified for these.
	$IGNORE_DOMAIN2 = "YOURDOMAIN2.COM";  // Leave it set as it is if you don't have multiple domains.
	<<<<<< RELEASE INCLUDE END >>>>>>> **/

	if(is_file($CONFIG_FILE_PATH))
	{
		require_once($CONFIG_FILE_PATH);
		$db_user_name	= $sugar_config['dbconfig']['db_user_name'];
		$db_password	= $sugar_config['dbconfig']['db_password'];
		$db_name		= $sugar_config['dbconfig']['db_name'];
		$db_host_name	= $sugar_config['dbconfig']['db_host_name'];
		$db_type        = $sugar_config['dbconfig']['db_type'];
		$db_host_instance = '';
		if( array_key_exists('db_host_instance',$sugar_config['dbconfig']) ) {
			$db_host_instance = $sugar_config['dbconfig']['db_type'];
		}
	 }
	else
	{
		$db_user_name	= 'root';
		$db_password	= 'your_password';
		$db_name		= 'sugarcrm';
		$db_host_name	= 'localhost';
		$db_type = 'mssql'; // other option is mssql 
		$db_host_instance = ''; // more of mssql thing
	}
	

	//--------------[ DO NOT MODIFY ANYTHING BELOW THIS LINE ]------------------//
		
		
	$is_cli = php_sapi_name() == "cli";
	
	print_wrapper("Checking for emails...\n");
	
	// checks if a db_host_instance is set and if so it appends to hostname (MSSQl ONLY)
	if( !empty($db_host_instance) ) {
		$db_hostname .= "\\" . $db_host_instance;
	}
	
	$dbcon = mxsql_connect($db_host_name,$db_user_name,$db_password);
	if (!$dbcon)
	 {
		print_wrapper('Could not connect: ' . mxsql_error());
		die('Could not connect: ' . mxsql_error());
	 }

	mxsql_select_db($db_name, $dbcon);


	print_wrapper("\n\nSTEP1: Preprocessing msgs sent 'To' $MAILBOX_EMAIL_ADDR...\n");
	print_wrapper("------------------------------------------\n");

// First we go through and convert any emails that are sent "TO" the crm@alertus.com
// These emails are intended to be archived (typically a customer reply)...  
$query8=<<<QUERY8
Select emails.id, emails.name, emails.date_sent, emails_text.description, emails_text.description_html, emails_email_addr_rel.email_address_id, email_addresses.email_address_caps, emails_email_addr_rel.address_type
  FROM inbound_email
  join emails
	ON inbound_email.id = emails.mailbox_id
  join emails_email_addr_rel
    ON emails.id = emails_email_addr_rel.email_id 
  join email_addresses
    ON emails_email_addr_rel.email_address_id = email_addresses.id
  join emails_text
    ON emails_text.email_id = emails.id
WHERE inbound_email.name='$MAILBOX_NAME' and emails.status != 'Archived'  and emails.deleted != 1 and inbound_email.deleted != 1
	and emails_email_addr_rel.deleted != 1 and email_addresses.deleted != 1
	and emails_text.deleted !=1
      AND emails_email_addr_rel.address_type='to' AND email_addresses.email_address_caps like '%$MAILBOX_EMAIL_ADDR%';
QUERY8;

	$email_results = mxsql_query_wrapper( $query8);

	// FOR EMAILS sent to $MAILBOX_EMAIL_ADDR
	while($email = mxsql_fetch_array($email_results))
	{
		print_wrapper("\nPre-Processing Email with subject: " . $email['name'] . "\n");

		//print_wrapper("EMAIL THAT IS SENT 'SUBJECt': " . $email['name'] . " " . $email['id'] . "\n" . substr($email['description'],0,300) . "\n\n");
		
		// Check description and description_html
		$newTo = extract_from_email( $email['description'] );
		if( empty( $newTo ) ) {
			print_wrapper(" - No email in description... checking description_html\n");
			$newTo = extract_from_email( $email['description_html'] );
		}
		
		if( empty( $newTo ) ) {
			print_wrapper("  - Unable to find a replacement 'To' Address\n");
			if( $VERBOSE_LOGGING ) {
				print_wrapper("(DEBUG) Description: " . $email['description'] . "\nDESCRIPTION_HTML: " . $email['description_html'] . "\n");
			}
		}
		else {

			print_wrapper("  + Associating Email with: $newTo\n");
		
			$email_id = $email['id'];
			$newTo = strtoupper($newTo);
		
			// See if there is an entry for the email_address in email_addresses table...
			// Stop if it doesn't (we will wait till it's created...) at which time emails_addr_bean_rel and emails_addr will be created for us.
			// Get Id if it does.
		      
			$query11=<<<QUERY11
select email_addresses.id, email_addr_bean_rel.bean_id, email_addr_bean_rel.bean_module
	from email_addresses
	join email_addr_bean_rel
		on email_addresses.id = email_addr_bean_rel.email_address_id
	where email_address_caps='$newTo' and email_addr_bean_rel.deleted != 1 and email_addresses.deleted != 1;
QUERY11;
	
			$bean_info = mxsql_query_get_first_result( $query11 );
			
			if( $bean_info == FALSE ) {
				print_wrapper( "  - No matching contact exists for: $newTo\n" );
			}
			else {
				
				// In emails_email_addr_rel, Do an update on the column that has email_address_id matching one from step aove, and address_type = "TO"
				// where email_id = AND address_type like "to" SET email_address_id = (above id)
				$updateToQry = 'UPDATE emails_email_addr_rel SET email_address_id=\'' . $bean_info['id'] . '\' where email_id=\'' . $email_id .'\' AND address_type like \'to\'';
				//print ("QUERY1: $updateToQry\n");
				mxsql_query_wrapper( $updateToQry );
				
				$guid = create_guid();
				$bean_id=$bean_info['bean_id'];
				$bean_module = $bean_info['bean_module'];
				$date_modified = $email['date_sent'];
		
				$query=<<<QUERYX
INSERT INTO emails_beans (id,email_id,bean_id,bean_module,date_modified,deleted) VALUES('$guid','$email_id','$bean_id','$bean_module','$date_modified','0');
QUERYX;
				mxsql_query_wrapper($query);
				//print ("QUERY2: $query\n");
			}
		}
	}






print_wrapper("\n\nSTEP2: Processing unarchived emails...\n");
print_wrapper("--------------------------------------\n");

if( $db_type == 'mysql' )
   $use_limit = 'LIMIT ' . $LIMIT_PROCESSING_TO;
else 
	$use_top = 'TOP ' . $LIMIT_PROCESSING_TO;

$query1=<<<QUERY1
Select $use_top emails.id, emails.name
FROM 
inbound_email join emails ON inbound_email.id = emails.mailbox_id
WHERE inbound_email.name='$MAILBOX_NAME' and emails.status != 'Archived' and emails.deleted != 1 and inbound_email.deleted !=1
ORDER BY emails.date_sent DESC $use_limit;
QUERY1;
	
	$email_results = mxsql_query_wrapper( $query1 );
	
	$noUserMatch=0;
	$noContactMatch=0;

	// FOR ALL THE UNPROCESSED EMAILS
	while($email = mxsql_fetch_array($email_results))
	{
		print_wrapper("\nProcessing Email with subject: " . $email['name'] . "\n");
		
		$email_id = $email['id'];
		
		$IGNORE_DOMAIN1 = strtoupper( $IGNORE_DOMAIN1 );
		$IGNORE_DOMAIN2 = strtoupper( $IGNORE_DOMAIN2 );
		
$query2=<<<QUERY2
Select emails.id, emails.name, emails.date_sent, emails_email_addr_rel.email_address_id, email_addresses.email_address_caps, emails_email_addr_rel.address_type, email_addr_bean_rel.bean_id, email_addr_bean_rel.bean_module, accounts_contacts.account_id
FROM emails 
  join emails_email_addr_rel
    ON emails.id = emails_email_addr_rel.email_id 
  join email_addr_bean_rel
    ON emails_email_addr_rel.email_address_id = email_addr_bean_rel.email_address_id
  join email_addresses
    ON emails_email_addr_rel.email_address_id = email_addresses.id
  LEFT OUTER JOIN accounts_contacts
    ON email_addr_bean_rel.bean_id = accounts_contacts.contact_id
WHERE emails.id = '$email_id' and (emails_email_addr_rel.address_type='to' OR emails_email_addr_rel.address_type='cc') AND
      email_addresses.email_address_caps not like '%$IGNORE_DOMAIN1%' AND email_addresses.email_address_caps not like '%$IGNORE_DOMAIN2%' AND
      email_addr_bean_rel.deleted != 1 and emails_email_addr_rel.deleted !=1 and email_addresses.deleted !=1
      and emails.deleted != 1 
 and if(isnull(accounts_contacts.deleted),0,accounts_contacts.deleted) != 1 -- necessary as by left join (if it is a user) the field can be undefined
QUERY2;

		//print "(Debug) $query2\n\n";

		// FIGURE out how many related beans there were included in the email
		$related_bean_result = mxsql_query_wrapper( $query2 );
		$related_bean_count = mxsql_num_rows( $related_bean_result );
		print_wrapper(" + Related Bean Count: $related_bean_count\n");
		
		print_wrapper(" + Email ID: $email_id\n");
		
		
		///// VERBOSE LOGGING TO PRINT ALL EMAIL ADDRESSES ASSOCIATED WITH THIS EMAIL!!!! ////////
		if( $VERBOSE_LOGGING ) {
			$qryAllEmailAddrs=<<<ALLEMAILS
Select emails.id, emails.name, email_addresses.email_address, emails.date_sent, emails_email_addr_rel.email_address_id, emails_email_addr_rel.address_type, email_addr_bean_rel.bean_id, email_addr_bean_rel.bean_module
FROM emails 
  join emails_email_addr_rel
    ON emails.id = emails_email_addr_rel.email_id 
  join email_addr_bean_rel
    ON emails_email_addr_rel.email_address_id = email_addr_bean_rel.email_address_id
  join email_addresses
    ON email_addresses.id = emails_email_addr_rel.email_address_id
WHERE emails.id = '$email_id'
and email_addresses.deleted  !=1 
and emails_email_addr_rel.deleted !=1 
and email_addr_bean_rel.deleted != 1 
and emails.deleted != 1;
ALLEMAILS;
			$temp33 = mxsql_query_wrapper( $qryAllEmailAddrs );
			while( $addr = mxsql_fetch_array($temp33) ) {
				print_wrapper( "   *** " . $addr['address_type'] . " " . $addr['email_address'] . " " . $addr['bean_module'] . " " . $addr['bean_id'] . "\n");
			}
		}
		///// END VERBOSE LOGGING TO PRINT ALL EMAIL ADDRESSES ASSOCIATED WITH THIS EMAIL!!!! ////////
			
			
		// If there is at least one related bean, then we have found a contact to associate with
		if( $related_bean_count > 0) {

// Finds the correct user for assigning the record to.
$queryFromId=<<<QUERYFromId
Select emails.id, emails.name, emails.date_sent, users.user_name, emails_email_addr_rel.email_address_id, emails_email_addr_rel.address_type, email_addr_bean_rel.bean_id, email_addr_bean_rel.bean_module
FROM emails 
  join emails_email_addr_rel
    ON emails.id = emails_email_addr_rel.email_id 
  join email_addr_bean_rel
    ON emails_email_addr_rel.email_address_id = email_addr_bean_rel.email_address_id
  join users
    ON email_addr_bean_rel.bean_id = users.id
WHERE emails.id = '$email_id' 
and (emails_email_addr_rel.address_type='from') and users.is_group='0' 
and emails_email_addr_rel.deleted != 1 and email_addr_bean_rel.deleted != 1 
and users.deleted != 1 and emails.deleted !=1 and emails_email_addr_rel.deleted != 1;
QUERYFromId;

			$temp1 = mxsql_query_wrapper($queryFromId);
			$assignedUserArr = mxsql_fetch_array($temp1);
			if( mxsql_num_rows($temp1) < 1 ) {
				print_wrapper(" - Can't locate a user account for FROM address.  Email will remain in the group inbox folder.\n");
				$noUserMatch++;
			}
			else {
				$assignedUser = $assignedUserArr['bean_id'];
				print_wrapper(" + FROM Address Recognized as USER:" . $assignedUserArr['user_name'] . "\n");
				
				// COMMENT THE TWO LINES BELOW WHEN DOING DEVELOPMENT TO PREVENT EMAILS FROM BEING REMOVED FROM QUEUE (so you don't have to keep resending emails).
				$updateQuery = 'UPDATE emails SET status=\'Archived\', type=\'outbound\', assigned_user_id=\'' . $assignedUser . '\' WHERE id=\'' . $email_id . '\'';
				mxsql_query_wrapper($updateQuery);
			
				log_entry("Archived email $email_id sent by:" . $assignedUserArr['user_name'] . "\n", $SUCCESS_LOG_FILE_PATH);
			
				
// This query makes use of inner joins to find get the account_id's for any of the contacts associated with this email.
$query3=<<<QUERY3
Select emails.id, emails.name, emails.date_sent,accounts_contacts.account_id, emails_email_addr_rel.address_type, email_addr_bean_rel.bean_id, email_addr_bean_rel.bean_module
FROM emails 
  join emails_email_addr_rel
    ON emails.id = emails_email_addr_rel.email_id 
  join email_addr_bean_rel
    ON emails_email_addr_rel.email_address_id = email_addr_bean_rel.email_address_id
  join accounts_contacts 
    ON email_addr_bean_rel.bean_id = accounts_contacts.contact_id
WHERE emails.id = '$email_id' and (emails_email_addr_rel.address_type='to' OR emails_email_addr_rel.address_type='cc')
AND accounts_contacts.deleted != 1 and email_addr_bean_rel.deleted != 1 
and emails_email_addr_rel.deleted != 1 and accounts_contacts.deleted  != 1
and emails.deleted != 1
;
QUERY3;
;
				$accounts = mxsql_query_wrapper( $query3 );
				while( $account = mxsql_fetch_array($accounts) ) {
					
					$guid = create_guid();
					$bean_id=$account['account_id'];
					$email_id=$account['id'];
					$date_modified = $account['date_sent'];
				
				// This query adds a new row in the emails_beans to link the email to the Account (if we didn't do this only the Contact is linked)
				// NEW in version 1.2 this is only called once per account.
$query4a=<<<QUERY4a
select id from emails_beans where email_id='$email_id' AND bean_id='$bean_id' and email_beans.deleted != 1 ;
QUERY4a;

$query4=<<<QUERY4
INSERT INTO emails_beans (id,email_id,bean_id,bean_module,date_modified,deleted) VALUES('$guid','$email_id','$bean_id','Accounts','$date_modified','0');
QUERY4;
					if( mxsql_query_get_first_result($query4a) == FALSE ) {
						print_wrapper("   + Added Account Ref\n");
						mxsql_query_wrapper($query4);
						$updateQuery = 'UPDATE emails SET parent_type="Accounts", parent_id=\''. $bean_id . '\' WHERE id=\'' . $email_id . '\'';
						mxsql_query_wrapper($updateQuery);
					}
					else {
						print_wrapper("   + Already added once\n");
					}
				
				}
			}			
		}
		else {
			print_wrapper(" - No Matching Leads/Contacts/Beans.  Email will remain in the group inbox folder.\n"); 
			$noContactMatch++;
		}
	}
	
	mxsql_close($dbcon);
	
	print_wrapper("\nSUMMARY: Unable to process " . ($noContactMatch+$noUserMatch) . " msgs.  No Matching User: $noUserMatch, No Matching Contact: $noContactMatch\n");
	print_wrapper("Exiting\n");
	
	
	
//--------------------[ PRIVATE UTILITIY METHODS ]-------------------------//

function extract_from_email($string){
 // preg_match("/From.*\w+([\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+)/i", $string, $matches);
  preg_match("/(From|Von).*\w+[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i", $string, $matches);
  preg_match("/[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i", $matches[0], $matches);
  return $matches[0];
}


// Wrapper method around the print method.
function print_wrapper( $str ) {
	global $is_cli;
	global $ENABLE_LOGGING;
		
	print $str;
	
	if( $ENABLE_LOGGING ) {
		log_entry($str);
	}
	
	if( !$is_cli ) {
		print "</br>";
	}
	
}




// Wrapper method which runs a query and returns the result of the first row, 
// if 2nd parameter is specified only that column is returned otherwise the entire row is returned.
// FALSE is returned if query returns no results.
function mxsql_query_get_first_result( $query, $col='row' ) {
	$retVal = FALSE;
	$result = mxsql_query_wrapper( $query );
	
	if( $result ) {	
		$row = mxsql_fetch_array( $result );
		if ($row) {
			if( $col == 'row' ) {
				$retVal = $row;
			}
			else {
				$retVal= $row[$col];
			}
		}	
	}
	return $retVal;
}

// Wrapper method which just prints the error out to standard out if one occurs.
function mxsql_query_wrapper( $query ) {
	global $dbcon;
	$temp = mxsql_query( $query, $dbcon );
	if( $temp == NULL ) {
		$msg = "MXSQL ERROR: " . mxsql_error() . "\nQUERY: $query\n";
		print_wrapper( $msg );
	}
	return $temp;
}

//---------- MYSQL / MSSQL wrapper Methods

function mxsql_query( $query, $dbcon ) {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_query($query,$dbcon);
	else 
		return mssql_query($query,$dbcon);
}

function mxsql_fetch_array( $queryResult ) {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_fetch_array($queryResult);
	else 
		return mssql_fetch_array($queryResult);
}

function mxsql_num_rows( $queryResult ) {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_num_rows($queryResult);
	else 
		return mssql_num_rows($queryResult);
}

function mxsql_connect( $db_host_name,$db_user_name,$db_password) {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_connect( $db_host_name,$db_user_name,$db_password);
	else 
		return mssql_connect( $db_host_name,$db_user_name,$db_password);
}

function mxsql_select_db($db_name, $dbcon) {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_select_db($db_name, $dbcon);
	else 
		return mssql_select_db($db_name, $dbcon);
}	

function mxsql_error() {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_error();
	else 
		return mssql_error();
}

function mxsql_close( $dbcon ) {
	global $db_type;
	if( $db_type == 'mysql') 
		return mysql_close( $dbcon );
	else 
		return mssql_close( $dbcon );
}

// 20110713 added to allow return of MSSQL error messages.
function mssql_error() {
	return mssql_get_last_message();
}

function log_entry( $str, $file = "default" ) {
	global $LOG_FILE_PATH;
	if( $file == "default" ) {
		$file = $LOG_FILE_PATH;
	}
	
	$handle = fopen($file, 'a');
	fwrite($handle, "[" . date('Y-m-j H:i:s') . "] " . $str );
	fclose($handle);
}

// ---- The following methods were copied from a sugarcrm utils.php class -----//

function create_guid()
{
    $microTime = microtime();
	list($a_dec, $a_sec) = explode(" ", $microTime);

	$dec_hex = sprintf("%x", $a_dec* 1000000);
	$sec_hex = sprintf("%x", $a_sec);

	ensure_length($dec_hex, 5);
	ensure_length($sec_hex, 6);

	$guid = "";
	$guid .= $dec_hex;
	$guid .= create_guid_section(3);
	$guid .= '-';
	$guid .= create_guid_section(4);
	$guid .= '-';
	$guid .= create_guid_section(4);
	$guid .= '-';
	$guid .= create_guid_section(4);
	$guid .= '-';
	$guid .= $sec_hex;
	$guid .= create_guid_section(6);

	return $guid;

}

function create_guid_section($characters)
{
	$return = "";
	for($i=0; $i<$characters; $i++)
	{
		$return .= sprintf("%x", mt_rand(0,15));
	}
	return $return;
}

function ensure_length(&$string, $length)
{
	$strlen = strlen($string);
	if($strlen < $length)
	{
		$string = str_pad($string,$length,"0");
	}
	else if($strlen > $length)
	{
		$string = substr($string, 0, $length);
	}
}

function microtime_diff($a, $b) {
   list($a_dec, $a_sec) = explode(" ", $a);
   list($b_dec, $b_sec) = explode(" ", $b);
   return $b_sec - $a_sec + $b_dec - $a_dec;
}
 RELEASE INCLUDE END */

?>
