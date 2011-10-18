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

// includes
require_once('ZC_EncodeTestHarness.php');
require_once('panda.php');

/**
 * Panda Stream providerId
 */
define('ZC_PANDA_PROVIDER_ID', 'panda');

/**
 * this class defines the encode execution and administrative integration with
 * encoding providers. Each ZC_Provider instance must have a corresponding 
 * harness in order to enable testing of that provider
 * @author  Jason Read <jason@cloudharmony.com>
 */
class ZC_PandaTestHarness extends ZC_EncodeTestHarness {
	/**
	 * this function may be overriden in order to respond to http callbacks made
	 * by specific providers. Use the ZC_Util::getCallbackUrl method in order to 
	 * construct a callback URL that will invoke this method. The $args parameter
	 * is the merging of both $_POST and $_GET
	 * @param hash $args the callback arguments (a combination of the $_GET and 
	 * $_POST http variables)
	 * @return void
	 */
	public static function callback($args) {
		if (SRA_Controller::getAppParams('debug') == '1') {
			SRA_Error::logError('DEBUG: ZC_PandaTestHarness::callback');
			SRA_Error::logError($args);
		}
		if (isset($args['encoding_id'])) $testId = ZC_Util::getTestIdFromProviderJobId($args['encoding_id']);
		// found testId - update status
		if ($testId) {
			$panda =& ZC_PandaTestHarness::getPanda();
			$encoding = json_decode($panda->get('/encodings/' . $args['encoding_id'] . '.json'));
			if (SRA_Controller::getAppParams('debug') == '1') {
				SRA_Error::logError("DEBUG: ZC_PandaTestHarness::callback: Got encoding object for test $testId");
				SRA_Error::logError($encoding);
			}
			else if ($encoding->status == 'fail') SRA_Error::logError("ERROR: ZC_PandaTestHarness::callback - Encoding failed for testId $testId and encoding_id " . $args['encoding_id']);
			ZC_Util::updateTestStatus($testId, $encoding->status != 'fail' ? 'complete' : 'error');
			// delete encoding
			$panda->delete('/encodings/' . $args['encoding_id'] . '.json');
			// update transfer and encoding times
			if ($encoding->status != 'fail') {
				$created = strtotime($encoding->created_at);
				$encode = strtotime($encoding->started_encoding_at);
				$transferTime = $encode - $created;
				$encodeTime = $encoding->encoding_time;
				$db =& SRA_Controller::getAppDb();
				$originalTime = SRA_Database::getQueryValue($db, 'SELECT encode_time FROM zc_encoding_test WHERE test_id=' . $testId);
				$db->execute('UPDATE zc_encoding_test SET transfer_time=' . $transferTime . ', encode_time=' . $encodeTime . ' WHERE test_id=' . $testId);
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_PandaTestHarness::callback: updating transfer_time to $transferTime and encode_time to $encodeTime (original time was $originalTime) for test_id $testId");
			}
		}
		else SRA_Error::logError('ERROR: ZC_PandaTestHarness::callback - Unable to determine testId for jobId ' . $args['encoding_id']);
	}
	
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
	public static function encode($testId, $type, $batchId) {
		$result = FALSE;
		static $_pandaVideos;
		static $_pandaProfiles;
		if (!is_array($_pandaVideos)) $_pandaVideos = SRA_File::propertiesFileToArray(dirname(__FILE__) . '/videos.ini');
		if (!is_array($_pandaProfiles)) $_pandaProfiles = SRA_File::propertiesFileToArray(dirname(__FILE__) . '/profiles.ini');
		
		$panda =& ZC_PandaTestHarness::getPanda();
		if (is_object($response = json_decode($panda->post('/encodings.json', array('video_id' => $_pandaVideos[$type], 'profile_id' => $_pandaProfiles[$type])))) && 
			  $response->status == 'processing') {
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_PandaTestHarness::encode: successfully submitted encoding request for testId $testId; type $type and batchId $batchId. encoding_id=" . $response->id);
			ZC_Util::updateTestProviderJobId($testId, $response->id);
			ZC_Util::updateTestStatus($testId, 'encoding', TRUE);
			$result = TRUE;
		}
		else {
			SRA_Error::logError("ERROR: ZC_PandaTestHarness::encode: Unable to submit encoding request for testId $testId; type $type and batchId $batchId");
		}
		return $result;
	}
	
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
	public static function &getPanda() {
		static $_panda;
		if (!$_panda) {
			$_panda = new Panda(array('api_host' => SRA_Controller::getAppParams('api-host', ZC_PANDA_PROVIDER_ID),
																'cloud_id' => SRA_Controller::getAppParams('cloud', ZC_PANDA_PROVIDER_ID),
																'access_key' => SRA_Controller::getAppParams('key', ZC_PANDA_PROVIDER_ID),
																'secret_key' => SRA_Controller::getAppParams('secret', ZC_PANDA_PROVIDER_ID)));
		}
		return $_panda;
	}
}
?>