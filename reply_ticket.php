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

define('IN_SCRIPT',1);
define('HESK_PATH','./');

/* Get all the required files and functions */
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
require(HESK_PATH . 'inc/database.inc.php');
require(HESK_PATH . 'inc/email_functions.inc.php');
require(HESK_PATH . 'inc/posting_functions.inc.php');

hesk_session_start();

/* A security check */
# hesk_token_check($_POST['token']);

$hesk_error_buffer = array();

/* Tracking ID */
$trackingID  = hesk_cleanID($_POST['orig_track']) or die($hesklang['int_error'].': No orig_track');

/* Email required to view ticket? */
$my_email = hesk_getCustomerEmail();

/* Message entered? */
$message = hesk_input($_POST['message']);
if (!strlen($message))
{
	$hesk_error_buffer[] = $hesklang['enter_message'];
}

$message = hesk_makeURL($message);
$message = nl2br($message);

/* Attachments */
if ($hesk_settings['attachments']['use'])
{
    require(HESK_PATH . 'inc/attachments.inc.php');
    $attachments = array();
    for ($i=1;$i<=$hesk_settings['attachments']['max_number'];$i++)
    {
        $att = hesk_uploadFile($i);
        if ($att !== false && !empty($att))
        {
            $attachments[$i] = $att;
        }
    }
}
$myattachments='';

/* Any errors? */
if (count($hesk_error_buffer)!=0)
{
    $_SESSION['ticket_message']  = $_POST['message'];

    $tmp = '';
    foreach ($hesk_error_buffer as $error)
    {
        $tmp .= "<li>$error</li>\n";
    }
    $hesk_error_buffer = $tmp;

    $hesk_error_buffer = $hesklang['pcer'].'<br /><br /><ul>'.$hesk_error_buffer.'</ul>';
    hesk_process_messages($hesk_error_buffer,'ticket.php?track='.$trackingID.$hesk_settings['e_param'].'&Refresh='.rand(10000,99999));
}

/* Connect to database */
hesk_dbConnect();

/* Get details about the original ticket */
$sql = "SELECT * FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `trackid`='".hesk_dbEscape($trackingID)."' LIMIT 1";
$res = hesk_dbQuery($sql);
if (hesk_dbNumRows($res) != 1)
{
	hesk_error($hesklang['ticket_not_found']);
}
$ticket = hesk_dbFetchAssoc($res);
$ticket['lastreplier'] = $ticket['name'];

/* If we require e-mail to view tickets check if it matches the one in database */
hesk_verifyEmailMatch($trackingID, $my_email, $ticket['email']);

/* Ticket locked? */
if ($ticket['locked'])
{
	hesk_process_messages($hesklang['tislock2'],'ticket.php?track='.$trackingID.$hesk_settings['e_param'].'&Refresh='.rand(10000,99999));
	exit();
}

/* Insert attachments */
if ($hesk_settings['attachments']['use'] && !empty($attachments))
{
    foreach ($attachments as $myatt)
    {
        $sql = "INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."attachments` (`ticket_id`,`saved_name`,`real_name`,`size`) VALUES (
        '".hesk_dbEscape($trackingID)."',
        '".hesk_dbEscape($myatt['saved_name'])."',
        '".hesk_dbEscape($myatt['real_name'])."',
        '".hesk_dbEscape($myatt['size'])."'
        )";
        $res = hesk_dbQuery($sql);
        $myattachments .= hesk_dbInsertID() . '#' . $myatt['real_name'] .',';
    }
}

/* Update ticket as necessary */
$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` SET `status`='1',`lastreplier`='0' WHERE `id`=".hesk_dbEscape($ticket['id'])." LIMIT 1";
$res = hesk_dbQuery($sql);

/* Add reply */
$sql = "
INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."replies` (
`replyto`,`name`,`message`,`dt`,`attachments`
)
VALUES (
'".hesk_dbEscape($ticket['id'])."',
'".hesk_dbEscape($ticket['name'])."',
'".hesk_dbEscape($message)."',
NOW(),
'".hesk_dbEscape($myattachments)."'
)
";
$res = hesk_dbQuery($sql);


/*** Need to notify any staff? ***/

/* --> Prepare reply message */
$ticket['subject'] = hesk_msgToPlain($ticket['subject'], 1, 0);
$ticket['message'] = hesk_msgToPlain($message, 1);

/* --> If ticket is assigned just notify the owner */
if ($ticket['owner'])
{
	hesk_notifyAssignedStaff(false, 'new_reply_by_customer', 'notify_reply_my');
}
/* --> No owner assigned, find and notify appropriate staff */
else
{
	hesk_notifyStaff('new_reply_by_customer',"`notify_reply_unassigned`='1'");
}

/* Clear unneeded session variables */
hesk_cleanSessionVars('ticket_message');

/* Show the ticket and the success message */
hesk_process_messages($hesklang['reply_submitted_success'],'ticket.php?track='.$trackingID.$hesk_settings['e_param'].'&Refresh='.rand(10000,99999),'SUCCESS');
exit();
?>
