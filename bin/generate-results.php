#!/usr/bin/php -q
<?php
/***************************************************************************

  Copyright (C) 2009 Unpublished Work of CloudHarmony
  All rights reserved.

  THIS WORK IS A COPYRIGHT WORK AND CONTAINS CONFIDENTIAL, PROPRIETARY AND TRADE 
  SECRET INFORMATION OF CLOUDHARMONY ACCESS TO THIS WORK IS RESTRICTED TO (i) 
  CLOUDHARMONY EMPLOYEES WHO HAVE A NEED TO KNOW TO PERFORM TASKS WITHIN THE SCOPE 
  OF THEIR ASSIGNMENTS AND (ii) ENTITIES OTHER THAN CLOUDHARMONY WHO HAVE ACCEPTED 
  THE CLOUDHARMONY SOURCE LICENSE OR OTHER CLOUDHARMONY LICENSE AGREEMENTS. EXCEPT 
  UNDER THE EXPRESS TERMS OF THE CLOUDHARMONY LICENSE AGREEMENT NO PART OF THIS 
  WORK MAY BE USED, PRACTICED, PERFORMED, COPIED, DISTRIBUTED, REVISED, 
  MODIFIED, TRANSLATED, ABRIDGED, CONDENSED, EXPANDED, COLLECTED, LINKED, 
  RECAST, TRANSFORMED, OR ADAPTED WITHOUT THE PRIOR WRITTEN CONSENT OF 
  CLOUDHARMONY INC. ANY USE OR EXPLOITATION OF THIS WORK WITHOUT AUTHORIZATION 
  COULD SUBJECT THE PERPETRATOR TO CRIMINAL AND CIVIL LIABILITY.

  http://www.cloudharmony.com/

***************************************************************************/

/**
 * generates result tables. accepts one argument, the start time to use in 
 * filtering the results
 */
require_once('/var/www/sierra/lib/core/SRA_Controller.php');
SRA_Controller::init('zencoder');
$db =& SRA_Controller::getAppDb();
if (isset($argv[1])) $start = new SRA_GregorianDate($argv[1]);

foreach(array(FALSE, TRUE) as $batch) {
	echo ($batch ? 'BATCH' : 'SINGLE REQUEST') . " RESULTS TABLE\n";
	echo "Provider	Test	Avg Encode Time (secs)	Standard Deviation %	Median Encode (secs)	90% Percentile Encode (secs)	Avg Transfer Time (secs)	Standard Deviation %	Total Time (secs)	Num Tests	Num Errors	Error %\n";
	$where = "where status='complete' and batch_id is " . ($batch ? 'not' : '') . ' null' . ($start ? ' and started>' . $db->convertDate($start) : '');
	$q1 = "select provider_id, type, round(avg(encode_time), 2) as avg_encode_time, 100*(round(stddev(encode_time)/avg(encode_time), 2)), round(avg(transfer_time), 2), 100*(round(stddev(transfer_time)/avg(transfer_time), 2)), count(*) from zc_encoding_test $where group by provider_id, type order by type, avg_encode_time";
	$r1 =& $db->fetch($q1);
	while($row =& $r1->next()) {
		echo "$row[0]\t$row[1]\t$row[2]\t$row[3]\t";
		$q2 = "SELECT encode_time FROM zc_encoding_test $where and provider_id='$row[0]' and type='$row[1]' order by encode_time asc";
		$times = array();
		$r2 =& $db->fetch($q2);
		while($r =& $r2->next()) $times[] = $r[0];
		$nmedians = count($times);
		$nmedians2 = floor($nmedians/2);
    $median = $nmedians % 2 ? $times[$nmedians2] : round(($times[$nmedians2 - 1] + $times[$nmedians2])/2, 2);
		$nineperc = $times[(round($nmedians/10)*9)-1];
		$totalTime = $row[2] + $row[4];
		echo "$median\t$nineperc\t$row[4]\t$row[5]\t$totalTime\t$row[6]\t";
		$errors = SRA_Database::getQueryValue($db, "SELECT count(*) FROM zc_encoding_test " . str_replace('complete', 'error', $where) . " and provider_id='$row[0]' and type='$row[1]'");
		$errorPerc = round(100*($errors/$row[6]), 2);
		echo "$errors\t$errorPerc\n";
	}
	echo "\n\n";
}
?>
