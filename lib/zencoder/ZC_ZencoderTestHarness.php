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
require_once('Zencoder.php');

/**
 * Zencoder providerId
 */
define('ZC_ZENCODER_PROVIDER_ID', 'zencoder');

/**
 * this class defines the encode execution and administrative integration with
 * encoding providers. Each ZC_Provider instance must have a corresponding 
 * harness in order to enable testing of that provider
 * @author  Jason Read <jason@cloudharmony.com>
 */
class ZC_ZencoderTestHarness extends ZC_EncodeTestHarness {
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
		$notification = ZencoderOutputNotification::catch_and_parse();
		if (SRA_Controller::getAppParams('debug') == '1') {
			SRA_Error::logError('DEBUG: ZC_ZencoderTestHarness::callback');
			SRA_Error::logError($notification);
		}
		if ($notification->job->id) $testId = ZC_Util::getTestIdFromProviderJobId($notification->job->id);
		// found testId - update status
		if ($testId) {
			ZC_Util::updateTestStatus($testId, $notification->output->state == 'finished' ? 'complete' : 'error');
			if (is_object($job = ZC_ZencoderTestHarness::invokeApi('jobs', $notification->job->id)) && isset($job->job->created_at) && 
					isset($job->job->input_media_file->finished_at) && isset($job->job->output_media_files[0]->finished_at)) {
				if (SRA_Controller::getAppParams('debug') == '1') {
					SRA_Error::logError('DEBUG: ZC_ZencoderTestHarness::callback: Success retrieved job details for testId ' . $testId . ' and zencoder job ' . $notification->job->id . '. Calculating transfer and encode times.');
					SRA_Error::logError($job->job);
				}
				$timeCreated = new SRA_GregorianDate($job->job->created_at);
				$timeStarted = new SRA_GregorianDate($job->job->input_media_file->finished_at);
				$timeFinished = new SRA_GregorianDate($job->job->output_media_files[0]->finished_at);
				$transferTime = $timeStarted->getUnixTimestamp() - $timeCreated->getUnixTimestamp();
				$encodeTime = $timeFinished->getUnixTimestamp() - $timeStarted->getUnixTimestamp();
				$db =& SRA_Controller::getAppDb();
				$originalTime = SRA_Database::getQueryValue($db, 'SELECT encode_time FROM zc_encoding_test WHERE test_id=' . $testId);
				$db->execute('UPDATE zc_encoding_test SET transfer_time=' . $transferTime . ', encode_time=' . $encodeTime . ' WHERE test_id=' . $testId);
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_ZencoderTestHarness::callback: updating transfer_time to $transferTime and encode_time to $encodeTime (original time was $originalTime) for test_id $testId");
			}
			else {
				SRA_Error::logError('ERROR: ZC_ZencoderTestHarness::callback - Unable to retrieve complete job details for ' . $notification->job->id . ' will use calculated encoding time');
				if ($job) SRA_Error::logError($job);
			}
		}
		else SRA_Error::logError('ERROR: ZC_ZencoderTestHarness::callback - Unable to determine testId for jobId ' . $notification->job->id);
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
		$audioOnly = ZC_Util::getTestBitrateVideo($type) ? FALSE : TRUE;
		$output = array(
      'format' => 'mp4',
      'audio_codec' => 'aac',
      'audio_bitrate' => ZC_Util::getTestBitrateAudio($type),
      'notifications' => array(ZC_Util::getCallbackUrl(ZC_ZENCODER_PROVIDER_ID))
    );
		// audio only
		if ($audioOnly) $output['skip_video'] = '1';
		// audio and video
		else {
			$output['video_bitrate'] = ZC_Util::getTestBitrateVideo($type);
			$output['video_codec'] = 'h264';
		}
		$args = array(
		  'api_key' => SRA_Controller::getAppParams('key', ZC_ZENCODER_PROVIDER_ID),
		  'input' => ZC_Util::getTestFileUrl($type),
			'private' => 'true',
		  'outputs' => array($output)
		);
		if (SRA_Controller::getAppParams('debug') == '1') {
			SRA_Error::logError('DEBUG: ZC_ZencoderTestHarness::encode - invoking API using arguments:');
			SRA_Error::logError($args);
		}
		$job = new ZencoderJob($args);
		if ($job->created) {
			ZC_Util::updateTestProviderJobId($testId, $job->id);
			ZC_Util::updateTestStatus($testId, 'encoding', TRUE);
			$result = TRUE;
		}
		else if ($job->errors) {
			SRA_Error::logError('ERROR: ZC_ZencoderTestHarness::encode - unable to invoke API due to errors');
			SRA_Error::logError($job->errors);
		}
		return $result;
	}
	
	/**
	 * invokes the zencoder API for the arguments specified and returns the JSON
	 * object that results from the call. Returns NULL on failure
	 * @param string $action the action to invoke (URI)
	 * @param string $id the id of the object that the action pertains to (sub-URI)
	 * @param hash $params optional request parameters
	 * @param int $requestType the request type - get, post, delete, put
	 * @param boolean $debug if TRUE, the return value will be a curl command line
	 * string that may be used to execute this API call. If $debug == 2, the curl 
	 * command will be executed and the results returned (json decoded)
	 * @return mixed
	 */
	public static function invokeApi($action, $id=NULL, $params=NULL, $requestType='get', $debug=FALSE) {
		if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_ZencoderTestHarness::invokeApi - called for action=$action; id=$id; requestType=$requestType");
		$results = NULL;
		if (!is_array($params)) $params = array();
		$params['api_key'] = SRA_Controller::getAppParams('key', ZC_ZENCODER_PROVIDER_ID);
		$pstring = '';
		if (is_array($params)) {
			foreach($params as $key => $val) {
				$pstring .= $pstring ? '&' : '';
				$pstring .= urlencode($key) . '=' . urlencode($val);
			}
		}
		$url = SRA_Controller::getAppParams('url', ZC_ZENCODER_PROVIDER_ID) . $action . ($id ? '/' . $id : '') . '.json' . ($requestType != 'post' ? '?' . $pstring : '');
		
		// return curl debug
		if ($debug) {
			$results = "curl -s $url -X $requestType";
			if ($requestType == 'post') {
				foreach($params as $key => $val) $results .= " -d '$key=$val'";
			}
			// execute curl command
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_ZencoderTestHarness::invokeApi - debug mode $debug: will return curl command string: $results");
			if ($debug === 2) {
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_ZencoderTestHarness::invokeApi - executing curl command string");
				$results = json_decode(shell_exec($results));
			}
		}
		// make actual api call using curl library
		else {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			if ($requestType == 'post') {
				curl_setopt($ch, CURLOPT_POST, TRUE);
				if ($pstring) curl_setopt($ch, CURLOPT_POSTFIELDS, $pstring);
			}
			else if ($requestType == 'delete') {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
			}
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			if ($response = curl_exec($ch)) {
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_ZencoderTestHarness::invokeApi - successfully invoked API URL $url with params $pstring - RESPONSE: $response");
				$results = json_decode($response);
			}
			else SRA_Error::logError("ERROR: ZC_ZencoderTestHarness::invokeApi - Unable to invoke API URL $url with params $pstring");
		}
		
		return $results;
	}
}
?>