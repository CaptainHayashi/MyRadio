<?php
/**
 * This file provides the NIPSWeb_TimeslotItem class for MyURY - a Show Plan wrapper for all items
 * @package MyURY_NIPSWeb
 */

/**
 * The NIPSWeb_TimeslotItem class helps provide Show Planner with access to all resource types a timeslot item could be
 * 
 * @version 16042013
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @package MyURY_NIPSWeb
 * @uses \Database
 */
class NIPSWeb_TimeslotItem extends ServiceAPI {
  /**
   * The Singleton store for TimeslotItem objects
   * @var Array of NIPSWeb_TimeslotItem
   */
  private static $resources = array();
  
  private $timeslot_item_id;
  
  private $item;
  
  private $channel;
  
  private $weight;
  
  /**
   * Initiates the TimeslotItem variables
   * @param int $resid The timeslot_item_id of the resource to initialise
   * @param NIPSWeb_ManagedPlaylist $playlistref If the playlist is requesting this item, then pass the playlist object
   */
  private function __construct($resid, $playlistref = null) {
    $this->timeslot_item_id = $resid;
    //*dies*
    $result = self::$db->fetch_one('SELECT * FROM bapsplanner.timeslot_items where timeslot_item_id=$1 LIMIT 1',
            array($resid));
    if (empty($result)) {
      throw new MyURYException('The specified Timeslot Item does not seem to exist');
      return;
    }
    
    /**
    * @todo detect definition of multiple track types in an entry and fail out
    */
     if ($result['rec_track_id'] != null) {
       //CentralDB
       $this->item = MyURY_Track::getInstance($result['rec_track_id']);
     } elseif ($result['managed_item_id'] != null) {
       //ManagedDB (Central Beds, Jingles...)
       $this->item = NIPSWeb_ManagedItem::getInstance($result['managed_item_id'], $playlistref);
     }
    
    $this->channel = (int)$result['channel_id'];
    $this->weight = (int)$result['weight'];
  }
  
  /**
   * Returns the current instance of that TimeslotItem object if there is one, or runs the constructor if there isn't
   * @param int $resid The ID of the TimesotItem to return an object for
   * @param NIPSWeb_ManagedPlaylist $playlistref If the playlist is requesting this item, then pass the playlist object
   * itself as a reference to prevent cyclic dependencies.
   */
  public static function getInstance($resid = -1, $playlistref = null) {
    self::__wakeup();
    if (!is_numeric($resid)) {
      throw new MyURYException('Invalid TimeslotItemID!');
    }

    if (!isset(self::$resources[$resid])) {
      self::$resources[$resid] = new self($resid, $playlistref);
    }

    return self::$resources[$resid];
  }
  
  /**
   * Get the unique timeslotitemid of the TimeslotItem
   * @return int
   */
  public function getID() {
    return $this->timeslot_item_id;
  }
  
  public function getChannel() {
    return $this->channel;
  }
  
  public function getWeight() {
    return $this->weight;
  }
  
  public function getItem() {
    return $this->item;
  }
  
  public function setLocation($channel, $weight) {
    $this->channel = (int) $channel;
    $this->weight = (int) $weight;
    self::$db->query('UPDATE bapsplanner.timeslot_items SET channel=$1, weight=$2 WHERE timeslot_item_id=$3 LIMIT 1',
            array($this->channel, $this->weight, $this->getID()));
  }
  
  public function remove() {
    self::$db->query('DELETE FROM WHERE timeslot_item_id=$1',
            array($this->getID()));
    unset($this);
  }
  
  public static function create_managed($timeslot, $manageditemid, $channel, $weight) {
    $result = self::$db->fetch_one('INSERT INTO bapsplanner.timeslot_items (timeslot_id, managed_item_id, channel_id, weight)
      VALUES ($1, $2, $3, $4) RETURNING timeslot_item_id',
            array($timeslot, $manageditemid, $channel, $weight));
    
    return self::getInstance($result[0]);
  }
  
  public static function create_central($timeslot, $trackid, $channel, $weight) {
    $result = self::$db->fetch_one('INSERT INTO bapsplanner.timeslot_items (timeslot_id, rec_track_id, channel_id, weight)
      VALUES ($1, $2, $3, $4) RETURNING timeslot_item_id',
            array($timeslot, $trackid, $channel, $weight));
    
    return self::getInstance($result[0]);
  }
  
  /**
   * Returns an array of key information, useful for Twig rendering and JSON requests
   * @todo Expand the information this returns
   * @return Array
   */
  public function toDataSource() {
    return array_merge(array(
        'timeslotitemid' => $this->getID(),
        'channel' => $this->getChannel(),
        'weight' => $this->getWeight()
        ),
        $this->getItem()->toDataSource()
    );
  }
}
