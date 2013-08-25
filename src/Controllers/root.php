<?php

/**
 * This is the Root Controller - it is the backbone of every request, preparing resources and passing the request onto
 * the necessary handler.
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130525
 * @package MyURY_Core
 * @uses Shibbobleh
 * @uses \CacheProvider
 * @uses \Database
 * @uses \CoreUtils
 */
/**
 * Turn on Error Reporting for the base start. Once the Config object is loaded this is altered based on the
 * Config::$debug setting
 */
error_reporting(E_ALL ^ E_STRICT);
ini_set('display_errors', 'On');
/**
 * Set the Default TimeZone.
 * Once Config is available, this value should be used instead.
 */
date_default_timezone_get('Europe/London');
/**
 * Sets the include path to include MyURY at the end - makes for nicer includes
 */
ini_set('include_path', str_replace('Controllers', '', __DIR__) . ':' . ini_get('include_path'));

/**
 * The Shibbobleh Client checks whether the user is authenticated, asking them to login via its captive portal
 * if necessary. See the Shibbobleh project documentation for more information about what it provides.
 */
require_once 'shibbobleh_client.php';
/**
 * The CoreUtils static class provides some useful standard functions for MyURY. Take a look at it before you start
 * developing - it may just save you some head scratching.
 */
require_once 'Classes/MyURY/CoreUtils.php';
/**
 * Load up the general Configurables - this includes things like the Database connection settings, the CacheProvider
 * to use and whether debug mode is enabled.
 */
require_once 'Classes/Config.php';

/**
 * Load in email functions
 */
require_once 'Classes/MyURYEmail.php';

/**
 * Load the phpError handler class - this has functions to put errors nicely on
 * the page, or to log them elsewhere.
 * And set the error handler to use it
 */
require_once 'Classes/MyURYError.php';
set_error_handler('MyURYError::errorsToArray');

/**
 * Turn off visible error reporting, if needed
 * 269 is AUTH_SHOWERRORS - the constants aren't initialised yet
 */
if (!Config::$display_errors && !CoreUtils::hasPermission(269)) {
  ini_set('display_errors', 'Off');
}
ini_set('error_log', Config::$log_file); // Set error log file

/**
 * Call the model that prepares the Database and the Global Abstraction API 
 */
require 'Models/Core/api.php';

/**
 * The Service Broker decides what version of a Service the user has access to. This includes MyURY, so gets added
 * here.
 * @todo Discuss or document the parts of MyURY core that cannot be brokered, see if this can be moved earlier
 */
require_once 'Controllers/service_broker.php';

/**
 * Set up the Module and Action global variables. These are used by Module/Action controllers as well as this file.
 * Notice how the default Module is MyURY. This is basically the MyURY Menu, and maybe a couple of admin pages.
 * Notice how the default Action is 'default'. This means that the "default" Controller should exist for all Modules.
 * The top half deals with Rewritten URLs, which get mapped to ?request=
 */
if (isset($_REQUEST['request'])) {
  $info = explode('/', $_REQUEST['request']);
  //If both are defined, it's Module/Action
  if (!empty($info[1])) {
    $module = $info[0];
    $action = $info[1];
    //If there's only one, determine if it's the module or action
  } elseif (CoreUtils::isValidController(Config::$default_module, $info[0])) {
    $module = Config::$default_module;
    $action = $info[0];
  } elseif (CoreUtils::isValidController($info[0], Config::$default_action)) {
    $module = $info[0];
    $action = Config::$default_action;
  } else {
    require 'Controllers/Errors/404.php';
    exit;
  }
} else {
  $module = (isset($_REQUEST['module']) ? $_REQUEST['module'] : Config::$default_module);
  $action = (isset($_REQUEST['action']) ? $_REQUEST['action'] : Config::$default_action);
  if (!CoreUtils::isValidController($module, $action)) {
    //Yep, that doesn't exist.
    require 'Controllers/Errors/404.php';
    exit;
  }
}

/**
 * Use the Database authentication data to check whether the use has permission to access that.
 * This method will automatically cause a premature exit if necessary.
 * 
 * IMPORTANT: This will cause a fatal error if an action does not have any permissions associated with it.
 * This is to prevent developers from forgetting to assign permissions to an action.
 */
CoreUtils::requirePermissionAuto($module, $action);

/**
 * If a Joyride is defined, start it
 */
if (isset($_REQUEST['joyride'])) {
  $_SESSION['joyride'] = $_REQUEST['joyride'];
}

//Include the requested action
require 'Controllers/' . $module . '/' . $action . '.php';