<?php

/**
 * Provides the MyURY_APIKey class for MyURY
 * @package MyURY_Podcast
 */

/**
 * Podcasts. For the website.
 * 
 * Reminder: Podcasts may not include any copyrighted content. This includes
 * all songs and *beds*.
 * 
 * @version 20130815
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @package MyURY_Podcast
 * @uses \Database
 */
class MyURY_Podcast extends MyURY_Metadata_Common {

  /**
   * Singleton store.
   * @var MyURY_Podcast[]
   */
  private static $podcasts = [];

  /**
   * The Podcast's ID
   * @var int
   */
  private $podcast_id;

  /**
   * The path to the file, relative to Config::$public_media_uri
   * @var String
   */
  private $file;

  /**
   * The Time the Podcast was uploaded
   * @var int
   */
  private $submitted;

  /**
   * The ID of the User that uploaded the Podcast
   * @var int
   */
  private $memberid;

  /**
   * The ID of the User that approved the Podcast
   * @var int
   */
  private $approvedid;

  /**
   * Array of Users and their relation to the Podcast.
   * @var Array
   */
  protected $credits = array();

  /**
   * The ID of the show this is linked to, if any.
   * @var int
   */
  private $show_id;

  /**
   * Get the object for the given Podcast
   * @param int $podcast_id
   * @return MyURY_Podcast
   * @throws MyURYException
   */
  public static function getInstance($podcast_id = null) {
    self::wakeup();
    if ($podcast_id === null) {
      throw new MyURYException('Invalid Podcast ID', 400);
    }

    if (!isset(self::$podcasts[$podcast_id])) {
      self::$podcasts[$podcast_id] = new self($podcast_id);
    }

    return self::$podcasts[$podcast_id];
  }

  /**
   * Construct the API Key Object
   * @param String $key
   */
  private function __construct($podcast_id) {
    $this->podcast_id = (int) $podcast_id;

    $result = self::$db->fetch_one('SELECT file, memberid, approvedid, submitted,
      show_id,
      (SELECT array(SELECT metadata_key_id FROM uryplayer.podcast_metadata
        WHERE podcast_id=$1 AND effective_from <= NOW()
        ORDER BY effective_from, podcast_metadata_id)) AS metadata_types,
      (SELECT array(SELECT metadata_value FROM uryplayer.podcast_metadata
        WHERE podcast_id=$1 AND effective_from <= NOW()
        ORDER BY effective_from, podcast_metadata_id)) AS metadata,
      (SELECT array(SELECT metadata_value FROM uryplayer.podcast_image_metadata
        WHERE podcast_id=$1 AND effective_from <= NOW()
        ORDER BY effective_from, podcast_image_metadata_id)) AS image_metadata,
      (SELECT array(SELECT credit_type_id FROM uryplayer.podcast_credit
         WHERE podcast_id=$1 AND effective_from <= NOW()
           AND (effective_to IS NULL OR effective_to >= NOW())
           AND approvedid IS NOT NULL
         ORDER BY podcast_credit_id)) AS credit_types,
      (SELECT array(SELECT creditid FROM uryplayer.podcast_credit
         WHERE podcast_id=$1 AND effective_from <= NOW()
           AND (effective_to IS NULL OR effective_to >= NOW())
           AND approvedid IS NOT NULL
         ORDER BY podcast_credit_id)) AS credits
      FROM uryplayer.podcast
      LEFT JOIN schedule.show_podcast_link USING (podcast_id)
      WHERE podcast_id=$1', array($podcast_id));

    if (empty($result)) {
      throw new MyURYException('Podcast ' . $podcast_id, ' does not exist.', 404);
    }

    $this->file = $result['file'];
    $this->memberid = (int) $result['memberid'];
    $this->approvedid = (int) $result['approvedid'];
    $this->submitted = strtotime($result['submitted']);
    $this->show_id = (int) $result['show_id'];

    //Deal with the Credits arrays
    $credit_types = self::$db->decodeArray($result['credit_types']);
    $credits = self::$db->decodeArray($result['credits']);

    for ($i = 0; $i < sizeof($credits); $i++) {
      if (empty($credits[$i])) {
        continue;
      }
      $this->credits[] = array('type' => (int) $credit_types[$i],
          'memberid' => $credits[$i],
          'User' => User::getInstance($credits[$i]));
    }


    //Deal with the Metadata arrays
    $metadata_types = self::$db->decodeArray($result['metadata_types']);
    $metadata = self::$db->decodeArray($result['metadata']);

    for ($i = 0; $i < sizeof($metadata); $i++) {
      if (self::isMetadataMultiple($metadata_types[$i])) {
        //Multiples should be an array
        $this->metadata[$metadata_types[$i]][] = $metadata[$i];
      } else {
        $this->metadata[$metadata_types[$i]] = $metadata[$i];
      }
    }
  }

  /**
   * Get all the Podcasts that the User is Owner of Creditor of.
   * @param User $user Default current user.
   * @return MyURY_Podcast[]
   */
  public static function getPodcastsAttachedToUser(User $user = null) {
    if ($user === null) {
      $user = User::getInstance();
    }

    $r = self::$db->fetch_column('SELECT podcast_id FROM uryplayer.podcast
      WHERE memberid=$1 OR podcast_id IN
        (SELECT podcast_id FROM uryplayer.podcast_credit
          WHERE creditid=$1 AND effective_from <= NOW() AND
          (effective_to >= NOW() OR effective_to IS NULL))', [$user->getID()]);

    return self::resultSetToObjArray($r);
  }
  
  public static function getPending() {
    return self::resultSetToObjArray(self::$db->fetch_column('SELECT podcast_id '
            . 'FROM uryplayer.podcast WHERE submitted IS NULL'));
  }

  public static function getCreateForm() {
    $form = (new MyURYForm('createpodcastfrm', 'Podcast', 'doCreatePodcast',
            ['title' => 'Create Podcast']))
            ->addField(new MyURYFormField('title', MyURYFormField::TYPE_TEXT, [
                'label' => 'Title'
            ]))->addField(new MyURYFormField('description', MyURYFormField::TYPE_BLOCKTEXT, [
                'label' => 'Description'
            ]))->addField(new MyURYFormField('tags', MyURYFormField::TYPE_TEXT, [
                'label' => 'Tags',
                'explanation' => 'A set of keywords to describe your podcast '
                . 'generally, seperated with spaces.'
            ]));
    
    //Get User's shows, or all shows if they have AUTH_PODCASTANYSHOW
    //Format them into a select field format.
    $shows = array_map(function($x) {
      return ['text' => $x->getMeta('title'), 'value' => $x->getID()];
    }, User::getInstance()->hasAuth(AUTH_PODCASTANYSHOW) ? 
            MyURY_Show::getAllShows()
            : MyURY_Show::getShowsAttachedToUser());
    
    //Add an option for not attached to a show
    if (User::getInstance()->hasAuth(AUTH_STANDALONEPODCAST)) {
      $shows = array_merge([['text' => 'Standalone']], $shows);
    }
    
    $form->addField(new MyURYFormField('show', MyURYFormField::TYPE_SELECT, [
                        'options' => $shows,
                        'explanation' => 'This Podcast will be attached to the '
                                          . 'Show you select here.',
                        'label' => 'Show'
              ]))->addField(new MyURYFormField('credits', MyURYFormField::TYPE_TABULARSET, [
                'label' => 'Credits', 'options' => [
                    new MyURYFormField('member', MyURYFormField::TYPE_MEMBER, [
                        'explanation' => '',
                        'label' => 'Person'
                            ]),
                    new MyURYFormField('credittype', MyURYFormField::TYPE_SELECT, [
                        'options' => array_merge([['text' => 'Please select...',
                        'disabled' => true]], MyURY_Scheduler::getCreditTypes()),
                        'explanation' => '',
                        'label' => 'Role'
              ])]]))->addField(new MyURYFormField('file', MyURYFormField::TYPE_FILE, [
                'label' => 'Audio',
                'explanation' => 'Upload the original, high-quality audio for'
                  . ' this podcast. We\'ll publish a version optimised for the web'
                  . ' and archive the original. Max size 500MB.',
                'options' => ['progress' => true]
            ]))->addField(new MyURYFormField('terms', MyURYFormField::TYPE_CHECK, [
                'label' => 'I have read and confirm that this audio file complies'
                . ' with <a href="/wiki/Podcasting_Policy" target="_blank">'
                . 'URY\'s Podcasting Policy</a>.'
            ]));
    
    return $form;
  }
  
  /**
   * Create a new Podcast
   * @param String $title The Podcast's title
   * @param String $description The Podcast's description
   * @param Array $tags An array of String tags
   * @param String $file The local filesystem path to the Podcast file
   * @param MyURY_Show $show The show to attach the Podcast to
   * @param Array $credits Credit data. Format compatible with a credit
   * TABULARSET (see Scheduler)
   */
  public static function create($title, $description, $tags, $file,
          MyURY_Show $show = null, $credits = null) {
    
    //Get an ID for the new Podcast
    $id = (int)self::$db->fetch_column('INSERT INTO uryplayer.podcast '
            . '(memberid, approvedid, submitted) VALUES ($1, $1, NULL) '
            . 'RETURNING podcast_id', [User::getInstance()->getID()])[0];
    
    $podcast = self::getInstance($id);
    
    $podcast->setMeta('title', $title);
    $podcast->setMeta('description', $description);
    $podcast->setMeta('tag', $tags);
    $podcast->setCredits($credits['member'], $credits['credittype']);
    if (!empty($show)) {
      $podcast->setShow($show);
    }
    
    //Ship the file off to the archive location to be converted
    if (!move_uploaded_file($file, $podcast->getArchiveFile())) {
      throw new MyURYException('Failed to move podcast file!', 500);
    }
  }

  /**
   * Get the Podcast ID
   * @return int
   */
  public function getID() {
    return $this->podcast_id;
  }

  /**
   * Get the Show this Podcast is linked to, if there is one.
   * @return MyURY_Show
   */
  public function getShow() {
    if (!empty($this->show_id)) {
      return MyURY_Show::getInstance($this->show_id);
    } else {
      return null;
    }
  }
  
  /**
   * Returns a human-readable explanation of the Podcast's state.
   * @return String
   */
  public function getStatus() {
    if (empty($this->submitted)) {
      return 'Processing...';
    } elseif ($this->submitted > time()) {
      return 'Scheduled for publication ('.CoreUtils::happyTime($this->submitted).')';
    } else {
      return 'Published';
    }
  }
  
  /**
   * Get the file system path to where the original file is stored.
   * @return String
   */
  public function getArchiveFile() {
    return Config::$podcast_archive_path.'/'.$this->getID().'.orig';
  }
  
  /**
   * Get the file system path to where the web file should be stored
   * @return String
   */
  public function getWebFile() {
    return Config::$public_media_path.'/podcasts/MyURYPodcast'.$this->getID().'.mp3';
  }
  
  /**
   * Get the value that *should* be stored in uryplayer.podcast.file
   * @return String
   */
  public function getFile() {
    return 'podcasts/MyURYPodcast'.$this->getID().'.mp3';
  }
  
  /**
   * Set the Show this Podcast is linked to. If null, removes any link.
   * @param MyURY_Show $show
   */
  public function setShow(MyURY_Show $show) {
    self::$db->query('DELETE FROM schedule.show_podcast_link '
            . 'WHERE podcast_id=$1', [$this->getID()]);
    
    if (!empty($show)) {
      self::$db->query('INSERT INTO schedule.show_podcast_link '
              . '(show_id, podcast_id) VALUES ($1, $2)',
              [$show->getID(), $this->getID()]);
      $this->show_id = $show->getID();
    } else {
      $this->show_id = null;
    }
    
  }

  /**
   * Get data in array format
   * @param boolean $full If true, returns more data.
   * @return Array
   */
  public function toDataSource($full = true) {
    $data = array(
        'podcast_id' => $this->getID(),
        'title' => $this->getMeta('title'),
        'description' => $this->getMeta('description'),
        'status' => $this->getStatus(),
        'editlink' => array(
            'display' => 'icon',
            'value' => 'script',
            'title' => 'Edit Podcast',
            'url' => CoreUtils::makeURL('Podcast', 'editPodcast', array('podcastid' => $this->getID())))
    );

    if ($full) {
      $data['credits'] = implode(', ', $this->getCreditsNames(false));
      $data['show'] = $this->getShow() ?
              $this->getShow()->toDataSource(false) : null;
    }

    return $data;
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
   * @param null $table Used for compatibility with parent.
   * @param null $pkey Used for compatibility with parent.
   */
  public function setMeta($string_key, $value, $effective_from = null, $effective_to = null, $table = null, $pkey = null) {
    parent::setMeta($string_key, $value, $effective_from, $effective_to, 'uryplayer.podcast_metadata', 'podcast_id');
  }
  
  /**
   * Updates the list of Credits.
   * 
   * Existing credits are kept active, ones that are not in the new list are set to effective_to now,
   * and ones that are in the new list but not exist are created with effective_from now.
   * 
   * @param User[] $users An array of Users associated.
   * @param int[] $credittypes The relevant credittypeid for each User.
   */
  public function setCredits($users, $credittypes, $table = null, $pkey = null) {
    parent::setCredits($users, $credittypes, 'uryplayer.podcast_credit', 'podcast_id');
  }
  
  /**
   * Set the time that the Podcast is submitted as visible on the website.
   * @param int $time
   */
  public function setSubmitted($time) {
    $this->submitted = $time;
    self::$db->query('UPDATE uryplayer.podcast SET submitted=$1 '
            . 'WHERE podcast_id=$2',
            [CoreUtils::getTimestamp($time), $this->getID()]);
  }
  
  /**
   * Convert the Archive file to the Web format.
   * 
   * If the preferred format is changed, re-run this on every Podcast to
   * reencode them.
   */
  public function convert() {
    $tmpfile = $this->getArchiveFile();
    $dbfile = $this->getWebFile();
    shell_exec("nice -n 15 ffmpeg -i '$tmpfile' -ab 192k -f mp3 - >'{$dbfile}'");
    
    self::$db->query('UPDATE uryplayer.podcast SET file=$1 WHERE podcast_id=$2',
            [$this->getWebURI(), $this->getID()]);
    if (empty($this->submitted)) {
      $this->setSubmitted(time());
    }
  }

}