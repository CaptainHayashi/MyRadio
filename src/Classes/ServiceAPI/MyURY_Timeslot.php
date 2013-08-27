<?php

/**
 * Provides the Timeslot class for MyURY
 * @package MyURY_Scheduler
 */

/**
 * The Timeslot class is used to view and manupulate Timeslot within the new MyURY Scheduler Format
 * @todo Generally the creation of bulk Timeslots is currently handled by the Season/Show classes, but this should change
 * @version 20130626
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @package MyURY_Scheduler
 * @uses \Database
 * @uses \MyURY_Show
 *
 */
class MyURY_Timeslot extends MyURY_Metadata_Common {
  
  private $timeslot_id;
  private $start_time;
  private $duration;
  private $season_id;
  private $owner;
  private $timeslot_num;
  protected $credits;

  protected function __construct($timeslot_id) {
    $this->timeslot_id = $timeslot_id;
    //Init Database
    self::initDB();

    //Get the basic info about the season
    $result = self::$db->fetch_one('SELECT show_season_timeslot_id,
      show_season_id, start_time, duration, memberid,
      (SELECT array(SELECT metadata_key_id FROM schedule.timeslot_metadata
        WHERE show_season_timeslot_id=$1 AND effective_from <= NOW() AND
          (effective_to IS NULL OR effective_to >= NOW())
        ORDER BY effective_from, show_season_timeslot_id)) AS metadata_types,
      (SELECT array(SELECT metadata_value FROM schedule.timeslot_metadata
        WHERE show_season_timeslot_id=$1 AND effective_from <= NOW() AND
          (effective_to IS NULL OR effective_to >= NOW())
        ORDER BY effective_from, show_season_timeslot_id)) AS metadata,
      (SELECT COUNT(*) FROM schedule.show_season_timeslot
        WHERE show_season_id=(SELECT show_season_id FROM schedule.show_season_timeslot WHERE show_season_timeslot_id=$1)
        AND start_time<=(SELECT start_time FROM schedule.show_season_timeslot WHERE show_season_timeslot_id=$1))
      AS timeslot_num,
      (SELECT array(SELECT creditid FROM schedule.show_credit
         WHERE show_id=(
          SELECT show_id FROM schedule.show_season_timeslot
            JOIN schedule.show_season USING (show_season_id)
            WHERE show_season_timeslot_id=$1
          )
         AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to >= NOW()) AND approvedid IS NOT NULL
         ORDER BY show_credit_id)) AS credits,
      (SELECT array(SELECT credit_type_id FROM schedule.show_credit
         WHERE show_id=(
          SELECT show_id FROM schedule.show_season_timeslot
            JOIN schedule.show_season USING (show_season_id)
            WHERE show_season_timeslot_id=$1
          ) AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to >= NOW()) AND approvedid IS NOT NULL
         ORDER BY show_credit_id)) AS credit_types
      FROM schedule.show_season_timeslot WHERE show_season_timeslot_id=$1', array($timeslot_id));
    if (empty($result)) {
      //Invalid Season
      throw new MyURYException('The MyURY_Timeslot with instance ID #' . $timeslot_id . ' does not exist.');
    }

    //Deal with the easy bits
    $this->timeslot_id = (int) $result['show_season_timeslot_id'];
    $this->season_id = (int) $result['show_season_id'];
    $this->start_time = strtotime($result['start_time']);
    $this->duration = $result['duration'];
    $this->owner = User::getInstance($result['memberid']);
    $this->timeslot_num = (int) $result['timeslot_num'];

    $metadata_types = self::$db->decodeArray($result['metadata_types']);
    $metadata = self::$db->decodeArray($result['metadata']);
    //Deal with the metadata
    for ($i = 0; $i < sizeof($metadata_types); $i++) {
      if (self::isMetadataMultiple($metadata_types[$i])) {
        $this->metadata[$metadata_types[$i]][] = $metadata[$i];
      } else {
        $this->metadata[$metadata_types[$i]] = $metadata[$i];
      }
    }
    
    //Deal with the Credits arrays
    $credit_types = self::$db->decodeArray($result['credit_types']);
    $credits = self::$db->decodeArray($result['credits']);

    for ($i = 0; $i < sizeof($credits); $i++) {
      if (empty($credits[$i])) {
        continue;
      }
      $this->credits[] = array('type' => (int)$credit_types[$i], 'memberid' => $credits[$i],
          'User' => User::getInstance($credits[$i]));
    }
  }

  public function getMeta($meta_string) {
    $key = self::getMetadataKey($meta_string);
    if (isset($this->meta[$key])) {
      return $this->meta[$key];
    } else {
      return $this->getSeason()->getMeta($meta_string);
    }
  }

  public function getID() {
    return $this->timeslot_id;
  }

  public function getSeason() {
    return MyURY_Season::getInstance($this->season_id);
  }

  public function getWebpage() {
    $season = $this->getSeason();
    return 'http://ury.org.uk/show/' . $season->getShow()->getID() . '/' . $season->getSeasonNumber() . '/' . $this->getTimeslotNumber();
  }

  /**
   * Get the Timeslot number - for the first Timeslot of a Season, this is 1, for the second it's 2 etc.
   * @return int
   */
  public function getTimeslotNumber() {
    return $this->timeslot_num;
  }

  /**
   * Get the start time of the Timeslot as an integer since epoch
   * @return int
   */
  public function getStartTime() {
    return $this->start_time;
  }

  public function getDuration() {
    return $this->duration;
  }

  /**
   * Gets the Timeslot that is on after this.
   * @param MyURY_Timeslot $timeslot
   * @return MyURY_Timeslot|null If null, Jukebox is next.
   */
  public function getTimeslotAfter() {
    $result = self::$db->fetch_column('SELECT show_season_timeslot_id'
            . ' FROM schedule.show_season_timeslot WHERE start_time=$1', [CoreUtils::getTimestamp(
                $this->getStartTime() + $this->getDuration())]);
    if (empty($result)) {
      return null;
    } else {
      return self::getInstance($result[0]);
    }
  }

  /**
   * Sets a metadata key to the specified value.
   *
   * If any value is the same as an existing one, no action will be taken.
   * If the given key has is_multiple, then the value will be added as a new, additional key.
   * If the key does not have is_multiple, then any existing values will have effective_to
   * set to the effective_from of this value, effectively replacing the existing value.
   * This will *not* unset is_multiple values that are not in the new set.
   *
   * @param String $string_key The metadata key
   * @param mixed $value The metadata value. If key is_multiple and value is an array, will create instance
   * for value in the array.
   * @param int $effective_from UTC Time the metavalue is effective from. Default now.
   * @param int $effective_to UTC Time the metadata value is effective to. Default NULL (does not expire).
   * @param null $table No action. Used for compatibility with parent.
   * @param null $pkey No action. Used for compatibility with parent.
   */
  public function setMeta($string_key, $value, $effective_from = null, $effective_to = null, $table = null, $pkey = null) {
    return parent::setMeta($string_key, $value, $effective_from, $effective_to,
            'schedule.timeslot_metadata', 'show_season_timeslot_id');
  }

  public function toDataSource() {
    return array_merge($this->getSeason()->toDataSource(), array(
        'id' => $this->getID(),
        'timeslot_num' => $this->getTimeslotNumber(),
        'title' => $this->getMeta('title'),
        'description' => $this->getMeta('description'),
        'start_time' => CoreUtils::happyTime($this->getStartTime()),
        'duration' => $this->getDuration(),
        'rejectlink' => array(
            'display' => 'icon',
            'value' => 'trash',
            'title' => 'Cancel Episode',
            'url' => CoreUtils::makeURL('Scheduler', 'cancelEpisode', array('show_season_timeslot_id' => $this->getID())))
    ));
  }

  /**
   * Find the most messaged Timeslots
   * @param int $date If specified, only messages for timeslots since $date are counted.
   * @return array An array of 30 Timeslots that have been put through toDataSource, with the addition of a msg_count key,
   * referring to the number of messages sent to that show.
   */
  public static function getMostMessaged($date = 0) {
    $result = self::$db->fetch_all('SELECT messages.timeslotid, count(*) as msg_count FROM sis2.messages
      LEFT JOIN schedule.show_season_timeslot ON messages.timeslotid = show_season_timeslot.show_season_timeslot_id
      WHERE show_season_timeslot.start_time > $1 GROUP BY messages.timeslotid ORDER BY msg_count DESC LIMIT 30', array(CoreUtils::getTimestamp($date)));

    $top = array();
    foreach ($result as $r) {
      $show = self::getInstance($r['timeslotid'])->toDataSource();
      $show['msg_count'] = intval($r['msg_count']);
      $top[] = $show;
    }

    return $top;
  }

  /**
   * Find the most listened Timeslots
   * @param int $date If specified, only messages for timeslots since $date are counted.
   * @return array An array of 30 Timeslots that have been put through toDataSource, with the addition of a msg_count key,
   * referring to the number of messages sent to that show.
   */
  public static function getMostListened($date = 0) {
    $key = 'stats_timeslot_mostlistened';
    if (($top = self::$cache->get($key)) !== false) {
      return $top;
    }

    $result = self::$db->fetch_all('SELECT show_season_timeslot_id,
      (SELECT COUNT(*) FROM strm_log WHERE (starttime < start_time AND endtime >= start_time)
        OR (starttime >= start_time AND starttime < start_time + duration)) AS listeners
        FROM schedule.show_season_timeslot WHERE start_time > $1
        ORDER BY listeners DESC LIMIT 30', array(CoreUtils::getTimestamp($date)));

    $top = array();
    foreach ($result as $r) {
      $show = self::getInstance($r['show_season_timeslot_id'])->toDataSource();
      $show['listeners'] = intval($r['listeners']);
      $top[] = $show;
    }

    self::$cache->set($key, $top, 86400);
    return $top;
  }

  /**
   * Returns the current Timeslot on air, if there is one.
   * @param int $time Optional integer timestamp
   *
   * @return MyURY_Timeslot|null
   */
  public static function getCurrentTimeslot($time = null) {
    if ($time === null) {
      $time = time();
    }

    $result = self::$db->fetch_column('SELECT show_season_timeslot_id FROM'
            . ' schedule.show_season_timeslot WHERE start_time <= $1 AND'
            . ' start_time + duration >= $1', [CoreUtils::getTimestamp($time)]);
    
    if (empty($result)) {
      return null;
    } else {
      return MyURY_Timeslot::getInstance($result[0]);
    }
  }

  /**
   * Gets the next Timeslot to start after $time
   * @param int $time
   * @return MyURY_Timeslot
   */
  public static function getNextTimeslot($time = null) {
    $result = self::$db->fetch_column('SELECT show_season_timeslot_id FROM'
            . ' schedule.show_season_timeslot WHERE start_time >= $1'
            . ' LIMIT 1', [CoreUtils::getTimestamp($time)]);

    if (empty($result)) {
      return null;
    } else {
      return self::getInstance($result[0]);
    }
  }

  /**
   * Returns the current timeslot, and the one after it, in a simplified
   * datasource format. Mainly intended for API use.
   * @param int $time
   */
  public static function getCurrentAndNext($time = null) {
    $timeslot = self::getCurrentTimeslot($time);

    if (empty($timeslot)) {
      $next = self::getNextTimeslot($time);
      if (empty($next)) {
        //There's currently not a show on, and there never will be.
        $response = [
            'current' => ['title' => 'URY Jukebox', 'desc' => 'Non-stop Music']
        ];
      } else {
        //There's currently not a show on, but there will be.
        $response = [
            'current' => ['title' => 'URY Jukebox',
                'desc' => 'Non-stop Music',
                'end_time' => $next->getStartTime()],
            'next' => ['title' => $next->getMeta('title'),
                'desc' => $next->getMeta('description')],
            'start_time' => $next->getStartTime(),
            'end_time' => $next->getStartTime() + $next->getDuration(),
            'presenters' => $next->getPresenterString()
        ];
      }
    } else {
      //There's a show on!
      $response = ['current' => [
              'title' => $timeslot->getMeta('title'),
              'descr' => $timeslot->getMeta('description'),
              'start_time' => $timeslot->getStartTime(),
              'end_time' => $timeslot->getStartTime() + $timeslot->getDuration(),
              'presenters' => $timeslot->getPresenterString()
      ]];
      $next = $timeslot->getTimeslotAfter();
      if (empty($next)) {
        //There's not a next show, but there might be one later
        $response['next'] = ['title' => 'Jukebox',
            'desc' => 'Non-stop Music',
            'start_time' => $timeslot->getStartTime() + $timeslot->getDuration(),
            'end_time' => self::getNextTimeslot($timeslot->getStartTime() + 1)
                    ->getStartTime()
        ];
      } else {
        //There's a next show
        $response['next'] = [
            'title' => $next->getMeta('title'),
            'descr' => $next->getMeta('description'),
            'start_time' => $next->getStartTime(),
            'end_time' => $next->getStartTime() + $next->getDuration(),
            'presenters' => $next->getPresenterString()
        ];
      }
    }

    return $response;
  }

  /**
   * Deletes this Timeslot from the Schedule, and everything associated with it.
   * 
   * 
   * This is a proxy for several other methods, depending on the User and the current time:<br>
   * (1) If the User has Cancel Show Privileges, then they can remove it at any time, notifying Creditors
   * 
   * (2) If the User is a Show Credit, and there are 48 hours or more until broadcast, they can remove it,
   *     notifying the PC
   * 
   * (3) If the User is a Show Credit, and there are less than 48 hours until broadcast, they can send a request to the
   *     PC for removal, and it will be flagged as hidden from the Schedule - it will still count as a noshow unless (1) occurs
   * 
   * @param string $reason, Why the episode was cancelled.
   * 
   * @todo Make the smarter - check if it's a programming team person, in which case just do this, if it's not
   *       then if >48hrs away just do it but email programming, but <48hrs should hide it but tell prog to confirm reason
   * @todo Response codes? i.e. error/db or error/403 etc
   */
  public function cancelTimeslot($reason) {

    //Get if the User has permission to drop the episode
    if (User::getInstance()->hasAuth(AUTH_DELETESHOWS)) {
      //Yep, do an administrative drop
      $r = $this->cancelTimeslotAdmin($reason);
    }

    //Get if the User is a Creditor
    elseif ($this->getSeason()->getShow()->isCurrentUserAnOwner()) {
      //Yaay, depending on time they can do an self-service drop or cancellation request
      if ($this->getStartTime() > time() + (48 * 3600)) {
        //Self-service cancellation
        $r = $this->cancelTimeslotSelfService($reason);
      } else {
        //Emergency cancellation request
        $r = $this->cancelTimeslotRequest($reason);
      }
    } else {
      //They can't do this.
      return $r = false;
    }
    return $r;
  }

  private function cancelTimeslotAdmin($reason) {
    $r = $this->deleteTimeslot();
    if (!$r)
      return false;

    $email = "Hi #NAME, \r\n\r\n Please note that an episode of your show, " . $this->getMeta('title') .
            ' has been cancelled by our Programming Team. The affected episode was at ' . CoreUtils::happyTime($this->getStartTime());
    $email .= "\r\n\r\nReason: $reason\r\n\r\nRegards\r\nURY Programming Team";
    self::$cache->purge();

    MyURYEmail::sendEmailToUserSet($this->getSeason()->getShow()->getCreditObjects(), 'Episode of ' . $this->getMeta('title') . ' Cancelled', $email);

    return true;
  }

  private function cancelTimeslotSelfService($reason) {

    $r = $this->deleteTimeslot();
    if (!$r)
      return false;

    $email1 = "Hi #NAME, \r\n\r\n You have requested that an episode of " . $this->getMeta('title') .
            ' is cancelled. The affected episode was at ' . CoreUtils::happyTime($this->getStartTime());
    $email1 .= "\r\n\r\nReason: $reason\r\n\r\nRegards\r\nURY Scheduler Robot";

    $email2 = $this->getMeta('title') . ' on ' . CoreUtils::happyTime($this->getStartTime()) . ' was cancelled by a presenter because ' . $reason;
    $email2 .= "\r\n\r\nIt was cancelled automatically as more than required notice was given.";

    MyURYEmail::sendEmailToUserSet($this->getSeason()->getShow()->getCreditObjects(), 'Episode of ' . $this->getMeta('title') . ' Cancelled', $email1);
    MyURYEmail::sendEmailToList(MyURY_List::getByName('programming'), 'Episode of ' . $this->getMeta('title') . ' Cancelled', $email2);

    return true;
  }

  private function cancelTimeslotRequest($reason) {
    $email = $this->getMeta('title') . ' on ' . CoreUtils::happyTime($this->getStartTime()) . ' has requested cancellation because ' . $reason;
    $email .= "\r\n\r\nDue to the short notice, it has been passed to you for consideration. To cancel the timeslot, visit ";
    $email .= CoreUtils::makeURL('Scheduler', 'cancelEpisode', array('show_season_timeslot_id' => $this->getID(), 'reason' => base64_encode($reason)));

    MyURYEmail::sendEmailToList(MyURY_List::getByName('programming'), 'Show Cancellation Request', $email);

    return true;
  }

  /**
   * Deletes the timeslot. Nothing else. See the cancelTimeslot... methods for recommended removal usage.
   * @return bool success/fail
   */
  private function deleteTimeslot() {
    $r = self::$db->query('DELETE FROM schedule.show_season_timeslot WHERE show_season_timeslot_id=$1', array($this->getID()));

    /**
     * @todo This is massively overkill, isn't it?
     */
    $m = new Memcached();
    $m->addServer(Config::$django_cache_server, 11211);
    $m->flush();

    return $r;
  }

  /**
   * This is the server-side implementation of the JSONON system for tracking Show Planner alterations
   * @param array $set A JSONON operation set
   */
  public function updateShowPlan($set) {
    $result = array();
    //Being a Database Transaction - this all succeeds, or none of it does
    self::$db->query('BEGIN');

    foreach ($set['ops'] as $op) {
      switch ($op['op']) {
        case 'AddItem':
          try {
            //Is this a record or a manageditem?
            $parts = explode('-', $op['id']);
            if ($parts[0] === 'ManagedDB') {
              //This is a managed item
              $i = NIPSWeb_TimeslotItem::create_managed($this->getID(), $parts[1], $op['channel'], $op['weight']);
            } else {
              //This is a rec database track
              $i = NIPSWeb_TimeslotItem::create_central($this->getID(), $parts[1], $op['channel'], $op['weight']);
            }
          } catch (MyURYException $e) {
            $result[] = array('status' => false);
            self::$db->query('ROLLBACK');
            return $result;
          }

          $result[] = array('status' => true, 'timeslotitemid' => $i->getID());
          break;

        case 'MoveItem':
          $i = NIPSWeb_TimeslotItem::getInstance($op['timeslotitemid']);
          if ($i->getChannel() != $op['oldchannel'] or $i->getWeight() != $op['oldweight']) {
            $result[] = array('status' => false);
            self::$db->query('ROLLBACK');
            return $result;
          } else {
            $i->setLocation($op['channel'], $op['weight']);
            $result[] = array('status' => true);
          }
          break;

        case 'RemoveItem':
          $i = NIPSWeb_TimeslotItem::getInstance($op['timeslotitemid']);
          if ($i->getChannel() != $op['channel'] or $i->getWeight() != $op['weight']) {
            $result[] = array('status' => false);
            self::$db->query('ROLLBACK');
            return $result;
          } else {
            $i->remove();
            $result[] = array('status' => true);
          }
          break;
      }
    }

    self::$db->query('INSERT INTO bapsplanner.timeslot_change_ops (client_id, change_ops)
      VALUES ($1, $2)', array($set['clientid'], json_encode($set['ops'])));

    self::$db->query('COMMIT');

    //Update the legacy baps show plans database
    $this->updateLegacyShowPlan();

    return $result;
  }

  private function updateLegacyShowPlan() {
    NIPSWeb_BAPSUtils::saveListingsForTimeslot($this);
  }

  /**
   * Returns the tracks etc. and their associated channels as planned for this show. Mainly used by NIPSWeb
   */
  public function getShowPlan() {
    /**
     * Find out if there's a NIPSWeb Schema listing for this timeslot.
     * If not, throw back an empty array
     */
    $r = self::$db->query('SELECT timeslot_item_id, channel_id FROM bapsplanner.timeslot_items WHERE timeslot_id=$1
      ORDER BY weight ASC', array($this->getID()));

    if (!$r or pg_num_rows($r) === 0) {
      //No show planned yet
      return array();
    } else {
      $tracks = array();
      foreach (self::$db->fetch_all($r) as $track) {
        $tracks[$track['channel_id']][] = NIPSWeb_TimeslotItem::getInstance($track['timeslot_item_id'])->toDataSource();
      }

      return $tracks;
    }
  }

}
