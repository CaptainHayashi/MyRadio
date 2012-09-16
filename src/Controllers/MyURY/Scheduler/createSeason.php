<?php
/**
 * This is the magic form that makes URY actually have content - it enables users to apply for seasons. And stuff.
 * 
 * @todo Security check to see if this user is allowed to apply for seasons for this show
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 23082012
 * @package MyURY_Scheduler
 */

//The Form definition
$current_term_info = Scheduler::getActiveApplicationTermInfo();
$current_term = $current_term_info['descr'];
require 'Models/MyURY/Scheduler/seasonfrm.php';
$form->setFieldValue('show_id', (int)$_REQUEST['showid']);
require 'Views/MyURY/Scheduler/createSeason.php';