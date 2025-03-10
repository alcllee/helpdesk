<?php
/*******************************************************************************
*  Title: Help Desk Software HESK
*  Version: 2.4.2 from 30th December 2012
*  Author: Klemen Stirn
*  Website: http://www.hesk.com
********************************************************************************
*  COPYRIGHT AND TRADEMARK NOTICE
*  Copyright 2005-2012 Klemen Stirn. All Rights Reserved.
*  HESK is a registered trademark of Klemen Stirn.

*  The HESK may be used and modified free of charge by anyone
*  AS LONG AS COPYRIGHT NOTICES AND ALL THE COMMENTS REMAIN INTACT.
*  By using this code you agree to indemnify Klemen Stirn from any
*  liability that might arise from it's use.

*  Selling the code for this program, in part or full, without prior
*  written consent is expressly forbidden.

*  Using this code, in part or full, to create derivate work,
*  new scripts or products is expressly forbidden. Obtain permission
*  before redistributing this software over the Internet or in
*  any other medium. In all cases copyright and header must remain intact.
*  This Copyright is in full effect in any country that has International
*  Trade Agreements with the United States of America or
*  with the European Union.

*  Removing any of the copyright notices without purchasing a license
*  is expressly forbidden. To remove HESK copyright notice you must purchase
*  a license for this script. For more information on how to obtain
*  a license please visit the page below:
*  https://www.hesk.com/buy.php
*******************************************************************************/

/* Check if this is a valid include */
if (!defined('IN_SCRIPT')) {die('Invalid attempt');} 

// Include all functions needed for email piping
require(HESK_PATH . 'inc/database.inc.php');
require(HESK_PATH . 'inc/email_functions.inc.php');
require(HESK_PATH . 'inc/posting_functions.inc.php');
require(HESK_PATH . 'inc/mail/rfc822_addresses.php');
require(HESK_PATH . 'inc/mail/mime_parser.php');
require(HESK_PATH . 'inc/mail/email_parser.php');

/*** FUNCTIONS ***/

function hesk_email2ticket($results, $pop3 = 0)
{
	global $hesk_settings, $hesklang, $hesk_db_link, $ticket;

	// Process "From:" email
	$tmpvar['email'] = hesk_validateEmail($results['from'][0]['address'],'ERR',0);

	// "From:" email missing or invalid?
	if ( ! $tmpvar['email'] )
	{
		return NULL;
	}

	// Process "From:" name, set to "[Customer]" if not set
	$tmpvar['name'] = hesk_input($results['from'][0]['name']) or $tmpvar['name'] = $hesklang['pde'];

	// Process "To:" email (not yet implemented, for future use)
	// $tmpvar['to_email']	= hesk_validateEmail($results['to'][0]['address'],'ERR',0);

	// Process email subject, convert to UTF-8 if needed, set to "[Piped email]" if none set
	$tmpvar['subject'] = $results['subject'];
	if ( ! empty($results['subject_encoding']) && strtoupper($results['subject_encoding']) != 'UTF-8' )
	{
		$tmpvar['subject'] = iconv($results['subject_encoding'], 'UTF-8', $tmpvar['subject']);
	}
	$tmpvar['subject'] = hesk_input($tmpvar['subject'],'','',1) or $tmpvar['subject'] = $hesklang['pem'];

	// Process email message, convert to UTF-8
	$tmpvar['message'] = $results['message'];
	if ( ! empty($results['encoding']) && strtoupper($results['encoding']) != 'UTF-8' )
	{
		$tmpvar['message'] = iconv($results['encoding'], 'UTF-8', $tmpvar['message']);
	}
	$tmpvar['message'] = hesk_input($tmpvar['message'],'','',1);

	// Message missing? We require it!
	if ( ! $tmpvar['message'])
	{
		return NULL;
	}

	// Strip quoted reply from email
    $tmpvar['message'] = hesk_stripQuotedText($tmpvar['message']);

	// Convert URLs to links, change newlines to <br />
	$tmpvar['message'] = hesk_makeURL($tmpvar['message']);
	$tmpvar['message'] = nl2br($tmpvar['message']);

	# For debugging purposes
    # die( bin2hex($tmpvar['message']) );
    # die($tmpvar['message']);

	// Try to detect "delivery failed" and "noreply" emails - ignore if detected
	if ( hesk_isReturnedEmail($tmpvar) )
	{
		return NULL;
	}

	// Check for email loops
	if ( hesk_isEmailLoop($tmpvar['email'], md5($tmpvar['message']) ) )
	{
		return NULL;
	}

	// OK, everything seems OK. Now determine if this is a reply to a ticket or a new ticket
	if ( preg_match('/\[#([A-Z0-9]{3}\-[A-Z0-9]{3}\-[A-Z0-9]{4})\]/', $tmpvar['subject'], $matches) )
	{
		// We found a possible tracking ID
		$tmpvar['trackid'] = $matches[1];

	    // Does it match one in the database?
		$sql = "SELECT * FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `trackid`='".hesk_dbEscape($tmpvar['trackid'])."' LIMIT 1";
		$res = hesk_dbQuery($sql);
		if (hesk_dbNumRows($res))
		{
			$ticket = hesk_dbFetchAssoc($res);

	        // Do email addresses match?
	        if ( strpos( strtolower($ticket['email']), strtolower($tmpvar['email']) ) === false )
	        {
	        	$tmpvar['trackid'] = '';
	        }

	        // Is this ticket locked? Force create a new one if it is
	        if ($ticket['locked'])
	        {
	        	$tmpvar['trackid'] = '';
	        }
	    }
	    else
	    {
	    	$tmpvar['trackid'] = '';
	    }
	}

	// If tracking ID is empty, generate a new one
	if ( empty($tmpvar['trackid']) )
	{
		$tmpvar['trackid'] = hesk_createID();
	    $is_reply = 0;
	}
	else
	{
		$is_reply = 1;
	}

	// Process attachments
	$tmpvar['attachments'] = '';
	$num = 0;
	if ($hesk_settings['attachments']['use'] && isset($results['attachments'][0]))
	{
    	#print_r($results['attachments']);

	    foreach ($results['attachments'] as $k => $v)
	    {

	    	// Check number of attachments, delete any over max number
	        if ($num >= $hesk_settings['attachments']['max_number'])
	        {
	            break;
	        }

			// Clean attachment names
			$myatt['real_name'] = hesk_cleanFileName($v['orig_name']);

	        // Check file extension
			$ext = strtolower(strrchr($myatt['real_name'], "."));
			if (!in_array($ext,$hesk_settings['attachments']['allowed_types']))
			{
				continue;
			}

	        // Check file size
	        $myatt['size'] = $v['size'];
			if ($myatt['size'] > ($hesk_settings['attachments']['max_size']))
			{
				continue;
			}

			// Generate a random file name
			$useChars='AEUYBDGHJLMNPQRSTVWXZ123456789';
			$tmp = $useChars{mt_rand(0,29)};
			for($j=1;$j<10;$j++)
			{
			    $tmp .= $useChars{mt_rand(0,29)};
			}
		    $myatt['saved_name'] = substr($tmpvar['trackid'] . '_' . md5($tmp . $myatt['real_name']), 0, 200) . $ext;

	        // Rename the temporary file
	        rename($v['stored_name'],HESK_PATH.'attachments/'.$myatt['saved_name']);

	        // Insert into database
	        $sql = "INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."attachments` (`ticket_id`,`saved_name`,`real_name`,`size`) VALUES (
	        '".hesk_dbEscape($tmpvar['trackid'])."',
	        '".hesk_dbEscape($myatt['saved_name'])."',
	        '".hesk_dbEscape($myatt['real_name'])."',
	        '".hesk_dbEscape($myatt['size'])."')";
	        $res = hesk_dbQuery($sql);
	        $tmpvar['attachments'] .= hesk_dbInsertID() . '#' . $myatt['real_name'] .',';

	        $num++;
	    }
	}

	// Delete the temporary files
	deleteAll($results['tempdir']);

	// If this is a reply add a new reply
	if ($is_reply)
	{
		// Set last replier name to customer name
		$ticket['lastreplier'] = $ticket['name'];

		// Update ticket as necessary
		$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` SET `status`='1',`lastreplier`='0' WHERE `id`=".hesk_dbEscape($ticket['id'])." LIMIT 1";
		$res = hesk_dbQuery($sql);

		// Insert reply into database
		$sql = "
		INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."replies` (
		`replyto`,`name`,`message`,`dt`,`attachments`
		)
		VALUES (
		'".hesk_dbEscape($ticket['id'])."',
		'".hesk_dbEscape($ticket['name'])."',
		'".hesk_dbEscape($tmpvar['message'])."',
		NOW(),
		'".hesk_dbEscape($tmpvar['attachments'])."'
		)
		";
		$res = hesk_dbQuery($sql);

		// --> Prepare reply message
		$ticket['subject'] = hesk_msgToPlain($ticket['subject'], 1, 0);
		$ticket['message'] = hesk_msgToPlain($tmpvar['message'], 1);

		// --> If ticket is assigned just notify the owner
		if ($ticket['owner'])
		{
			hesk_notifyAssignedStaff(false, 'new_reply_by_customer', 'notify_reply_my');
		}
		// --> No owner assigned, find and notify appropriate staff
		else
		{
			hesk_notifyStaff('new_reply_by_customer',"`notify_reply_unassigned`='1'");
		}

		return $ticket['trackid'];

	} // END REPLY

	// Not a reply, but a new ticket. Add it to the database
	$tmpvar['category'] 	= 1;
	$tmpvar['priority'] 	= 3;
	$_SERVER['REMOTE_ADDR'] = $hesklang['unknown'];

	// Auto assign tickets if aplicable
	$tmpvar['owner']   = 0;
	$tmpvar['history'] = $pop3 ? sprintf($hesklang['thist16'], hesk_date()) : sprintf($hesklang['thist11'], hesk_date());

	$autoassign_owner = hesk_autoAssignTicket($tmpvar['category']);

	#print_r($autoassign_owner);

	if ($autoassign_owner)
	{
		$tmpvar['owner'] = $autoassign_owner['id'];
	    $tmpvar['history'] .= sprintf($hesklang['thist10'],hesk_date(),$autoassign_owner['name'].' ('.$autoassign_owner['user'].')');
	}

	// Custom fields will be empty as there is no reliable way of detecting them
	foreach ($hesk_settings['custom_fields'] as $k=>$v)
	{
		$tmpvar[$k] = '';
	}

	// Insert ticket to database
	$ticket = hesk_newTicket($tmpvar);

	// Notify the customer
	hesk_notifyCustomer();

	// Need to notify staff?
	// --> From autoassign?
	if ($tmpvar['owner'] && $autoassign_owner['notify_assigned'])
	{
		hesk_notifyAssignedStaff($autoassign_owner, 'ticket_assigned_to_you');
	}
	// --> No autoassign, find and notify appropriate staff
	elseif ( ! $tmpvar['owner'] )
	{
		hesk_notifyStaff('new_ticket_staff', " `notify_new_unassigned` = '1' ");
	}

    return $ticket['trackid'];
} // END hesk_email2ticket()


function hesk_stripQuotedText($message)
{
	global $hesk_settings, $hesklang;

	// Stripping quoted text disabled?
	if ( ! $hesk_settings['strip_quoted'])
	{
		return $message;
	}

	// Loop through available languages and ty to find the tag
	foreach ($hesk_settings['languages'] as $language => $settings)
	{
		if ( ($found = strpos($message, $settings['hr']) ) !== false )
		{
			// "Reply above this line" tag found, strip quoted reply
			$message  = substr($message, 0, $found);
            $message .= "\n" . $hesklang['qrr'];

            // Set language to the detected language
            hesk_setLanguage($language);
			break;
		}
	}

	return $message;
} // END hesk_stripQuotedText()


function hesk_isReturnedEmail($tmpvar)
{
	// Check noreply email addresses
	if ( preg_match('/not?[\-_]reply@/i', $tmpvar['email']) )
	{
		return true;
	}

	// Check mailer daemon email addresses
	if ( preg_match('/mail(er)?[\-_]daemon@/i', $tmpvar['email']) )
	{
		return true;
	}

	// Check autoreply subjects
	if ( preg_match('/^[\[\(]?Auto(mat(ic|ed))?[ \-]?reply/i', $tmpvar['subject']) )
	{
		return true;
	}

	// Check out of office subjects
	if ( preg_match('/^Out of Office/i', $tmpvar['subject']) )
	{
		return true;
	}

	// Check delivery failed email subjects
	if (
	preg_match('/DELIVERY FAILURE/i', $tmpvar['subject']) ||
	preg_match('/Undelivered Mail Returned to Sender/i', $tmpvar['subject']) ||
	preg_match('/Delivery Status Notification \(Failure\)/i', $tmpvar['subject']) ||
	preg_match('/Returned mail\: see transcript for details/i', $tmpvar['subject'])
	)
	{
		return true;
	}

	// Check Mail Delivery sender name
	if ( preg_match('/Mail[ \-_]?Delivery/i', $tmpvar['name']) )
	{
		return true;
	}

	// Check Delivery failed message
	if ( preg_match('/postmaster@/i', $tmpvar['email']) && preg_match('/Delivery has failed to these recipients/i', $tmpvar['message']) )
	{
		return true;
	}

	// No pattern detected, seems like this is not a returned email
	return false;

} // END hesk_isReturnedEmail()


function hesk_isEmailLoop($email, $message_hash)
{
	global $hesk_settings, $hesklang, $hesk_db_link;

	// If $hesk_settings['loop_hits'] is set to 0 this function is disabled
    if ( ! $hesk_settings['loop_hits'])
    {
    	return false;
    }

	// Delete expired DB entries
	$sql = "DELETE FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."pipe_loops` WHERE `dt` < (NOW() - INTERVAL ".intval($hesk_settings['loop_time'])." SECOND) ";
	$res = hesk_dbQuery($sql);

	// Check current entry
	$sql = "SELECT `hits`, `message_hash` FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."pipe_loops` WHERE `email` LIKE '".hesk_dbEscape($email)."' LIMIT 1";
	$res = hesk_dbQuery($sql);

	// Any active entry*
	if (hesk_dbNumRows($res))
	{
		list($num, $md5) = hesk_dbFetchRow($res);

		$num++;

		// Number of emails in a time period reached?
		if ($num >= $hesk_settings['loop_hits'])
		{
			return true;
		}

        // Message exactly the same as in previous email?
        if ($message_hash == $md5)
        {
        	return true;
        }

		// Update DB entry
		$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."pipe_loops` SET `hits` = `hits` + 1, `message_hash` = '".hesk_dbEscape($message_hash)."' WHERE `email` LIKE '".hesk_dbEscape($email)."' LIMIT 1";
	}
	else
	{
		// First instance, insert a new database row
		$sql = "INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."pipe_loops` (`email`, `message_hash`) VALUES ('".hesk_dbEscape($email)."', '".hesk_dbEscape($message_hash)."')";
	}
	$res = hesk_dbQuery($sql);

	// No loop rule trigered
    return false;

} // END hesk_isEmailLoop()
