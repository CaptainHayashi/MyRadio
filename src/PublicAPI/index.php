<?php

/**
 * This MyURY Extension exposes some of MyURY's internal classes as a REST API.
 * It aims to be compatible with https://developers.helloreverb.com/swagger/
 * 
 * @todo Management interfaces to configure keys and expose methods
 */
// Configure MyURY & Set API Settings
define('SILENT_EXCEPTIONS', true);

require_once __DIR__ . '/../Controllers/cli_common.php';

/**
 * Handle API errors
 */
function api_error($code, $message = null) {
  $messages = [400 => "Bad Request", 401 => "Unauthorized",
      403 => "Forbidden", 404 => "File Not Found",
      500 => "Internal Server Error"];
  header("HTTP/1.1 $code {$messages[$code]}");
  header("Content-Type: application/json");
  echo json_encode([
      "status" => $code,
      "message" => $message
  ]);
  //Log an API failure so it appears in the status graphs.
  throw new MyURYException('API Error: ' . $message .
  "\nSource: " . $_SERVER['REMOTE_ADDR'], $code);
}

/**
 * Break up the URL. URLs are of the form /api/ExposedClassName[/id][/method]
 */
//Remove double-slashes that occassionally appear.
$cleaned = str_replace('//', '/', $_SERVER['REQUEST_URI']);
$params = explode('/', str_ireplace(Config::$api_uri, '', explode('?', $cleaned)[0]));
$class = $params[0];

if (empty($class)) {
  //Someone's gone to the home page. Let's tell them to RTFM
  header('Location: rtfm');
  exit;
}

/**
 * Get the API key, or complain if there isn't one.
 * Do this after RTFM.
 * 
 * If accessing Resources, then we give it an API Key with no permissions
 * in order to access it.
 */
if (isset($_REQUEST['apiKey'])) {
  $_REQUEST['api_key'] = $_REQUEST['apiKey'];
}
if (empty($_REQUEST['api_key'])) {
  if ($class === 'resources') {
    $_REQUEST['api_key'] = 'IUrnsb8AMkjqDRdfXvOMe3DqHLW8HJ1RNBPNJq3H1FQpiwQDs7Ufoxmsf5xZE9XEbQErRO97DG4xfyVAO7LuS2dOiVNZYoxkk4fEhDt8wR4sLXbghidtM5rLHcgkzO10';
  } else {
    api_error(401, 'An API Key must be provided.');
  }
}
$api_key = MyURY_APIKey::getInstance($_REQUEST['api_key']);

/**
 * Available API Classes
 */
$classes = MyURY_Swagger::getApiClasses();
if (!isset($classes[$class])) {
  api_error(404);
}
$classReflection = new ReflectionClass($classes[$class]);

/**
 * Okay, now lets see if the second part of the URL is numeric. If it is, that's definitely an ID
 */
if (is_numeric($params[1])) {
  $id = (int) $params[1];
  $method = empty($params[2]) ? 'toDataSource' : $params[2];
} elseif (!empty($params[2])) {
  /**
   * Now, if it's a String, it could be an ID or method. If there's a 3rd part this is the ID.
   */
  $id = $params[1];
  $method = $params[2];
} else {
  /**
   * If there's two parts, this could be a Static method call *or* the ID of something with
   * a String key. We'll have to ask the class if the method exists.
   */
  if (method_exists($classes[$class], $params[1])) {
    $id = -1;
    $method = $params[1];
  } else {
    $id = $params[1];
    $method = 'toDataSource';
  }
}

/**
 * Woo! Now we know everything we need to get started.
 * Now let's check if the method exists and get its details
 */
try {
  $methodReflection = $classReflection->getMethod($method);
} catch (ReflectionException $e) {
  api_error(404);
}

/**
 * Okay, the method exists. Does the given API key have access to it?
 */
if (!$api_key->canCall($classes[$class], $method)) {
  api_error(403, 'Your API Key (' . $_REQUEST['api_key'] . ') does not have access to this method.');
} else {
  /**
   * Map the paramaters
   */
  $args = [];
  foreach ($methodReflection->getParameters() as $param) {
    if (isset($_REQUEST[$param->getName()])) {
      //If the param has a class hint, initialise the class, assuming the argument is an ID.
      if ($param->getClass() !== null) {
        try {
          $hint = $param->getClass()->getName();
          $args[$param->getName()] = $hint::getInstance($_REQUEST[$param->getName()]);
        } catch (MyURYException $ex) {
          api_error(400, 'Parameter ' . $param->getName() . ' got an invalid ID. Must be an ID for ' . $param->getClass() . '.');
        }
      } else {
        $args[$param->getName()] = $_REQUEST[$param->getName()];
      }
    } elseif (!$param->isOptional()) {
      //Uh-oh, required option missing
      api_error(400, 'Parameter ' . $param->getName() . ' is required but not provided.');
    }
  }

  /**
   * From here on out, return a happy error message.
   */
  try {
    /**
     * Okay, now if the method is static, then we need to initialise an object.
     */
    if (!$methodReflection->isStatic()) {
      if (method_exists($classes[$class], 'getInstance')) {
        $object = $classes[$class]::getInstance($id);
      } else {
        $object = new $classes[$class]($id);
      }
    } else {
      $object = null;
    }

    /**
     * Let's process the request!
     */
    $api_key->logCall(preg_replace('/(.*)\?(.*)/', '$1', str_replace(Config::$api_uri, '', $_SERVER['REQUEST_URI'])), $args);
    $result = $methodReflection->invokeArgs($object, $args);
  } catch (MyURYException $e) {
    api_error($e->getCode(), $e->getMessage());
  }

  header('Content/Type: application/json');
  echo json_encode(CoreUtils::dataSourceParser($result));
}