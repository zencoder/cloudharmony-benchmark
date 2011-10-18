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
 * encoding.com providerId
 */
define('ZC_ENCODING_COM_PROVIDER_ID', 'encodingcom');

/*
ENCODING RESPONSE
Array
(
    [xml] => <?xml version="1.0"?>
<result><mediaid>5220424</mediaid><source>https://s3.amazonaws.com/[FILTERED]/content/30s_phone_2pass.mov</source><status>Finished</status><description></description><format><taskid>18737549</taskid><output>m4v</output><status>Finished</status><destination>http://encoding.com.result.s3.amazonaws.com/30s_phone_2pass_18737549.m4v</destination></format></result>

)
*/

/**
 * this class defines the encode execution and administrative integration with
 * encoding providers. Each ZC_Provider instance must have a corresponding 
 * harness in order to enable testing of that provider
 * @author  Jason Read <jason@cloudharmony.com>
 */
class ZC_EncodingTestHarness extends ZC_EncodeTestHarness {
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
			SRA_Error::logError('DEBUG: ZC_EncodingTestHarness::callback: invoked with arguments');
			SRA_Error::logError($args);
		}
		if (preg_match('/<mediaid>([0-9]+)<\/mediaid>.*content\/(.*)\..*<status>(.*)<\/status>/msU', $args['xml'], $m)) {
			// mediaid=$m[1]; type=$m[2]; status=$m[3]
			$success = $m[3] == 'Finished' || $m[3] == 'Ready to process';
			// upload media response
			if ($args['action'] == 'AddMediaBenchmark' && preg_match('/<status>New<\/status>/msU', $args['xml'])) {
				if ($success) {
					if (SRA_Controller::getAppParams('debug') == '1') {
						SRA_Error::logError('DEBUG: ZC_EncodingTestHarness::callback: add media successful with mediaId ' . $m[1] . ' and type ' . $m[2]);
						SRA_Error::logError('DEBUG: ZC_EncodingTestHarness::callback: start encoding for testId ' . $args['testId']);
					}
					ZC_EncodingTestHarness::encode($args['testId'], $m[2], NULL, $m[1]);
				}
				else {
					SRA_Error::logError('ERROR: ZC_EncodingTestHarness::callback: add media failed');
					ZC_Util::updateTestStatus($args['testId'], 'error', $args['batchId']);
				}
			}
			// encoding response
			else if ($args['action'] == 'AddMediaBenchmark') {
				ZC_Util::updateTestStatus($args['testId'], $success ? 'complete' : 'error');
				// delete media
				if (!ZC_EncodingTestHarness::sendApiRequest('CancelMedia', $args['testId'], $args['batchId'], array('mediaid' => $m[1]))) {
					SRA_Error::logError("ERROR: ZC_EncodingTestHarness::callback - unable to delete media " . $m[1]);
				}
			}
			// unknown action
			else SRA_Error::logError('ERROR: ZC_EncodingTestHarness::callback - Invalid action ' . $args['action']);
		}
		else {
			SRA_Error::logError('ERROR: ZC_EncodingTestHarness::callback - Unable to parse results from callback arguments');
			SRA_Error::logError($args);
		}
	}
	
  /**
   * this function must be overriden. it should instruct the encoding service 
   * to encode the video identified by $type. should return TRUE on success
   * FALSE on failure
   * @param int $testId the testId that this encoding job pertains to
   * @param string $type the video type to encode
   * @param int $batchId optional batch identifier for this encoding test (if 
   * the encoding job is part of a larger batch)
   * @param int $mediaId optional mediaId to use for the encode job
   * @return boolean
   */
	public static function encode($testId, $type, $batchId, $mediaId=NULL) {
		$result = FALSE;
		// raw video is already uploaded, go ahead with encoding
		if ($mediaId) {
			ZC_Util::updateTestStatus($testId, 'encoding');
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_EncodingTestHarness::encode - encoding media $mediaId");
			if (($xml = ZC_EncodingTestHarness::sendApiRequest('ProcessMedia', $testId, $batchId, array('mediaid' => $mediaId)))) {
				if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_EncodingTestHarness::encode - got XML response '$xml'");
				$result = TRUE;
			}
			else {
				SRA_Error::logError("ERROR: ZC_EncodingTestHarness::encode - unable to submit encoding request for testId $testId");
				ZC_Util::updateTestStatus($testId, 'error');
			}
		}
		// need to upload media first
		else if (ZC_EncodingTestHarness::addMedia($testId, $type, $batchId)) $result = TRUE;

		return $result;
	}
	
	
	/**
	 * uploads the raw video associated to the test $type specified. returns the
	 * new mediaId on success, NULL otherwise
	 * @param int $testId testId to add to the callback
	 * @param string $type the test type
	 * @param int $batchId optional batchId
	 * @return int
	 */
	public static function addMedia($testId, $type, $batchId=NULL) {
		$mediaId = NULL;
		$audioOnly = ZC_Util::getTestBitrateVideo($type) ? FALSE : TRUE;
		$format = array(
      'output' => 'mp4',
      'audio_codec' => 'libfaac',
      'audio_bitrate' => ZC_Util::getTestBitrateAudio($type) . 'k',
			'two_pass' => 'yes'
    );
		if (!$audioOnly) {
			$format['bitrate'] = ZC_Util::getTestBitrateVideo($type) . 'k';
			$format['video_codec'] = 'libx264';
			// use twin turbo option for video encoding (uses higher end, multi-threaded encoding server)
			$format['twin_turbo'] = 'yes';
		}
		if (($xml = ZC_EncodingTestHarness::sendApiRequest('AddMediaBenchmark', $testId, $batchId, array('source' => ZC_Util::getTestFileUrl($type), 'region' => 'us-east-1', 'format' => $format))) && 
		    preg_match('/<MediaID>([0-9]+)<\/MediaID>/msU', $xml, $m)) {
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_EncodingTestHarness::addMedia - got XML response '$xml'. MediaID is " . $m[1]);
			$mediaId = $m[1];
		}
		return $mediaId;
	}
	
	/**
	 * submits the API request specified by $action and returns the resulting XML
	 * on success, NULL on error
	 * @param string $action the action to perform
	 * @param string $testId optional testId to include in the notification
	 * @param string $batchId optional batchId to include in the notification
	 * @param hash $params an array of optional parameters that should be added
	 * to the request. the value may also be a hash in which case it will be nested
	 * in the XML structure using the top level key as the node name
	 * @return string
	 */
	public function sendApiRequest($action, $testId=NULL, $batchId=NULL, $params=NULL) {
		$result = NULL;
		if ($action) {
			$req = new SimpleXMLElement('<?xml version="1.0"?><query></query>');
			$req->addChild('userid', SRA_Controller::getAppParams('userId', ZC_ENCODING_COM_PROVIDER_ID));
			$req->addChild('userkey', SRA_Controller::getAppParams('key', ZC_ENCODING_COM_PROVIDER_ID));
			$req->addChild('action', $action);
			$req->addChild('notify', htmlspecialchars(ZC_Util::getCallbackUrl(ZC_ENCODING_COM_PROVIDER_ID) . '?action=' . $action . ($testId ? '&testId=' . $testId : '') . ($batchId ? '&batchId=' . $batchId : '')));
			if (is_array($params)) {
				foreach(array_keys($params) as $key) {
					if (is_array($params[$key])) {
						$sub = $req->addChild($key);
						foreach($params[$key] as $k => $v) $sub->addChild($k, $v);
					}
					else {
						$req->addChild($key, $params[$key]);
					}
				}
			}
			if (SRA_Controller::getAppParams('debug') == '1')	SRA_Error::logError("DEBUG: ZC_EncodingTestHarness::sendApiRequest - Sending API request:" . $req->asXML());
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, SRA_Controller::getAppParams('url', ZC_ENCODING_COM_PROVIDER_ID));
			curl_setopt($ch, CURLOPT_POSTFIELDS, "xml=" . urlencode($req->asXML()));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			if ($res = curl_exec($ch)) {
				try {
					if (SRA_Controller::getAppParams('debug') == '1')	SRA_Error::logError("DEBUG: ZC_EncodingTestHarness::sendApiRequest successful with XML response '$res'");
					// Creating new object from response XML  
					$response = new SimpleXMLElement($res);  
					// If there are any errors, set error message  
					if(isset($response->errors[0]->error[0])) $error = (string) $response->errors[0]->error[0];
					else $result = $res;
				}
				// If wrong XML response received
				catch(Exception $e) { $error = $e->getMessage(); }
			}
			else {
				$error = 'Unable to invoke encoding.com API using XML=' . $req->asXML();
			}
			// error occurred
			if ($error) SRA_Error::logError("ERROR: ZC_EncodingTestHarness::sendApiRequest - Unable to submit API request due to error $error");
		}
		return $result;
	}
}
?>