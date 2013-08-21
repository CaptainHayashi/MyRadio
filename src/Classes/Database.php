<?php
/**
 * This file provides the Database class for MyURY
 * @package MyURY_Core
 */

/**
 * This singleton class handles actual database connection
 * 
 * This is a Critical include! - It is loaded before MyURY Brokers into versions so only the live one is used!
 * 
 * @version 20130531
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @depends Config
 * @package MyURY_Core
 */
class Database {

  /**
   * Stores the singleton instance of the Database object 
   * @var Database
   */
  private static $me;

  /**
   * Stores the resource id of the connection to the PostgreSQL database
   * @var Resource
   */
  private $db;
  
  /**
   * Stores the number of queries executed
   * @var int
   */
  private $counter = 0;

  /**
   * Constructs the singleton database connector 
   */
  private function __construct() {
    $this->db = pg_connect('host=' . Config::$db_hostname . ' port=5432 dbname=membership
            user=' . Config::$db_user . ' password=' . Config::$db_pass);
    if (!$this->db) {
      //Database isn't working. Throw an EVERYTHING IS BROKEN Exception
      throw new MyURYException('Database Connection Failed!',
              MyURYException::FATAL);
    }
  }
  
  /**
   * Attempts to reset connection to the database server
   */
  public function reconnect() {
    return pg_connection_reset($this->db);
  }
  
  /**
   * Check if the connection to the database server is still alive.
   * @return boolean Whether the connection is OK.
   */
  public function status() {
    return pg_connection_status($this->db) === PGSQL_CONNECTION_OK;
  }

  /**
   * Generic function that just runs a pg_query_params
   * @param String $sql The query string to execute
   * @param Array $params Parameters for the query
   * @param bool $rollback If true, an error will automatically execute a rollback before throwing an Exception
   * Use for transactions.
   * @return A pg result reference
   * @throws MyURYException If the query fails
   * @assert ('SELECT * FROM public.tableethatreallydoesntexist') throws MyURYException
   * @assert ('SELECT * FROM public.member') != false
   */
  public function query($sql, $params = array(), $rollback = false) {
    
    foreach ($params as $k => $v) {
      if (is_bool($v)) $params[$k] = ($v? 't' : 'f');
    }
    
    if (isset($_REQUEST['dbdbg']) && CoreUtils::hasPermission(AUTH_SHOWERRORS)) {
      //Debug output
      echo $sql.'&nbsp;'.print_r($params,true).'<br>';
    }
    
    $result = @pg_query_params($this->db, $sql, $params);
    if (!$result) {
      if ($rollback) {
        pg_query($this->db, 'ROLLBACK');
      }
      throw new MyURYException('Query failure: ' . $sql . '<br>'
              . pg_errormessage($this->db).'<br>Params: '.print_r($params,true));
    }
    $this->counter++;
    return $result;
  }

  /**
   * Equates to a pg_num_rows($result)
   * @param Resource $result a reference to a postgres result set
   * @return int The number of rose in the result set
   */
  public function num_rows($result) {
    return pg_num_rows($result);
  }

  /**
   * The most commonly used database function
   * Equates to a pg_fetch_all(pg_query)
   * @param String|Resource $sql The query string to execute
   * or a psql result resource
   * @param Array $params Parameters for the query
   * @return Array An array of result rows (potentially empty)
   * @throws MyURYException 
   */
  public function fetch_all($sql, $params = array()) {
    if (is_resource($sql)) {
      return pg_fetch_all($sql);
    } elseif (is_string($sql)) {
      try {
        $result = $this->query($sql, $params);
      } catch (MyURYException $e) {
        return array();
      }
      if (pg_num_rows($result) === 0)
        return array();
      return pg_fetch_all($result);
    } else {
      throw new MyURYException('Invalid Request for $sql');
    }
  }

  /**
   * Equates to a pg_fetch_assoc(pg_query). Returns the first row
   * @param String $sql The query string to execute
   * @param Array $params Paramaters for the query
   * @return Array The requested result row, or an empty array on failure
   * @throws MyURYException 
   */
  public function fetch_one($sql, $params = array()) {
    try {
      $result = $this->query($sql, $params);
    } catch (MyURYException $e) {
      return array();
    }
    return pg_fetch_assoc($result);
  }

  /**
   * Equates to a pg_fetch_all_columns(pg_query,0). Returns all first column entries
   * @param String $sql The query string to execute
   * @param Array $params Paramaters for the query
   * @param bool $rollback If true, an error will automatically execute a rollback before throwing an Exception
   * Use for transactions.
   * @return Array The requested result column, or an empty array on failure
   * @throws MyURYException 
   */
  public function fetch_column($sql, $params = array(), $rollback = false) {
    try {
      $result = $this->query($sql, $params, $rollback);
    } catch (MyURYException $e) {
      return array();
    }
    if (pg_num_rows($result) === 0)
      return array();
    return pg_fetch_all_columns($result, 0);
  }

  /**
   * Used to create the object, or return a reference to it if it already exists
   * @return Database One of these things
   */
  public static function getInstance() {
    if (!self::$me) {
      self::$me = new self();
    }
    return self::$me;
  }

  /**
   * Prevent copies being unintentionally made
   * @throws MyURYException
   */
  public function __clone() {
    throw new MyURYException('Attempted to clone a singleton');
  }

  /**
   * Converts a postgresql array to a php array
   * json_decode *nearly* works in some cases, but this tends to be more reliable
   * 
   * Based on http://stackoverflow.com/questions/3068683/convert-postgresql-array-to-php-array
   */
  public function decodeArray($text) {
    $limit = strlen($text) - 1;
    $output = array();
    $offset = 1;
    
    if ('{}' != $text)
      do {
        if ('{' != $text{$offset}) {
          preg_match("/(\\{?\"([^\"\\\\]|\\\\.)*\"|[^,{}]+)+([,}]+)/", $text, $match, 0, $offset);
          $offset += strlen($match[0]);
          $output[] = ( '"' != $match[1]{0} ? $match[1] : stripcslashes(substr($match[1], 1, -1)) );
          if ('},' == $match[3])
            return $offset;
        }
        else
          $offset = pg_array_parse($text, $output[], $limit, $offset + 1);
      }
      while ($limit > $offset);
    return $output;
  }
  
  public function intervalToTime($interval) {
    return strtotime('1970-01-01 '.$interval.'+00');
  }
  
  public function getCounter() {
    return $this->counter;
  }
  
  public function resetCounter() {
    $this->counter = 0;
  }

}