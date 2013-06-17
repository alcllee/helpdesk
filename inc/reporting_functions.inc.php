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

function dateweek($weeknumber,$business=0)
{
	$x = strtotime("last Monday");
	$Year = date("Y",$x);
	$Month = date("m",$x);
	$Day = date("d",$x);

	if ($Month < 2 && $Day < 8)
    {
		$Year = $Year--;
		$Month = $Month--;
	}

	if ($Month > 1 && $Day < 8)
    {
		$Month = $Month--;
	}
	//DATE BEGINN OF THE WEEK ( Monday )
	$Day = $Day+7*$weeknumber;
	$dt[0]=date('Y-m-d', mktime(0, 0, 0, $Month, $Day, $Year));

	if ($business)
    {
		//DATE END OF BUSINESS WEEK ( Friday )
		$Day = $Day+4;
		$dt[1]=date('Y-m-d', mktime(0, 0, 0, $Month, $Day, $Year));
	}
    else
    {
		//DATE END OF THE WEEK ( Sunday )
		$Day = $Day+6;
		$dt[1]=date('Y-m-d', mktime(0, 0, 0, $Month, $Day, $Year));
	}

	return $dt;
} // END dateweek()


function DateArray($s,$e)
{
	$start = strtotime($s);
	$end = strtotime($e);
	$da = array();
	for ($n=$start;$n <= $end;$n += 86400)
    {
		$da[] = date('Y-m-d',$n);
	}
	return $da;
} // END DateArray()


function MonthsArray($s,$e)
{
	$start = date('Y-m-01', strtotime($s));
	$end = date('Y-m-01', strtotime($e));
    $mt = array();
	while ($start <= $end)
	{
		$mt[] = $start;
		$start = date('Y-m-01',strtotime("+1 month", strtotime($start)));
	}
    return $mt;
} // END MonthsArray()


function hesk_getOldestDate()
{
	global $hesk_settings, $hesklang, $date_from, $date_to;

	$sql = "SELECT `dt` FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` ORDER BY `dt` ASC LIMIT 1";
	$res = hesk_dbQuery($sql);

    if (hesk_dbNumRows($res) == 1)
    {
		$row = hesk_dbFetchAssoc($res);
        return date('Y-m-d', strtotime($row['dt']) );
    }
    else
    {
    	return date('Y-m-d');
    }

} // END hesk_getOldestDate()
