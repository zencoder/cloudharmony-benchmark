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

/**
 * Sorenson Media providerId
 */
define('ZC_SORENSON_PROVIDER_ID', 'sorenson');

/**
 * identifies a GET request
 */
define('ZC_SORENSON_REQUEST_TYPE_GET', 'get');

/**
 * identifies a POST request
 */
define('ZC_SORENSON_REQUEST_TYPE_POST', 'post');

/**
 * identifies a DELETE request
 */
define('ZC_SORENSON_REQUEST_TYPE_DELETE', 'delete');

/**
 * identifies a PUT request
 */
define('ZC_SORENSON_REQUEST_TYPE_PUT', 'put');

/**
 * this class defines the encode execution and administrative integration with
 * encoding providers. Each ZC_Provider instance must have a corresponding 
 * harness in order to enable testing of that provider
 * @author  Jason Read <jason@cloudharmony.com>
 */
class ZC_SorensonTestHarness extends ZC_EncodeTestHarness {
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
			SRA_Error::logError('DEBUG: ZC_SorensonTestHarness::callback');
			SRA_Error::logError($args);
		}
		$args = $args['params'];
		if (isset($args['callback_data'])) {
			$testId = $args['callback_data'];
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::callback: encoding finished for testId " . $testId);
			ZC_Util::updateTestStatus($args['callback_data'], $args['transcode_job_status'] == 5 ? 'complete' : 'error');
			ZC_SorensonTestHarness::invokeApi('assets', $args['output_asset_id'], NULL, ZC_SORENSON_REQUEST_TYPE_DELETE);
			// use job timestamps to apply correct transfer and encoding times
			if ($args['transcode_job_status'] == 5 && ($job = ZC_SorensonTestHarness::invokeApi('transcode_jobs', $args['transcode_job_id'])) && $job->time_submitted) {
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError($job);
				$timeCreated = new SRA_GregorianDate($job->time_submitted);
				$timeStarted = new SRA_GregorianDate($job->time_started);
				$timeFinished = new SRA_GregorianDate($job->time_finished);
				$transferTime = $timeStarted->getUnixTimestamp() - $timeCreated->getUnixTimestamp();
				$encodeTime = $timeFinished->getUnixTimestamp() - $timeStarted->getUnixTimestamp();
				$db =& SRA_Controller::getAppDb();
				$originalTime = SRA_Database::getQueryValue($db, 'SELECT encode_time FROM zc_encoding_test WHERE test_id=' . $testId);
				$db->execute('UPDATE zc_encoding_test SET transfer_time=' . $transferTime . ', encode_time=' . $encodeTime . ' WHERE test_id=' . $testId);
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::callback: updating transfer_time to $transferTime and encode_time to $encodeTime (original time was $originalTime) for test_id $testId");
			}
		}
		else SRA_Error::logError('ERROR: ZC_SorensonTestHarness::callback: unable to process sorenson callback parameters');
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
		// audio only encoding does not work with Sorenson
		//if ($type == '3m_audio') return FALSE;
		
		$result = FALSE;
		static $_sorensonAssets;
		static $_sorensonPresets;
		if (!is_array($_sorensonAssets)) $_sorensonAssets = SRA_File::propertiesFileToArray(dirname(__FILE__) . '/assets.ini');
		if (!is_array($_sorensonPresets)) $_sorensonPresets = SRA_File::propertiesFileToArray(dirname(__FILE__) . '/presets.ini');
		
		$params = array('output_asset_display_name' => basename(ZC_Util::getTestFileUrl($type)), 
									  'output_asset_description' => "$type", 
										'output_asset_author_name' => $testId . ($batchId ? ":$batchId" : ''),
										'preset_id' => $_sorensonPresets[$type],
										'callback_url' => ZC_Util::getCallbackUrl(ZC_SORENSON_PROVIDER_ID),
										'callback_data' => $testId);
		if ($response = ZC_SorensonTestHarness::invokeApi('transcode_jobs', $_sorensonAssets[$type], $params, ZC_SORENSON_REQUEST_TYPE_POST, 2)) {
			if (SRA_Controller::getAppParams('debug') == '1') {
				SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::encode: successfully submitted encoding request for testId $testId; type $type and batchId $batchId - transcode_job_id=" . $response->transcode_job_id);
				SRA_Error::logError($response);
			}
			ZC_Util::updateTestProviderJobId($testId, $response->transcode_job_id);
			ZC_Util::updateTestStatus($testId, 'encoding', TRUE);
			$result = TRUE;
		}
		else {
			SRA_Error::logError("ERROR: ZC_SorensonTestHarness::encode: Unable to submit encoding request for testId $testId; type $type and batchId $batchId. Response: $response");
		}
		return $result;
	}
	
  /**
   * returns authentication credentials for the current PHP process. The return 
	 * value is a hash containing 3 keys: accountId, sessionId and token. These
	 * can be used (and are required) for subsequent API calls. returns NULL on 
	 * error
   * @return hash
   */
	public static function getAuthCredentials() {
		static $_sorensonAuth;
		if (!is_array($_sorensonAuth)) {
			$_sorensonAuth = array();
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, SRA_Controller::getAppParams('url-auth', ZC_SORENSON_PROVIDER_ID));
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, 'username=' . SRA_Controller::getAppParams('user', ZC_SORENSON_PROVIDER_ID) . '&password=' . SRA_Controller::getAppParams('pswd', ZC_SORENSON_PROVIDER_ID));
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			if ($response = curl_exec($ch)) {
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::getAuthCredentials - got response $response");
				$response = json_decode($response);
				$_sorensonAuth['accountId'] = $response->account_id;
				$_sorensonAuth['sessionId'] = $response->session_id;
				$_sorensonAuth['token'] = $response->token;
			}
			else {
				SRA_Error::logError("ERROR: ZC_SorensonTestHarness::getAuthCredentials - Unable to obtain credentials");
			}
		}
		return count($_sorensonAuth) ? $_sorensonAuth : NULL;
	}
	
	/**
	 * invokes the sorenson API for the arguments specified and returns the JSON
	 * object that results from the call. Returns NULL on failure
	 * @param string $action the action to invoke (URI)
	 * @param string $id the id of the object that the action pertains to (sub-URI)
	 * @param hash $params optional request parameters
	 * @param int $requestType the request type - one of the ZC_SORENSON_REQUEST_TYPE_*
	 * constants. Default is GET
	 * @param boolean $debug if TRUE, the return value will be a curl command line
	 * string that may be used to execute this API call. If $debug == 2, the curl 
	 * command will be executed and the results returned (json decoded)
	 * @return mixed
	 */
	public static function invokeApi($action, $id=NULL, $params=NULL, $requestType=ZC_SORENSON_REQUEST_TYPE_GET, $debug=FALSE) {
		if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::invokeApi - called for action=$action; id=$id; requestType=$requestType");
		$results = NULL;
		if ($auth = ZC_SorensonTestHarness::getAuthCredentials()) {
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::invokeApi - obtained auth credentials " . $auth['accountId'] . ':' . $auth['token'] . ", proceeding with API call");
			$pstring = '';
			if (is_array($params)) {
				foreach($params as $key => $val) {
					$pstring .= $pstring ? '&' : '';
					$pstring .= urlencode($key) . '=' . urlencode($val);
				}
			}
			$url = SRA_Controller::getAppParams('url', ZC_SORENSON_PROVIDER_ID) . '/' . $action . ($id ? '/' . $id : '') . ($requestType == ZC_SORENSON_REQUEST_TYPE_GET ? '?' . $pstring : '');
			
			// return curl debug
			if ($debug) {
				$results = "curl -s $url -X $requestType -u " . $auth['accountId'] . ':' . $auth['token'];
				if (is_array($params) && $requestType == ZC_SORENSON_REQUEST_TYPE_POST) {
					foreach($params as $key => $val) $results .= " -d '$key=$val'";
				}
				// execute curl command
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::invokeApi - debug mode $debug: will return curl command string: $results");
				if ($debug === 2) {
					if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::invokeApi - executing curl command string");
					$results = json_decode(shell_exec($results));
				}
			}
			// make actual api call using curl library
			else {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_USERPWD, $auth['accountId'] . ':' . $auth['token']);
				if ($requestType == ZC_SORENSON_REQUEST_TYPE_POST) {
					curl_setopt($ch, CURLOPT_POST, TRUE);
					if ($pstring) curl_setopt($ch, CURLOPT_POSTFIELDS, $pstring);
				}
				else if ($requestType == ZC_SORENSON_REQUEST_TYPE_DELETE) {
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				}
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				if ($response = curl_exec($ch)) {
					if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_SorensonTestHarness::invokeApi - successfully invoked API URL $url with params $pstring - RESPONSE: $response");
					$results = json_decode($response);
				}
				else SRA_Error::logError("ERROR: ZC_SorensonTestHarness::invokeApi - Unable to invoke API URL $url with params $pstring");
			}
			// request failed
			if (is_object($results) && isset($results->status) && $results->status == 'failure') {
				SRA_Error::logError("ERROR: ZC_SorensonTestHarness::invokeApi - response object indicated request failed. Error message is: '" . $results->message . "'");
				$results = NULL;
			}
		}
		else SRA_Error::logError("ERROR: ZC_SorensonTestHarness::invokeApi - Unable to obtain credentials");
		
		return $results;
	}
}
?>