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
 * this class defines the encode execution and administrative integration with
 * encoding providers. Each ZC_Provider instance must have a corresponding 
 * harness in order to enable testing of that provider
 * @author  Jason Read <jason@cloudharmony.com>
 */
abstract class ZC_EncodeTestHarness {
	/**
	 * starts the test identified by $type for the provider identified by 
	 * $providerId. returns the number of tests successfully started (0 if no 
	 * tests were started)
	 * @param string $providerId the provider ID
	 * @param string $type the test type
	 * @param int $concurrency the amount of concurrency to test (# of parallel
	 * requests to launch)
	 * @param string $testScheduleId test schedule identifier
	 * @return int
	 */
	public static final function test($providerId, $type, $concurrency=1, $testScheduleId=NULL) {
		$result = 0;
		$dao =& SRA_DaoFactory::getDao('ZC_Provider');
		if (ZC_Provider::isValid($provider =& $dao->findByPk($providerId))) {
			$dao =& SRA_DaoFactory::getDao('ZC_EncodingTest');
			$batchId = $concurrency > 1 ? rand() : NULL;
			for($i=0; $i<$concurrency; $i++) {
				$test = new ZC_EncodingTest();
				$test->setProviderId($providerId);
				$test->setBatchId($batchId);
				$test->setType($type);
				if ($testScheduleId) $test->setTestScheduleId($testScheduleId);
				if ($dao->insert($test)) {
					$args = array($test->getTestId(), $type, $batchId);
					if (SRA_Util::invokeStaticMethodPath($provider->getHarness() . '::encode', $args)) $result++;
					// error - unable to submit encode job
					else ZC_Util::updateTestStatus($test->getTestId(), 'error');
				}
			}
		}
		return $result;
	}
	
	/**
	 * this function may be overriden in order to respond to http callbacks made
	 * by specific providers. Use the ZC_Util::getCallbackUrl method in order to 
	 * construct a callback URL that will invoke this method. The $args parameter
	 * is the merging of both $_POST and $_GET
	 * @param hash $args the callback arguments (a combination of the $_GET and 
	 * $_POST http variables)
	 * @return void
	 */
	public static function callback($args) {}
		
  /**
   * this function must be overriden. it should instruct the encoding service 
   * to encode the video identified by $type. should return TRUE on success
   * FALSE on failure
   * @param int $testId the testId that this encoding job pertains to
   * @param string $type the video type to encode
   * @param int $batchId optional batch identifier for this encoding test (if 
   * the encoding job is part of a larger batch)
   * @return boolean
   */
	public static abstract function encode($testId, $type, $batchId);
	
}
?>