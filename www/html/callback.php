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
