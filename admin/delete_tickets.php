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

hesk_session_start();
hesk_dbConnect();
hesk_isLoggedIn();

/* Set correct return URL */
if (isset($_SERVER['HTTP_REFERER']))
{
	$url = hesk_input($_SERVER['HTTP_REFERER']);
    $url = str_replace('&amp;','&',$url);
	if ($tmp = strstr($url,'show_tickets.php'))
    {
    	$referer = $tmp;
    }
	elseif ($tmp = strstr($url,'find_tickets.php'))
    {
    	$referer = $tmp;
    }
    elseif ($tmp = strstr($url,'admin_main.php'))
    {
    	$referer = $tmp;
    }
    else
    {
    	$referer = 'admin_main.php';
    }
}
else
{
	$referer = 'admin_main.php';
}

/* Is this a delete ticket request from within a ticket ("delete" icon)? */
if ( isset($_GET['delete_ticket']) )
{
    /* Check permissions for this feature */
	hesk_checkPermission('can_del_tickets');

	/* A security check */
	hesk_token_check($_GET['token']);

    $trackingID = strtoupper(hesk_input($_GET['track']));

	/* Get ticket info */
	$sql = "SELECT `id`,`trackid`,`category` FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `trackid`='".hesk_dbEscape($trackingID)."' LIMIT 1";
	$result = hesk_dbQuery($sql);
	if (hesk_dbNumRows($result) != 1)
	{
		hesk_error($hesklang['ticket_not_found']);
	}
	$ticket = hesk_dbFetchAssoc($result);

	/* Is this user allowed to delete tickets inside this category? */
	hesk_okCategory($ticket['category']);

	hesk_fullyDeleteTicket();

    hesk_process_messages(sprintf($hesklang['num_tickets_deleted'],1),$referer,'SUCCESS');
}


/* This is a request from ticket list. Must be POST and id must be an array */
if ( ! isset($_POST['id']) || ! is_array($_POST['id']) )
{
	hesk_process_messages($hesklang['no_selected'], $referer, 'NOTICE');
}
/* If not, then needs an action (a) POST variable set */
elseif ( ! isset($_POST['a']) )
{
	hesk_process_messages($hesklang['invalid_action'], $referer);
}

$i=0;

/* DELETE */
if ($_POST['a']=='delete')
{
    /* Check permissions for this feature */
	hesk_checkPermission('can_del_tickets');

	/* A security check */
	hesk_token_check($_POST['token']);

    foreach ($_POST['id'] as $this_id)
    {
        $this_id = hesk_isNumber($this_id,$hesklang['id_not_valid']);
        $sql = 'SELECT `id`,`trackid`,`category` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($this_id).' LIMIT 1';
        $result = hesk_dbQuery($sql);
		if (hesk_dbNumRows($result) != 1)
		{
			continue;
		}
        $ticket = hesk_dbFetchAssoc($result);

        hesk_okCategory($ticket['category']);

        hesk_fullyDeleteTicket();
        $i++;
    }

    hesk_process_messages(sprintf($hesklang['num_tickets_deleted'],$i),$referer,'SUCCESS');
}
/* MERGE TICKETS */
elseif ($_POST['a']=='merge')
{
    /* Check permissions for this feature */
	hesk_checkPermission('can_merge_tickets');

	/* A security check */
	hesk_token_check($_POST['token']);

	/* Sort IDs, tickets will be merged to the lowest ID */
    sort($_POST['id'], SORT_NUMERIC);

    /* Select lowest ID as the target ticket */
    $merge_into = array_shift($_POST['id']);

	/* Merge tickets or throw an error */
	if ( hesk_mergeTickets( $_POST['id'] , $merge_into ) )
    {
		hesk_process_messages($hesklang['merged'],$referer,'SUCCESS');
    }
    else
    {
    	$hesklang['merge_err'] .= ' ' . $_SESSION['error'];
        hesk_cleanSessionVars($_SESSION['error']);
    	hesk_process_messages($hesklang['merge_err'],$referer);
    }
}
/* TAG/UNTAG TICKETS */
elseif ($_POST['a']=='tag' || $_POST['a']=='untag')
{
    /* Check permissions for this feature */
	hesk_checkPermission('can_add_archive');

	/* A security check */
	hesk_token_check($_POST['token']);

    if ($_POST['a']=='tag')
    {
    	$archived = 1;
        $action = $hesklang['num_tickets_tag'];
    }
    else
    {
		$archived = 0;
        $action = $hesklang['num_tickets_untag'];
    }

    foreach ($_POST['id'] as $this_id)
    {
        $this_id = hesk_isNumber($this_id,$hesklang['id_not_valid']);
        $sql = 'SELECT `id`,`trackid`,`category` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($this_id).' LIMIT 1';
        $result = hesk_dbQuery($sql);
		if (hesk_dbNumRows($result) != 1)
		{
			continue;
		}
        $ticket = hesk_dbFetchAssoc($result);

        hesk_okCategory($ticket['category']);

		$sql = 'UPDATE `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` SET `archive`=\''.$archived.'\' WHERE `id`='.hesk_dbEscape($this_id).' LIMIT 1';
        hesk_dbQuery($sql);
        $i++;
    }

    hesk_process_messages(sprintf($action,$i),$referer,'SUCCESS');
}
/* JUST CLOSE */
else
{
    /* Check permissions for this feature */
	hesk_checkPermission('can_view_tickets');
    hesk_checkPermission('can_reply_tickets');

	/* A security check */
	hesk_token_check($_POST['token']);

    $revision = sprintf($hesklang['thist3'],hesk_date(),$_SESSION['name'].' ('.$_SESSION['user'].')');

	foreach ($_POST['id'] as $this_id)
	{
		$this_id = hesk_isNumber($this_id,$hesklang['id_not_valid']);

        $sql = 'SELECT `category` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($this_id).' LIMIT 1';
        $result = hesk_dbQuery($sql);
        $ticket = hesk_dbFetchAssoc($result);

        hesk_okCategory($ticket['category']);

		$sql = 'UPDATE `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` SET `status`=\'3\', `history`=CONCAT(`history`,\''.hesk_dbEscape($revision).'\') WHERE `id`='.hesk_dbEscape($this_id).' LIMIT 1';
		hesk_dbQuery($sql);
		$i++;
	}

    hesk_process_messages(sprintf($hesklang['num_tickets_closed'],$i),$referer,'SUCCESS');
}


/*** START FUNCTIONS ***/


function hesk_fullyDeleteTicket()
{
	global $hesk_settings, $hesklang, $ticket;

    /* Delete attachment files */
    $sql = 'SELECT * FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'attachments` WHERE `ticket_id`=\''.hesk_dbEscape($ticket['trackid']).'\'';
	$res = hesk_dbQuery($sql);
    if (hesk_dbNumRows($res))
    {
    	while ($file = hesk_dbFetchAssoc($res))
        {
        	$tmp = $hesk_settings['server_path'].'/attachments/'.$file['saved_name'];
            if (file_exists($tmp))
            {
            	@unlink($tmp);
            }
        }
    }

    /* Delete attachments info from the database */
	$sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'attachments` WHERE `ticket_id`=\''.hesk_dbEscape($ticket['trackid']).'\'';
	hesk_dbQuery($sql);

    /* Delete the ticket */
    $sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($ticket['id']).' LIMIT 1';
	hesk_dbQuery($sql);

    /* Delete replies to the ticket */
    $sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'replies` WHERE `replyto`='.hesk_dbEscape($ticket['id']);
	hesk_dbQuery($sql);

    /* Delete ticket notes */
    $sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'notes` WHERE `ticket`='.hesk_dbEscape($ticket['id']);
	hesk_dbQuery($sql);

    return true;
}
?>
