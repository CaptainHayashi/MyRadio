<?php
/**
 * This file lets administrators choose a version of the service to use.
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130525
 * @package MyURY_Core
 * 
 * @uses $member - The current user
 * 
 * Sets the $service_version Global Variable
 */

// Get a list of Service Versions for this Service
$versions = CoreUtils::getServiceVersions();

// If the version selector has just been submitted, update the session
if (isset($_REQUEST['svc_version'])) {
  $serviceid = Config::$service_id;
  foreach ($versions as $version) {
    if ($version['version'] === $_POST['svc_version']) {
      $_SESSION['myury_svc_version_'.$serviceid] = $version['version'];
      $_SESSION['myury_svc_version_'.$serviceid.'_path'] = $version['path'];
    }
  }
  header('Location: ?service='.$_REQUEST['svc_name']);
  exit;
}

if (isset($_REQUEST['select_version'])) {
  $service = $_REQUEST['select_version'];
  require 'Views/MyURY/brokerVersion.php';
  exit;
}