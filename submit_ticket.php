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

// Get all the required files and functions
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
require(HESK_PATH . 'inc/database.inc.php');
require(HESK_PATH . 'inc/email_functions.inc.php');
require(HESK_PATH . 'inc/posting_functions.inc.php');

// We only allow POST requests to this file
if ( ! isset($_POST['name']) )
{
	header('Location: index.php?a=add');
	exit();
}

// Befor anything else block obvious spammers trying to inject email headers
$pattern = "/\n|\r|\t|%0A|%0D|%08|%09/";
if (preg_match($pattern, $_POST['name']) || preg_match($pattern, $_POST['subject']))
{
	header('HTTP/1.1 403 Forbidden');
    exit();
}

hesk_session_start();

// A security check - not needed here, but uncomment if you require it
# hesk_token_check($_POST['token']);

// Prevent submitting multiple tickets by reloading submit_ticket.php page
if (isset($_SESSION['already_submitted']))
{
	hesk_forceStop();
}

// Connect to database
hesk_dbConnect();

$hesk_error_buffer = array();

if ($hesk_settings['question_use'])
{
	$question = hesk_input($_POST['question']);
	if (empty($question))
	{
		$hesk_error_buffer['question'] = $hesklang['q_miss'];
	}
	elseif (strtolower($question) != strtolower($hesk_settings['question_ans']))
	{
		$hesk_error_buffer['question'] = $hesklang['q_wrng'];
	}
	else
	{
		$_SESSION['c_question'] = $question;
	}
}

if ($hesk_settings['secimg_use'] && ! isset($_SESSION['img_verified']))
{
	$mysecnum = hesk_isNumber($_POST['mysecnum']);
	if (empty($mysecnum))
	{
		$hesk_error_buffer['mysecnum']=$hesklang['sec_miss'];
	}
	else
	{
		require(HESK_PATH . 'inc/secimg.inc.php');
		$sc = new PJ_SecurityImage($hesk_settings['secimg_sum']);
		if ($sc->checkCode($mysecnum,$_SESSION['checksum']))
		{
        	$_SESSION['img_verified']=true;
		}
        else
        {
			$hesk_error_buffer['mysecnum']=$hesklang['sec_wrng'];
        }
	}
}

$tmpvar['name']	 = hesk_input($_POST['name']) or $hesk_error_buffer['name']=$hesklang['enter_your_name'];
$tmpvar['email'] = hesk_validateEmail($_POST['email'],'ERR',0) or $hesk_error_buffer['email']=$hesklang['enter_valid_email'];

if ($hesk_settings['confirm_email'])
{
	if ( ! isset($_POST['email2']) )
    {
    	$_POST['email2'] = '';
    }
	$tmpvar['email2'] = hesk_input($_POST['email2']) or $hesk_error_buffer['email2']=$hesklang['confemail2'];
	if (strlen($tmpvar['email2']) && ( strtolower($tmpvar['email']) != strtolower($tmpvar['email2']) ))
	{
	    $tmpvar['email2'] = '';
	    $_POST['email2'] = '';
        $_SESSION['c_email2'] = '';
        $_SESSION['isnotice'][] = 'email';
	    $hesk_error_buffer['email2']=$hesklang['confemaile'];
	}
	else
	{
		$_SESSION['c_email2'] = $_POST['email2'];
	}
}

$tmpvar['category'] = hesk_input($_POST['category']) or $hesk_error_buffer['category']=$hesklang['sel_app_cat'];
$tmpvar['priority'] = ($hesk_settings['cust_urgency'] ? intval($_POST['priority']) : 3) or $hesk_error_buffer['priority']=$hesklang['sel_app_priority'];
$tmpvar['subject']  = hesk_input($_POST['subject']) or $hesk_error_buffer['subject']=$hesklang['enter_ticket_subject'];
$tmpvar['message']  = hesk_input($_POST['message']) or $hesk_error_buffer['message']=$hesklang['enter_message'];

// Is category a valid choice?
$tmpvar['category'] = intval($tmpvar['category']);
if ($tmpvar['category'])
{
	$sql = "SELECT `autoassign` FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."categories` WHERE `id`=" . hesk_dbEscape($tmpvar['category']) . " AND `type`='0' LIMIT 1";
	$res = hesk_dbQuery($sql);

	if ( hesk_dbNumRows($res) < 1 )
	{
    	// Category either doesn't exist or is not public
		$hesk_error_buffer['category'] = $hesklang['sel_app_cat'];
	}
	else
    {
    	$row = hesk_dbResult($res);

        // Is auto-assign of tickets disabled in this category?
    	if ( ! $row )
		{
			$hesk_settings['autoassign'] = false;
        }
	}
}


// Custom fields
foreach ($hesk_settings['custom_fields'] as $k=>$v)
{
	if ($v['use'])
    {
        if ($v['type'] == 'checkbox')
        {
			$tmpvar[$k]='';

        	if (isset($_POST[$k]))
            {
				if (is_array($_POST[$k]))
				{
					foreach ($_POST[$k] as $myCB)
					{
						$tmpvar[$k].=hesk_input($myCB).'<br />';
					}
					$tmpvar[$k]=substr($tmpvar[$k],0,-6);
				}
            }
            else
            {
            	if ($v['req'])
                {
					$hesk_error_buffer[$k]=$hesklang['fill_all'].': '.$v['name'];
                }
            	$_POST[$k] = '';
            }
        }
		elseif ($v['req'])
        {
        	$tmpvar[$k]=hesk_makeURL(nl2br(hesk_input($_POST[$k])));
            if (!strlen($tmpvar[$k]))
            {
            	$hesk_error_buffer[$k]=$hesklang['fill_all'].': '.$v['name'];
            }
        }
		else
        {
        	$tmpvar[$k]=hesk_makeURL(nl2br(hesk_input($_POST[$k])));
        }
		$_SESSION["c_$k"]=$_POST[$k];
	}
    else
    {
    	$tmpvar[$k] = '';
    }
}

// Check maximum open tickets limit
$below_limit = true;
if ($hesk_settings['max_open'])
{
	$sql = "SELECT COUNT(*) FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `status` != '3' AND " . hesk_dbFormatEmail($tmpvar['email']);
	$res = hesk_dbQuery($sql);
	$num = hesk_dbResult($res);

	if ($num >= $hesk_settings['max_open'])
    {
    	$hesk_error_buffer = array( 'max_open' => sprintf($hesklang['maxopen'], $num, $hesk_settings['max_open']) );
        $below_limit = false;
    }
}

// If we reached max tickets let's save some resources
if ($below_limit)
{
	// Generate tracking ID
	$tmpvar['trackid'] = hesk_createID();

	// Attachments
	if ($hesk_settings['attachments']['use'])
	{
	    require_once(HESK_PATH . 'inc/attachments.inc.php');

	    $attachments = array();
        $trackingID  = $tmpvar['trackid'];

	    for ($i = 1; $i <= $hesk_settings['attachments']['max_number']; $i++)
	    {
	        $att = hesk_uploadFile($i);
	        if ($att !== false && ! empty($att) )
	        {
	            $attachments[$i] = $att;
	        }
	    }
	}
	$tmpvar['attachments'] = '';
}

// If we have any errors lets store info in session to avoid re-typing everything
if (count($hesk_error_buffer))
{
	$_SESSION['iserror'] = array_keys($hesk_error_buffer);

    $_SESSION['c_name']     = $_POST['name'];
    $_SESSION['c_email']    = $_POST['email'];
    $_SESSION['c_category'] = $_POST['category'];
    $_SESSION['c_priority'] = isset($_POST['priority']) ? $_POST['priority'] : '';
    $_SESSION['c_subject']  = $_POST['subject'];
    $_SESSION['c_message']  = $_POST['message'];

    $tmp = '';
    foreach ($hesk_error_buffer as $error)
    {
        $tmp .= "<li>$error</li>\n";
    }

    $hesk_error_buffer = $hesklang['pcer'] . '<br /><br /><ul>' . $tmp . '</ul>';
    hesk_process_messages($hesk_error_buffer, 'index.php?a=add');
}

$tmpvar['message']=hesk_makeURL($tmpvar['message']);
$tmpvar['message']=nl2br($tmpvar['message']);

// All good now, continue with ticket creation
$tmpvar['owner']   = 0;
$tmpvar['history'] = sprintf($hesklang['thist15'], hesk_date(), $tmpvar['name']);

// Auto assign tickets if aplicable
$autoassign_owner = hesk_autoAssignTicket($tmpvar['category']);
if ($autoassign_owner)
{
	$tmpvar['owner']    = $autoassign_owner['id'];
    $tmpvar['history'] .= sprintf($hesklang['thist10'], hesk_date(), $autoassign_owner['name'].' ('.$autoassign_owner['user'].')');
}

// Insert attachments
if ($hesk_settings['attachments']['use'] && ! empty($attachments) )
{
    foreach ($attachments as $myatt)
    {
        $sql = "INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."attachments` (`ticket_id`,`saved_name`,`real_name`,`size`) VALUES ('".hesk_dbEscape($tmpvar['trackid'])."', '".hesk_dbEscape($myatt['saved_name'])."', '".hesk_dbEscape($myatt['real_name'])."', '".hesk_dbEscape($myatt['size'])."')";
        $res = hesk_dbQuery($sql);
        $tmpvar['attachments'] .= hesk_dbInsertID() . '#' . $myatt['real_name'] .',';
    }
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

// Next ticket show suggested articles again
$_SESSION['ARTICLES_SUGGESTED']=false;
$_SESSION['already_submitted']=1;

// Need email to view ticket? If yes, remember it by default
if ($hesk_settings['email_view_ticket'])
{
	setcookie('hesk_myemail', $tmpvar['email'], strtotime('+1 year'));
}

// Unset temporary variables
unset($tmpvar);
hesk_cleanSessionVars('tmpvar');
hesk_cleanSessionVars('c_category');
hesk_cleanSessionVars('c_priority');
hesk_cleanSessionVars('c_subject');
hesk_cleanSessionVars('c_message');
hesk_cleanSessionVars('c_question');
hesk_cleanSessionVars('img_verified');

// Print header
require_once(HESK_PATH . 'inc/header.inc.php');

?>
<table width="100%" border="0" cellspacing="0" cellpadding="0">
<tr>
<td width="3"><img src="img/headerleftsm.jpg" width="3" height="25" alt="" /></td>
<td class="headersm"><?php hesk_showTopBar($hesklang['ticket_submitted']); ?></td>
<td width="3"><img src="img/headerrightsm.jpg" width="3" height="25" alt="" /></td>
</tr>
</table>

<table width="100%" border="0" cellspacing="0" cellpadding="3">
<tr>
<td><span class="smaller"><a href="<?php echo $hesk_settings['site_url']; ?>" class="smaller"><?php echo $hesk_settings['site_title']; ?></a> &gt;
<a href="<?php echo $hesk_settings['hesk_url']; ?>" class="smaller"><?php echo $hesk_settings['hesk_title']; ?></a>
&gt; <?php echo $hesklang['ticket_submitted']; ?></span></td>
</tr>
</table>

</td>
</tr>
<tr>
<td>

<p>&nbsp;</p>

<?php
// Show success message with link to ticket
hesk_show_success(

	$hesklang['ticket_submitted'] . '<br /><br />' .
    $hesklang['ticket_submitted_success'] . ': <b>' . $ticket['trackid'] . '</b><br /><br />
	<a href="' . $hesk_settings['hesk_url'] . '/ticket.php?track=' . $ticket['trackid'] . '">' . $hesklang['view_your_ticket'] . '</a>'

);

// Any other messages to display?
hesk_handle_messages();
?>

<p>&nbsp;</p>

<?php
require_once(HESK_PATH . 'inc/footer.inc.php');
exit();


function hesk_forceStop()
{
	global $hesklang;
	?>
	<html>
	<head>
	<meta http-equiv="Refresh" content="0; url=index.php?a=add" />
	</head>
	<body>
	<p><a href="index.php?a=add"><?php echo $hesklang['c2c']; ?></a>.</p>
	</body>
	</html>
	<?php
    exit();
} // END hesk_forceStop()
?>
