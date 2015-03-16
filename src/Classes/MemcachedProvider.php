<?php
/**
 * This file contains the MemcachedProvider class. The current CacheProvider is loaded as part of the bootstrap process,
 * as configured in the Config
 */

namespace MyRadio;

use \Memcached;

/**
 * MemcachedProvider provides in-memory caching for PHP resources to increase page load times
 *
 * MemcachedProvider was the first CacheProvider implementation in MyRadio. It enables Models to send cache commands to it
 * which are then stored using the APC plugin automatically. In order for this class to work correctly, the server
 * needs the APC PHP plugin installed on the server. It will throw an Exception if it is not.
 *
 * @package MyRadio_Core
 */
class MemcachedProvider implements \MyRadio\Iface\CacheProvider
{
    /**
     * A variable to store the singleton instance
     * @var MemcachedProvider storage for the only MemcachedProvider instance
     */
    private static $me;

    /**
     * Stores whether caching should be used. If not, it does not do anything on function calls
     * @var boolean
     */
    private $enable;
    /**
     * Stores the underlying Memcached object
     * @var Memcached
     */
    private $memcached;

    /**
     * Constructs the Unique instance of the CacheProvider for use. Private so that instances cannot be used in ways
     * other than those intended
     * @param boolean $enable Whether caching is actually enabled in this request. Default true.
     */
    private function __construct($enable = true, $servers = [])
    {
        $this->enable = $enable;
        if ($enable) {
            if (!class_exists('\Memcached')) {
                //Functions not available. If this is caught upstream, just disable
                trigger_error('Cache is enabled but selected CacheProvider does not have required prerequisites (Is Memcached Extension installed and loaded?)');
                $this->enable = false;
            } elseif (empty($servers)) {
                trigger_error('No Memcached servers are configured.');
                $this->enable - false;
            } else {
                $this->memcached = new Memcached();
                $this->memcached->addServers($servers);
            }
        }
    }

    /**
     * Stores an object in the APC User Object Cache
     *
     * @param  String $key     The unique name of the object to store. Ideally, this would use myradio_{module}_{name}
     * @param  mixed  $value   The data to store
     * @param  int    $expires The number of seconds this cache entry is valid for.Default is value of
     *            MyRadio_Config::$cache_default_timeout
     * @return boolean Whether the operation was successful (returns false if caching disabled)
     * @assert ('myradio_core_test', 'test value', 0) == true
     * @todo Consider using Memcached::cas
     */
    public function set($key, $value, $expires = 0)
    {
        if (!$this->enable) {
            return false;
        }

        if ($expires === 0) {
            $expires = \MyRadio\Config::$cache_default_timeout;
        }
        // Values > 30 days are assumed to be epoch times
        // http://php.net/manual/en/memcached.expiration.php
        if ($expires > 60*60*24*30) {
            $expires = time() + $expires;
        }
        return $this->memcached->set($this->getKeyPrefix() . $key, $value, $expires);
    }

    /**
     * Reads a previously stored value from Memcached and returns it
     *
     * @param  String $key The unique name of the object to fetch
     * @return mixed The value of the store, or false on failure
     * @assert ('myradio_core_test') == 'test value'
     */
    public function get($key)
    {
        if (!$this->enable) {
            return false;
        }
        return $this->memcached->get($this->getKeyPrefix() . $key);
    }

    public function getAll($prefix = '')
    {
        if (!$this->enable) {
            return [];
        }

        $prefix = $this->getKeyPrefix() . $prefix;
        $result = [];
        $len = strlen($prefix);
        foreach ($this->memcached->getAllKeys() as $key) {
            if (substr($key, 0, $len) === $prefix) {
                //Don't use $this->get as it'll append the prefix twice
                $result[] = $this->memcached->get($key);
            }
        }

        return $result;
    }

    /**
     * Deletes a previously stored value from Memcached
     *
     * @param  String $key The unique name of the object to delete
     * @return boolean Returns whether the operaion was a success
     * @assert ('myradio_core_test') == true
     */
    public function delete($key)
    {
        if (!$this->enable) {
            return false;
        }
        return $this->memcached->delete($this->getKeyPrefix() . $key);
    }

    /**
     * This will completely wipe Memcached. This is not limited to MyRadio items
     */
    public function purge()
    {
        if (!$this->enable) {
            return false;
        }
        return $this->memcached->flush();
    }

    /**
     * Returns the Singleton instance of this class, creating it if necessary
     *
     * @return MemcachedProvider
     */
    public static function getInstance()
    {
        if (!self::$me) {
            self::$me = new self(
                Config::$cache_enable,
                Config::$cache_memcached_servers
            );
        }
        return self::$me;
    }

    /**
     * Prevent copies being unintentionally made
     * @throws MyRadioException
     */
    public function __clone()
    {
        throw new \MyRadio\MyRadioException('Attempted to clone a singleton');
    }

    private function getKeyPrefix()
    {
        return 'MyRadioCache-';
    }
}
