<?php

/**
 * Allows Users to edit their profiles, or for admins to edit other users.
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130731
 * @package MyURY_Profile
 */
// Set if trying to view another member's profile page
if (isset($_REQUEST['profileedit-memberid']) && User::getInstance()->hasAuth(AUTH_EDITANYPROFILE)) {
  $user = User::getInstance($_REQUEST['profileedit-memberid']);
} else {
  $user = User::getInstance();
}

$data = $user->getEditForm()->readValues();

$user->setFName($data['fname'])
        ->setSName($data['sname'])
        ->setSex($data['sex'])
        ->setCollegeID($data['collegeid'])
        ->setPhone($data['phone'])
        ->setEmail($data['email'])
        ->setReceiveEmail($data['receive_email'])
        ->setEduroam($data['eduroam'])
        ->setBio($data['bio']);

if (!empty($data['photo']['tmp_name'])) {
  $user->setProfilePhoto(MyURY_Photo::create($data['photo']['tmp_name']));
}

if (isset($data['local_name'])) {
  $user->setLocalName($data['local_name'])
          ->setLocalAlias($data['local_alias']);
}

header('Location: ' . CoreUtils::makeURL('Profile', 'view', array('memberid' => $data['memberid'])));