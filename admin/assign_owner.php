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
define('HESK_PATH','../');

/* Get all the required files and functions */
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
require(HESK_PATH . 'inc/admin_functions.inc.php');
require(HESK_PATH . 'inc/database.inc.php');
require(HESK_PATH . 'inc/email_functions.inc.php');

hesk_session_start();
hesk_dbConnect();
hesk_isLoggedIn();

$can_assign_others = hesk_checkPermission('can_assign_others',0);
if ($can_assign_others)
{
	$can_assign_self = TRUE;
}
else
{
	$can_assign_self = hesk_checkPermission('can_assign_self',0);
}

/* A security check */
hesk_token_check( hesk_REQUEST('token') );

/* Ticket ID */
$trackingID = hesk_cleanID( hesk_REQUEST('track') ) or die($hesklang['int_error'].': '.$hesklang['no_trackID']);

$sql = "SELECT * FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `trackid`='".hesk_dbEscape($trackingID)."' LIMIT 1";
$res = hesk_dbQuery($sql);
if (hesk_dbNumRows($res) != 1)
{
	hesk_error($hesklang['ticket_not_found']);
}
$ticket = hesk_dbFetchAssoc($res);

$_SERVER['PHP_SELF'] = 'admin_ticket.php?track='.$trackingID.'&Refresh='.rand(10000,99999);

/* New owner ID */
$owner = intval( hesk_REQUEST('owner') );

/* If ID is -1 the ticket will be unassigned */
if ($owner == -1)
{
	$revision = sprintf($hesklang['thist2'],hesk_date(),'<i>'.$hesklang['unas'].'</i>',$_SESSION['name'].' ('.$_SESSION['user'].')');
	$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` SET `owner`=0 , `history`=CONCAT(`history`,'".hesk_dbEscape($revision)."') WHERE `trackid`='".hesk_dbEscape($trackingID)."' LIMIT 1";
	$res = hesk_dbQuery($sql);
    
    hesk_process_messages($hesklang['tunasi2'],$_SERVER['PHP_SELF'],'SUCCESS');
}
elseif ($owner < 1)
{
    hesk_process_messages($hesklang['nose'],$_SERVER['PHP_SELF'],'NOTICE');
}

/* Verify the new owner and permissions */
$sql = "SELECT `id`,`user`,`name`,`email`,`isadmin`,`categories`,`notify_assigned` FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."users` WHERE `id`=$owner LIMIT 1";
$res = hesk_dbQuery($sql);
$row = hesk_dbFetchAssoc($res);

/* Has new owner access to the category? */
if ( ! $row['isadmin'])
{
	$row['categories']=explode(',',$row['categories']);
	if (!in_array($ticket['category'],$row['categories']))
	{
		hesk_error($hesklang['unoa']);
	}
}

/* Assigning to self? */
if ($can_assign_others || ($owner == $_SESSION['id'] && $can_assign_self))
{
	$revision = sprintf($hesklang['thist2'],hesk_date(),$row['name'].' ('.$row['user'].')',$_SESSION['name'].' ('.$_SESSION['user'].')');
	$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` SET `owner`='".hesk_dbEscape($owner)."' , `history`=CONCAT(`history`,'".hesk_dbEscape($revision)."') WHERE `trackid`='".hesk_dbEscape($trackingID)."' LIMIT 1";
	$res = hesk_dbQuery($sql);

    if ($owner != $_SESSION['id'] && !hesk_checkPermission('can_view_ass_others',0))
    {
    	$_SERVER['PHP_SELF']='admin_main.php';
    }
}
else
{
	hesk_error($hesklang['no_permission']);
}

$ticket['owner'] = $owner;

/* --> Prepare message */
$ticket['subject'] = hesk_msgToPlain($ticket['subject'], 1, 0);
$ticket['message'] = hesk_msgToPlain($ticket['message'], 1, 0);

/* Notify the new owner? */
if ($ticket['owner'] != intval($_SESSION['id']))
{
	hesk_notifyAssignedStaff(false, 'ticket_assigned_to_you');
}

$tmp = ($owner == $_SESSION['id']) ? $hesklang['tasy'] : $hesklang['taso'];
hesk_process_messages($tmp,$_SERVER['PHP_SELF'],'SUCCESS');
?>
