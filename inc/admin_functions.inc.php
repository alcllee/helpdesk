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

/*** FUNCTIONS ***/


function hesk_getHHMMSS($in)
{
	$in = hesk_getTime($in);
    return explode(':', $in);
} // END hesk_getHHMMSS();


function hesk_getTime($in)
{
	$in = trim($in);

	/* If everything is OK this simple check should return true */
    if ( preg_match('/^([0-9]{2,3}):([0-5][0-9]):([0-5][0-9])$/', $in) )
    {
    	return $in;
    }

	/* No joy, let's try to figure out the correct values to use... */
    $h = 0;
    $m = 0;
    $s = 0;

    /* How many parts do we have? */
    $parts = substr_count($in, ':');

    switch ($parts)
    {
    	/* Only two parts, let's assume minutes and seconds */
		case 1:
	        list($m, $s) = explode(':', $in);
	        break;

        /* Three parts, so explode to hours, minutes and seconds */
        case 2:
	        list($h, $m, $s) = explode(':', $in);
	        break;

        /* Something other was entered, let's assume just minutes */
        default:
	        $m = $in;
    }

	/* Make sure all inputs are integers */
	$h = intval($h);
    $m = intval($m);
    $s = intval($s);

	/* Convert seconds to minutes if 60 or more seconds */
    if ($s > 59)
    {
    	$m = floor($s / 60) + $m;
        $s = intval($s % 60);
    }

	/* Convert minutes to hours if 60 or more minutes */
    if ($m > 59)
    {
    	$h = floor($m / 60) + $h;
        $m = intval($m % 60);
    }

    /* MySQL accepts max time value of 838:59:59 */
    if ($h > 838)
    {
    	return '838:59:59';
    }    

	/* That's it, let's send out formatted time string */
    return str_pad($h, 2, "0", STR_PAD_LEFT) . ':' . str_pad($m, 2, "0", STR_PAD_LEFT) . ':' . str_pad($s, 2, "0", STR_PAD_LEFT);

} // END hesk_getTime();


function hesk_mergeTickets($merge_these, $merge_into)
{
	global $hesk_settings, $hesklang, $hesk_db_link;

    /* Target ticket must not be in the "merge these" list */
    if ( in_array($merge_into, $merge_these) )
    {
        $merge_these = array_diff($merge_these, array( $merge_into ) );
    }

    /* At least 1 ticket needs to be merged with target ticket */
    if ( count($merge_these) < 1 )
    {
    	$_SESSION['error'] = $hesklang['merr1'];
    	return false;
    }

    /* Make sure target ticket exists */
	$sql = 'SELECT `id`,`trackid`,`category` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($merge_into).' LIMIT 1';
	$res = hesk_dbQuery($sql);
	if (hesk_dbNumRows($res) != 1)
	{
    	$_SESSION['error'] = $hesklang['merr2'];
		return false;
	}
	$ticket = hesk_dbFetchAssoc($res);

	/* Make sure user has access to ticket category */
	if ( ! hesk_okCategory($ticket['category'], 0) )
	{
    	$_SESSION['error'] = $hesklang['merr3'];
		return false;
	}

    /* Set some variables for later */
    $merge['attachments'] = '';
	$merge['replies'] = array();
    $merge['notes'] = array();
    $sec_worked = 0;
    $history = '';
    $merged = '';

	/* Get messages, replies, notes and attachments of tickets that will be merged */
    foreach ($merge_these as $this_id)
    {
		/* Validate ID */
    	$this_id = hesk_isNumber($this_id, $hesklang['id_not_valid']);

        /* Get required ticket information */
        $sql = 'SELECT `id`,`trackid`,`category`,`name`,`message`,`dt`,`time_worked`,`attachments` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($this_id).' LIMIT 1';
        $res = hesk_dbQuery($sql);
		if (hesk_dbNumRows($res) != 1)
		{
			continue;
		}
        $row = hesk_dbFetchAssoc($res);

        /* Has this user access to the ticket category? */
        if ( ! hesk_okCategory($row['category'], 0) )
        {
        	continue;
        }

        /* Insert ticket message as a new reply to target ticket */
		$sql = "
		INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."replies` (
		`replyto`,`name`,`message`,`dt`,`attachments`
		)
		VALUES (
		'".hesk_dbEscape($ticket['id'])."',
		'".hesk_dbEscape($row['name'])."',
		'".hesk_dbEscape($row['message'])."',
		'".hesk_dbEscape($row['dt'])."',
		'".hesk_dbEscape($row['attachments'])."'
		)
		";
		hesk_dbQuery($sql);

		/* Update attachments  */
		$sql = 'UPDATE `'.hesk_dbEscape($hesk_settings['db_pfix']).'attachments` SET `ticket_id`=\''.hesk_dbEscape($ticket['trackid']).'\' WHERE `ticket_id`=\''.hesk_dbEscape($row['trackid']).'\'';
		hesk_dbQuery($sql);

        /* Get old ticket replies and insert them as new replies */
        $sql = 'SELECT * FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'replies` WHERE `replyto`='.hesk_dbEscape($row['id']);
        $res = hesk_dbQuery($sql);
        while ( $reply = hesk_dbFetchAssoc($res) )
        {
			$sql = "
			INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."replies` (
			`replyto`,`name`,`message`,`dt`,`attachments`,`staffid`,`rating`,`read`
			)
			VALUES (
			'".hesk_dbEscape($ticket['id'])."',
			'".hesk_dbEscape($reply['name'])."',
			'".hesk_dbEscape($reply['message'])."',
			'".hesk_dbEscape($reply['dt'])."',
			'".hesk_dbEscape($reply['attachments'])."',
            '".hesk_dbEscape($reply['staffid'])."',
            '".hesk_dbEscape($reply['rating'])."',
            '".hesk_dbEscape($reply['read'])."'
			)
			";
			hesk_dbQuery($sql);
        }

		/* Delete replies to the old ticket */
		$sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'replies` WHERE `replyto`='.hesk_dbEscape($row['id']);
		hesk_dbQuery($sql);

        /* Get old ticket notes and insert them as new notes */
        $sql = 'SELECT * FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'notes` WHERE `ticket`='.hesk_dbEscape($row['id']);
        $res = hesk_dbQuery($sql);
        while ( $note = hesk_dbFetchAssoc($res) )
        {
			$sql = "
			INSERT INTO `".hesk_dbEscape($hesk_settings['db_pfix'])."notes` (`ticket`,`who`,`dt`,`message`)
			VALUES (
			'".hesk_dbEscape($ticket['id'])."',
			'".hesk_dbEscape($note['who'])."',
			'".hesk_dbEscape($note['dt'])."',
			'".hesk_dbEscape($note['message'])."'
			)
			";
			hesk_dbQuery($sql);
        }

		/* Delete replies to the old ticket */
		$sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'notes` WHERE `ticket`='.hesk_dbEscape($row['id']);
		hesk_dbQuery($sql);

	    /* Delete old ticket */
	    $sql = 'DELETE FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'tickets` WHERE `id`='.hesk_dbEscape($row['id']).' LIMIT 1';
		hesk_dbQuery($sql);

		/* Log that ticket has been merged */
		$history .= sprintf($hesklang['thist13'],hesk_date(),$row['trackid'],$_SESSION['name'].' ('.$_SESSION['user'].')');

        /* Add old ticket ID to target ticket "merged" field */
        $merged .= '#' . $row['trackid'];

		/* Convert old ticket "time worked" to seconds and add to $sec_worked variable */
		list ($hr, $min, $sec) = explode(':', $row['time_worked']);
		$sec_worked += (((int)$hr) * 3600) + (((int)$min) * 60) + ((int)$sec);
    }

	/* Convert seconds to HHH:MM:SS */
	$sec_worked = hesk_getTime('0:'.$sec_worked);

    /* Update history (log) and merged IDs of target ticket */
	$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` SET `time_worked`=ADDTIME(`time_worked`, '".hesk_dbEscape($sec_worked)."'), `merged`=CONCAT(`merged`,'".hesk_dbEscape($merged . '#')."'), `history`=CONCAT(`history`,'".hesk_dbEscape($history)."') WHERE `id`='".hesk_dbEscape($merge_into)."' LIMIT 1";
	$res = hesk_dbQuery($sql);

    return true;

} // END hesk_mergeTickets()


function hesk_updateStaffDefaults()
{
	global $hesk_settings, $hesklang;

	// Demo mode
	if ( defined('HESK_DEMO') )
	{
		return true;
	}
	// Remove the part that forces saving as default - we don't need it every time
    $default_list = str_replace('&def=1','',$_SERVER['QUERY_STRING']);

    // Update database
	$sql = "UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."users` SET `default_list`='".hesk_dbEscape($default_list)."' WHERE `id` = '".hesk_dbEscape($_SESSION['id'])."' LIMIT 1";
	$res = hesk_dbQuery($sql);

    // Update session values so the changes take effect immediately
    $_SESSION['default_list'] = $default_list;

    return true;
    
} // END hesk_updateStaffDefaults()


function hesk_makeJsString($in)
{
	return addslashes(preg_replace("/\s+/",' ',$in));
} // END hesk_makeJsString()


function hesk_checkNewMail()
{
	global $hesk_settings, $hesklang;

	$sql = "SELECT COUNT(*) FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."mail` WHERE `to`=".hesk_dbEscape($_SESSION['id'])." AND `read`='0' AND `deletedby`!=".hesk_dbEscape($_SESSION['id']);
	$res = hesk_dbQuery($sql);
	$num = hesk_dbResult($res,0,0);

	return $num;
} // END hesk_checkNewMail()


function hesk_dateToString($dt,$returnName=1,$returnTime=0,$returnMonth=0)
{
	global $hesklang;

	list($y,$m,$n,$d,$G,$i,$s) = explode('-',date('Y-n-j-w-G-i-s',strtotime($dt)));

	$m = $hesklang['m'.$m];
	$d = $hesklang['d'.$d];

	if ($returnName)
	{
		return "$d, $m $n, $y";
	}

    if ($returnTime)
    {
    	return "$d, $m $n, $y $G:$i:$s";
    }

    if ($returnMonth)
    {
    	return "$m $y";
    }

	return "$m $n, $y";
} // End hesk_dateToString()


function hesk_getCategoriesArray($kb = 0) {
	global $hesk_settings, $hesklang, $hesk_db_link;

	$categories = array();
    if ($kb)
    {
    	$sql = 'SELECT `id`, `name` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'kb_categories` ORDER BY `cat_order` ASC';
    }
    else
    {
		$sql = 'SELECT `id`, `name` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'categories` ORDER BY `cat_order` ASC';
    }
	$result = hesk_dbQuery($sql);

	while ($row=hesk_dbFetchAssoc($result))
	{
		$categories[$row['id']] = $row['name'];
	}

    return $categories;
} // END hesk_getCategoriesArray()


function hesk_getHTML($in)
{
	global $hesk_settings, $hesklang;

	$replace_from = array("\t","<?","?>","$","<%","%>");
	$replace_to   = array("","&lt;?","?&gt;","\$","&lt;%","%&gt;");

	$in = trim($in);
	$in = str_replace($replace_from,$replace_to,$in);
	$in = preg_replace('/\<script(.*)\>(.*)\<\/script\>/Uis',"<script$1></script>",$in);
	$in = preg_replace('/\<\!\-\-(.*)\-\-\>/Uis',"<!-- comments have been removed -->",$in);

	if (HESK_SLASH === true)
	{
		$in = addslashes($in);
	}
    $in = str_replace('\"','"',$in);

	return $in;
} // END hesk_getHTML()


function hesk_autoLogin($noredirect=0)
{
	global $hesk_settings, $hesklang, $hesk_db_link;

	if (!$hesk_settings['autologin'])
    {
    	return false;
    }

    $user = isset($_COOKIE['hesk_username']) ? hesk_htmlspecialchars($_COOKIE['hesk_username']) : '';
    $hash = isset($_COOKIE['hesk_p']) ? hesk_htmlspecialchars($_COOKIE['hesk_p']) : '';
    define('HESK_USER', $user);

	if (empty($user) || empty($hash))
    {
    	return false;
    }

	/* Login cookies exist, now lets limit brute force attempts */
	hesk_limitBfAttempts();

	/* Check username */
	$sql = 'SELECT * FROM `'.$hesk_settings['db_pfix'].'users` WHERE `user` = \''.hesk_dbEscape($user).'\' LIMIT 1';
	$result = hesk_dbQuery($sql);
	if (hesk_dbNumRows($result) != 1)
	{
        setcookie('hesk_username', '');
        setcookie('hesk_p', '');
        header('Location: index.php?a=login&notice=1');
        exit();
	}

	$res=hesk_dbFetchAssoc($result);
	foreach ($res as $k=>$v)
	{
	    $_SESSION[$k]=$v;
	}

	/* Check password */
	if ($hash != hesk_Pass2Hash($_SESSION['pass'] . strtolower($user) . $_SESSION['pass']) )
    {
        setcookie('hesk_username', '');
        setcookie('hesk_p', '');
        header('Location: index.php?a=login&notice=1');
        exit();
	}

    /* Check if default password */
    if ($_SESSION['pass'] == '499d74967b28a841c98bb4baaabaad699ff3c079')
    {
    	hesk_process_messages($hesklang['chdp'],'NOREDIRECT','NOTICE');
    }

	unset($_SESSION['pass']);

	/* Login successful, clean brute force attempts */
	hesk_cleanBfAttempts();

	/* Regenerate session ID (security) */
	hesk_session_regenerate_id();

	/* Get allowed categories */
	if (empty($_SESSION['isadmin']))
	{
	    $_SESSION['categories']=explode(',',$_SESSION['categories']);
	}

	/* Renew cookies */
	setcookie('hesk_username', "$user", strtotime('+1 year'));
	setcookie('hesk_p', "$hash", strtotime('+1 year'));

    /* Close any old tickets here so Cron jobs aren't necessary */
	if ($hesk_settings['autoclose'])
    {
    	$revision = sprintf($hesklang['thist3'],hesk_date(),$hesklang['auto']);
    	$dt  = date('Y-m-d H:i:s',time() - $hesk_settings['autoclose']*86400);
		$sql = "UPDATE `".$hesk_settings['db_pfix']."tickets` SET `status`='3', `history`=CONCAT(`history`,'".hesk_dbEscape($revision)."') WHERE `status` = '2' AND `lastchange` <= '".hesk_dbEscape($dt)."' ";
		hesk_dbQuery($sql);
    }

	/* If session expired while a HESK page is open just continue using it, don't redirect */
    if ($noredirect)
    {
    	return true;
    }

	/* Redirect to the destination page */
	if ( hesk_isREQUEST('goto') && $url=hesk_REQUEST('goto') )
	{
	    $url = str_replace('&amp;','&',$url);
	    header('Location: '.$url);
	}
	else
	{
	    header('Location: admin_main.php');
	}
	exit();
} // END hesk_autoLogin()


function hesk_isLoggedIn()
{
	global $hesk_settings;

	$referer = hesk_input($_SERVER['REQUEST_URI']);
	$referer = str_replace('&amp;','&',$referer);

    if (empty($_SESSION['id']))
    {
    	if ($hesk_settings['autologin'] && hesk_autoLogin(1) )
        {
			// Users online
        	if ($hesk_settings['online'])
            {
            	require(HESK_PATH . 'inc/users_online.inc.php');
                hesk_initOnline($_SESSION['id']);
            }

        	return true;
        }

        if ( strpos($referer,'admin_reply_ticket.php') !== false)
        {
            $referer = 'admin_main.php';
        }

        $url = 'index.php?a=login&notice=1&goto='.urlencode($referer);
        header('Location: '.$url);
        exit();
    }
    else
    {
        hesk_session_regenerate_id();

        // Need to update permissions?
		if ( empty($_SESSION['isadmin']) )
		{
			$sql = "SELECT `isadmin`, `categories`, `heskprivileges` FROM `".$hesk_settings['db_pfix']."users` WHERE `id` = '".hesk_dbEscape($_SESSION['id'])."' LIMIT 1";
			$res = hesk_dbQuery($sql);
			if (hesk_dbNumRows($res) == 1)
			{
				$me = hesk_dbFetchAssoc($res);
				foreach ($me as $k => $v)
				{
					$_SESSION[$k]=$v;
				}

				// Get allowed categories
				if  (empty($_SESSION['isadmin']) )
				{
					$_SESSION['categories']=explode(',',$_SESSION['categories']);
				}
			}
            else
            {
				hesk_session_stop();
				$url = 'index.php?a=login&notice=1&goto='.urlencode($referer);
				header('Location: '.$url);
				exit();
            }
		}

		// Users online
		if ($hesk_settings['online'])
		{
			require(HESK_PATH . 'inc/users_online.inc.php');
            hesk_initOnline($_SESSION['id']);
		}

        return true;
    }

} // END hesk_isLoggedIn()


function hesk_Pass2Hash($plaintext) {
    $majorsalt  = '';
    $len = strlen($plaintext);
    for ($i=0;$i<$len;$i++)
    {
        $majorsalt .= sha1(substr($plaintext,$i,1));
    }
    $corehash = sha1($majorsalt);
    return $corehash;
} // END hesk_Pass2Hash()


function hesk_formatDate($dt)
{
    $dt=hesk_date($dt);
	$dt=str_replace(' ','<br />',$dt);
    return $dt;
} // End hesk_formatDate()


function hesk_jsString($str)
{
	$str  = str_replace( array('\'','<br />') , array('\\\'','') ,$str);
    $from = array("/\r\n|\n|\r/", '/\<a href="mailto\:([^"]*)"\>([^\<]*)\<\/a\>/i', '/\<a href="([^"]*)" target="_blank"\>([^\<]*)\<\/a\>/i');
    $to   = array("\\r\\n' + \r\n'", "$1", "$1");
    return preg_replace($from,$to,$str);
} // END hesk_jsString()


function hesk_myCategories($what='category') {

    if (!empty($_SESSION['isadmin']))
    {
        return '1';
    }
    else
    {
    	$mycat_sql = " `".hesk_dbEscape($what)."` IN (" . hesk_dbEscape( implode(',',$_SESSION['categories']) ) . ") ";
        return $mycat_sql;
    }

} // END hesk_myCategories()


function hesk_okCategory($cat,$error=1,$user_isadmin=false,$user_cat=false) {
	global $hesklang;

	/* Checking for current user or someone else? */
    if ($user_isadmin === false)
    {
		$user_isadmin = $_SESSION['isadmin'];
    }

    if ($user_cat === false)
    {
		$user_cat = $_SESSION['categories'];
    }

    /* Is admin? */
    if ($user_isadmin)
    {
        return true;
    }
    /* Staff with access? */
    elseif (in_array($cat,$user_cat))
    {
        return true;
    }
    /* No access */
    else
    {
        if ($error)
        {
        	hesk_error($hesklang['not_authorized_tickets']);
        }
        else
        {
        	return false;
        }
    }

} // END hesk_okCategory()


function hesk_checkPermission($feature,$showerror=1) {
	global $hesklang;

    /* Admins have full access to all features */
    if ($_SESSION['isadmin'])
    {
        return true;
    }

    /* Check other staff for permissions */
    if (strpos($_SESSION['heskprivileges'], $feature) === false)
    {
    	if ($showerror)
        {
        	hesk_error($hesklang['no_permission'].'<p>&nbsp;</p><p align="center"><a href="index.php">'.$hesklang['click_login'].'</a>');
        }
        else
        {
        	return false;
        }
    }
    else
    {
        return true;
    }

} // END hesk_checkPermission()
