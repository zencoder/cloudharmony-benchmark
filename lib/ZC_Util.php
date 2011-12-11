<?php

/**
 * provides basic static utility methods used throughout the application. this
 * class is automatically imported into the application
 * @author  Jason Read <jason@cloudharmony.com>
 */
class ZC_Util {
	/**
	 * returns all of the testIds associated with $batchId
	 * @param int $batchId the batch ID to return the test IDs for
	 * @return array
	 */
	public static function getAllProviderIds() {
		$providerIds = array();
		$db =& SRA_Controller::getAppDb();
		if (SRA_ResultSet::isValid($results =& $db->fetch('SELECT provider_id FROM zc_provider'))) {
			while($row =& $results->next()) $providerIds[] = $row[0];
		}
		return $providerIds;
	}
	
	/**
	 * returns all of the testIds associated with $batchId
	 * @param int $batchId the batch ID to return the test IDs for
	 * @return array
	 */
	public static function getBatchTestIds($batchId) {
		$testIds = array();
		$db =& SRA_Controller::getAppDb();
		if (SRA_ResultSet::isValid($results =& $db->fetch('SELECT test_id FROM zc_encoding_test WHERE batch_id=' . $db->convertInt($batchId)))) {
			while($row =& $results->next()) $testIds[] = $row[0];
		}
		return $testIds;
	}
	
	/**
	 * returns a provider specific callback URL. Any requests to this URL will 
	 * automatically be routed to the provider harness static method 'callback'
	 * @param string $providerId the id of the provider to return the callback 
	 * URL for (required)
	 * @return string
	 */
	public static function getCallbackUrl($providerId) {
		return SRA_Controller::getAppParams('url', 'callback') . $providerId;
	}
	
	/**
	 * returns the audio bitrate to use for the test identified by $type
	 * @param string $type the test type
	 * @return int
	 */
	public static function getTestBitrateAudio($type, $audio=FALSE) {
		return ZC_Util::getTestBitrateVideo($type, TRUE);
	}
	
	/**
	 * returns the video bitrate to use for the test identified by $type. returns
	 * NULL if this test type is for audio only
	 * @param string $type the test type
	 * @return int
	 */
	public static function getTestBitrateVideo($type, $audio=FALSE) {
		$settings = SRA_File::propertiesFileToArray(SRA_Controller::getAppConfDir() . '/test-encode-settings.ini');
		$key = $type . '-' . ($audio ? 'audio' : 'video') . '-bitrate';
		return isset($settings[$key]) ? $settings[$key] : NULL;
	}
	
	/**
	 * returns name of the S3 bucket containing the test files
	 * @return string
	 */
	public static function getS3Bucket() {
		return SRA_Controller::getAppParams('bucket', 's3');
	}
	
	/**
	 * returns the HTTP URL to use for the test identified by $type
	 * @param string $type the test type
	 * @param boolean $s3key if TRUE, the S3 bucket key will be returned instead
	 * of the file URL
	 * @return string
	 */
	public static function getTestFileUrl($type, $s3key=FALSE) {
		$files = SRA_File::propertiesFileToArray(SRA_Controller::getAppConfDir() . '/test-files.ini');
		$url = isset($files[$type]) ? $files[$type] : NULL;
		if ($url && $s3key) {
			$pieces = explode(ZC_Util::getS3Bucket(), $url);
			$url = $pieces[1];
		}
		return $url;
	}
	
	/**
	 * returns the $testId for the $providerJobId specified
	 * @param int $providerJobId the provider job id
	 * @return int
	 */
	public static function getTestIdFromProviderJobId($providerJobId) {
		$db =& SRA_Controller::getAppDb();
		if (is_numeric($testId = SRA_Database::getQueryValue($db, 'SELECT test_id FROM zc_encoding_test WHERE provider_job_id=' . $db->convertString($providerJobId)))) {
			return $testId;
		}
		else {
			return NULL;
		}
	}
	
	/**
	 * updates a the providerJobId of the test identified by $testId. returns 
	 * TRUE on success, FALSE on failure
	 * @param int $testId the test to update
	 * @param int $jobId the provider job ID to use
	 * @return boolean
	 */
	public static function updateTestProviderJobId($testId, $jobId) {
		$result = FALSE;
		$dao =& SRA_DaoFactory::getDao('ZC_EncodingTest');
		if ($testId && ZC_EncodingTest::isValid($test =& $dao->findByPk($testId))) {
			$test->setProviderJobId($jobId);
			$result = $dao->update($test);
		}
		return $result;
	}
	
	/**
	 * updates a the status of the test identified by $testId. returns TRUE on 
	 * success, FALSE on failure
	 * @param int $testId the test to update
	 * @param string $status the new test status
	 * @param boolean $noTransferUpdate when TRUE and the status has been changed
	 * from transfer to encoding, the transfer time will not be updated
	 * @return boolean
	 */
	public static function updateTestStatus($testId, $status, $noTransferUpdate=FALSE) {
		if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_Util::updateTestStatus - update status of test $testId to $status");
		$result = FALSE;
		$dao =& SRA_DaoFactory::getDao('ZC_EncodingTest');
		if ($testId && ZC_EncodingTest::isValid($test =& $dao->findByPk($testId))) {
			if (SRA_Controller::getAppParams('debug') == '1') SRA_Error::logError("DEBUG: ZC_Util::updateTestStatus - current status is " . $test->getStatus());
			$test->__noTransferUpdate = $noTransferUpdate;
			$test->setStatus($status);
			$result = $dao->update($test);
		}
		return $result;
	}
}
?>