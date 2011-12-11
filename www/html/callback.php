<?php

/**
 * primary provider callback handling script
 */
require_once('/var/www/sierra/lib/core/SRA_Controller.php');
SRA_Controller::init('zencoder');
header('Content-type: text/plain');
$dao =& SRA_DaoFactory::getDao('ZC_Provider');
if (!isset($_GET['providerId']) || !ZC_Provider::isValid($provider =& $dao->findByPk($_GET['providerId']))) {
	echo 'Invalid providerId';
}
else {
	unset($_GET['providerId']);
	$args = array(is_array($_POST) ? array_merge($_GET, $_POST) : $_GET);
	SRA_Util::invokeStaticMethodPath($provider->getHarness() . '::callback', $args);
}
?>
