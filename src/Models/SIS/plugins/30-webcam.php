<?php
/**
 * Webcam Plugin for SIS
 * 
 * @author Andy Durant <aj@ury.org.uk>
 * @version 20130923
 * @package MyURY_SIS
 */

$moduleInfo = array(
'name' => 'webcam',
'title' => 'Webcam Selector',
'enabled' => true,
'startOpen' => false,
'help' => 'You may have noticed that Studio 1 now has two webcams. The Webcam section over to the left lets you choose which of the station\'s cameras can be seen by listeners.',
'template' => 'SIS/plugins/webcam.twig',
'vars' => $vars,
'required_permission' => AUTH_MODIFYWEBCAM,
'required_location' => true,
);


$vars = array(
	'webcam_prefix' => Config::$webcam_prefix,
	'cameras' => array('jukebox.jpg', 'studio1', 'studio2', null, 'office', 's1-fos'),
	'current' => explode("\t", file_get_contents($baseURL . "/plugins/webcam/get.php"), 2)[1]
	);

  /**
   * @todo: current - will be in a class
   */