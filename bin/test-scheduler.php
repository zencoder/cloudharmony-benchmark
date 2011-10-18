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
 * allow this script to run for 5 minutes
 */
define('ZC_TEST_SCHEDULER_TIMEOUT', 60*5);

/**
 * the maximum value allowed for concurrency
 */
define('ZC_TEST_SCHEDULER_MAX_CONCURRENCY', 10);

/**
 * used to simulate server load using non-synthetic benchmarks
 */
require_once('/var/www/sierra/lib/core/SRA_Controller.php');
SRA_Controller::init('zencoder');
require_once('ZC_EncodeTestHarness.php');

// get lock
if (!SRA_Util::semAcquire($skey = basename(__FILE__), ZC_TEST_SCHEDULER_TIMEOUT)) {
  $msg = basename(__FILE__) . ' - failed to run, unable to obtain lock';
  SRA_Error::logError($msg, __FILE__, __LINE__);
  echo $msg . "\n";
  exit;
}

try {
  ini_set('max_execution_time', ZC_TEST_SCHEDULER_TIMEOUT);
  echo "Loading test scheduler configuration\n";
	if (SRA_XmlParser::isValid($parser =& SRA_XmlParser::getXmlParser(SRA_Controller::getAppConfDir() . '/test-schedule.xml'))) {
		$db =& SRA_Controller::getAppDb();
		$error = "";
		$config =& $parser->getData();
		$testStarted = FALSE;
		
		foreach(array_keys($config['test-set']) as $key) {
			// retrieve and validate configuration
			$tset = $config['test-set'][$key];
			// frequency
			$frequency = isset($tset['attributes']['frequency']) ? $tset['attributes']['frequency'] : 'daily';
			if ($frequency != 'daily' && $frequency != 'weekly' && $frequency != 'monthly') $error = "Invalid frequency\n";

			// order
			$order = isset($tset['attributes']['order']) ? $tset['attributes']['order'] : 'sequential';
			if ($order != 'random' && $order != 'sequential') $error .= "Invalid order\n";

			// provider IDs
			$allProviders = ZC_Util::getAllProviderIds();
			$providers = isset($tset['attributes']['providers']) ? explode(',', $tset['attributes']['providers']) : $allProviders;
			if (in_array('all', $providers)) $providers = $allProviders;
			foreach($providers as $i => $provider) {
				if (!trim($provider)) {
					unset($providers[$i]);
					continue;
				}
				$providers[$i] = trim($provider);
				if (!in_array($providers[$i], $allProviders)) $error .= "Invalid providerId $provider";
			}

			// start offset
			$startOffset = isset($tset['attributes']['start-offset']) ? $tset['attributes']['start-offset']*1 : 0;
			if (!is_numeric($startOffset) || $startOffset < 0) $error .= "Invalid start-offset $startOffset";

			// tests
			$tests = array();
			if (isset($tset['test']) && is_array($tset['test'])) {
				foreach(array_keys($tset['test']) as $i) {
					// test type
					$type = $tset['test'][$i]['attributes']['type'];
					if (!$type || !ZC_Util::getTestFileUrl($type)) {
						$error .= "Invalid test type $type\n";
						continue;
					}
					// test concurrency
					$concurrency = isset($tset['test'][$i]['attributes']['concurrency']) ? $tset['test'][$i]['attributes']['concurrency']*1 : 1;
					if (!is_numeric($concurrency) || $concurrency < 1 || $concurrency > ZC_TEST_SCHEDULER_MAX_CONCURRENCY) {
						$error .= "Invalid concurrency $concurrency\n";
						continue;
					}
					$tests[] = array('type' => $type, 'concurrency' => $concurrency);
				}
			}
			if (!count($tests)) $error .= "No tests have been defined\n";

			if (!$error) {			
				// everything is good to go, start testing
				echo "Test schedule configuration for $key is valid, evaluating testing conditions using frequency=$frequency; order=$order; providers=" . implode(', ', $providers) . '; start-offset=' . $startOffset . ".\n";
				// test has already been started
				if ($testStarted) {
					echo "A test-set has already been started, this test set will be ignored\n";
					continue;
				}
				// randomize tests
				if ($order == 'random') shuffle($tests);
				$testScheduleIds = array();
				foreach($tests as $i => $test) $testScheduleIds[$key . ':' . $test['type'] . ':' . $test['concurrency']] = $i;

				// determine test start time for this interval
				$startTime = new SRA_GregorianDate();
				if ($frequency == 'daily') {
					$interval = SRA_GREGORIAN_DATE_UNIT_DAY;
					$startTime->jumpToStartOfDay();
				}
				else if ($frequency == 'weekly') {
					$interval = SRA_GREGORIAN_DATE_UNIT_WEEK;
					$startTime->jumpToStartOfWeek();
				}
				else if ($frequency == 'monthly') {
					$interval = SRA_GREGORIAN_DATE_UNIT_MONTH;
					$startTime->jumpToStartOfMonth();
				}
				// check for start offset			
				if ($startOffset) {
					$startThreshold = $startTime->copy();
					$startThreshold->jump($interval, -1);
					echo 'Checking for previous start time between ' . $startThreshold->format() . ' and ' . $startTime->format() . "\n";
					if ($previousStart = SRA_Database::getQueryValue($db, 'SELECT min(started) FROM zc_encoding_test WHERE test_schedule_id LIKE ' . $db->convertString($key . '%') . ' AND started >=' . $db->convertTime($startThreshold) . ' AND started < ' . $db->convertTime($startTime), SRA_DATA_TYPE_TIME)) {
						echo "Found previous start time " . $previousStart->format() . "\n";
						$startThreshold = $previousStart;
						$startThreshold->jump(SRA_GREGORIAN_DATE_UNIT_MINUTE, $startOffset);
					}
					else echo "There are no previous test iterations\n";
					$startThreshold->jump($interval, 1);
					echo "Start threshold is " . $startThreshold->format() . "\n";
					$startTime = $startThreshold->copy();
				}
				echo 'Current test interval starts at ' . $startTime->format() . "\n";

				// first set any hanging tests to timeout
				$timeout = new SRA_GregorianDate();
				$timeout->jump(SRA_GREGORIAN_DATE_UNIT_MINUTE, SRA_Controller::getAppParams('timeout')*-1);
				$results =& $db->execute('UPDATE zc_encoding_test SET status="timeout" WHERE (status="transfer" OR status="encoding") AND started < ' . $db->convertTime($timeout));
				if ($results->getNumRowsAffected()) echo $results->getNumRowsAffected() . " tests were set to timeout status because they were started prior to " . $timeout->format() . "\n";

				$now = new SRA_GregorianDate();
				if ($startTime && $startTime->compare($now) > 0) {
					echo "Start threshold has not been reached. Testing will not start\n";
				}
				else {
					// check for tests already completed within this interval and if any are still in progress

					// determine what tests have already been run and remove them from the list
					echo "Checking for tests that have already been run for this test interval\n";
					if (SRA_ResultSet::isValid($results =& $db->fetch('SELECT test_schedule_id, status FROM zc_encoding_test WHERE started >=' . $db->convertTime($startTime)))) {
						$numTests = count($tests);
						$running = 0;
						while($row =& $results->next()) {
							if ($row[1] == 'encoding' || $row[1] == 'transfer') $running++;
							if (isset($testScheduleIds[$row[0]])) {
								unset($tests[$testScheduleIds[$row[0]]]);
								unset($testScheduleIds[$row[0]]);
							}
						}
						$numPendingTests = count($tests);
						// there are no remaining tests to run during this interval
						if (!count($tests)) echo "All tests have been completed for this iteration\n";
						// some tests are still running
						else if ($running) echo "There are currently $numPendingTests of $numTests pending tests, but there are $running tests are still running, scheduler is exiting.\n";
						// there is still at least 1 test to run - run the first one in the list
						else {
							echo "Tests are sorted in the following order. The first test will be run\n";
							$type = NULL;
							$concurrency = NULL;
							foreach($tests as $i => $test) {
								if (!$type) {
									$type = $test['type'];
									$concurrency = $test['concurrency'];
								}
								echo '  Test #' . ($i+1) . ": Type=" . $test['type'] . ", Concurrency=" . $test['concurrency'] . "\n";
							}
							$testScheduleId = "$key:$type:$concurrency";
							echo "There are " . count($tests) . " of $numTests pending tests for this test interval. Executing test $type with concurrency $concurrency\n";
							shuffle($providers);
							foreach($providers as $provider) {
								echo "Starting test for $provider\n";
								ZC_EncodeTestHarness::test($provider, $type, $concurrency, $testScheduleId);
								$testStarted = TRUE;
							}
						}
					}
					else $error = "Unable to retrieve current tests\n";
				}
			}
		}
		if ($error) echo "ERROR: $error";
	}
	else {
		echo "ERROR: unable to load test scheduler configuration\n";
	}
}
catch (Exception $e) {
  $msg = basename(__FILE__) . ' error - exception: ' . $e->getMessage();
  SRA_Error::logError($msg, __FILE__, __LINE__);
}

SRA_Util::semRelease($skey);
?>
