<?php
/**
 * Allows creation of new URY members!
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130717
 * @package MyURY_Profile
 */
$params = User::getQuickAddForm()->readValues();

$user = User::create($params);

CoreUtils::backWithMessage('New Member has been created with ID '.$user->getID());