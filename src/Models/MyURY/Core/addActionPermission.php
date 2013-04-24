<?php
/**
 * Adds an Action Permission. Expects input ($data) to be that returned by $form->readValues() or identical format.
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 24072012
 * @package MyURY_Core
 */

$service = $data['service'];
$module = CoreUtils::getModuleId($service, $data['module']);
$action = CoreUtils::getActionId($module, $data['action']);
$permission = $data['permission'];
if (empty($action)) $action = null;
if (empty($permission)) $permission = null;

CoreUtils::addActionPermission($service, $module, $action, $permission);