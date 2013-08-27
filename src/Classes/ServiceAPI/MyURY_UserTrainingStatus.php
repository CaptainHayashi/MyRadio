<?php
/**
 * Provides the MyURY_UserTrainingStatus class for MyURY
 * @package MyURY_Core
 */

/**
 * The UserTrainingStatus class links TrainingStatuses to Users.
 * 
 * This class does not bother with a singleton store - only Users should initialise it anyway.
 * 
 * Historically, and databasically (that's a word now), Training Statuses have been
 * referred to as Presenter Statuses. With the increasing removal detachment from
 * just "presenter" training, and more towards any activity, "Training Status"
 * was adopted.
 * 
 * @version 20130810
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @package MyURY_Core
 */

class MyURY_UserTrainingStatus extends MyURY_TrainingStatus {
  
  /**
   * The singleton store for UserTrainingStatus objects
   * @var MyURY_UserTrainingStatus[]
   */
  private static $uts = array();

  /**
   * The ID of the UserPresenterStatus
   * 
   * @var int
   */
  private $memberpresenterstatusid;
  
  /**
   * The User the TrainingStatus was awarded to.
   * @var int
   */
  private $user;
  
  /**
   * The timestamp the UserTrainingStatus was Awarded
   * @var int
   */
  private $awarded_time;
  
  /**
   * The memberid of the User that granted this UserTrainingStatus
   * @var int
   */
  private $awarded_by;
  
  /**
   * The timestamp the UserTrainingStatus was Revoked (null if still active)
   * @var int
   */
  private $revoked_time;
  
  /**
   * The memberid of the User that revoked this UserTrainingStatus
   * @var int
   */
  private $revoked_by;
  
  /**
   * Create a new UserTrainingStatus object.
   * 
   * @param int $statusid The ID of the UserTrainingStatus.
   * @throws MyURYException
   */
  protected function __construct($statusid) {
    $this->memberpresenterstatusid = (int)$statusid;
    
    $result = self::$db->fetch_one('SELECT * FROM public.member_presenterstatus
      WHERE memberpresenterstatusid=$1', array($statusid));
    
    if (empty($result)) {
      throw new MyURYException('The specified UserTrainingStatus ('.$statusid.') does not seem to exist');
    }
    
    $this->user = (int)$result['memberid'];
    $this->awarded_time = strtotime($result['completeddate']);
    $this->awarded_by = (int)$result['confirmedby'];
    $this->revoked_time = strtotime($result['revokedtime']);
    $this->revoked_by = (int)$result['revokedby'];
    
    parent::__construct($result['presenterstatusid']);
  }

  /**
   * Get an Object for the given Training Status ID, initialising it if necessary.
   * 
   * @param int $statusid
   * @return MyURY_UserTrainingStatus
   * @throws MyURYException
   */
  public static function getInstance($statusid = -1) {
    self::wakeup();
    if (!is_numeric($statusid)) {
      throw new MyURYException('Invalid User Training Status ID! ('.$statusid.')', 400);
    }

    if (!isset(self::$uts[$statusid])) {
      self::$uts[$statusid] = new self($statusid);
    }

    return self::$uts[$statusid];
  }
  
  /**
   * Get the memberpresenterstatusid
   * @return int
   */
  public function getUserTrainingStatusID() {
    return $this->memberpresenterstatusid;
  }
  
  /**
   * Get the User that Awarded this Training Status
   * @return User
   */
  public function getAwardedBy() {
    return User::getInstance($this->awarded_by);
  }
  
  /**
   * Get the User that was Awarded this Training Status
   * @return User
   */
  public function getAwardedTo($id = false) {
    return $id ? $this->user : User::getInstance($this->user);
  }
  
  /**
   * Get the time the User was Awarded this Training Status
   * @return int
   */
  public function getAwardedTime() {
    return $this->awarded_time;
  }
  
  /**
   * Get the User that Revoked this Training Status
   * @return User|null
   */
  public function getRevokedBy() {
    return empty($this->revoked_by) ? null : User::getInstance($this->revoked_by);
  }
  
  /**
   * Get the time the User had this Training Status Revoked
   * @return int
   */
  public function getRevokedTime() {
    return $this->revoked_time;
  }
  
  /**
   * Get an array of properties for this UserTrainingStatus.
   * 
   * @return Array
   */
  public function toDataSource($full = true) {
    $data = parent::toDataSource();
    $data['user_status_id'] = $this->getUserTrainingStatusID();
    $data['awarded_by'] = $this->getAwardedBy()->toDataSource($full);
    $data['awarded_time'] = $this->getAwardedTime();
    $data['revoked_by'] = ($this->getRevokedBy() === null ? null : 
            $this->getRevokedBy()->toDataSource($full));
    $data['revoked_time'] = $this->getRevokedTime();
    return $data;
  }
  
  /**
   * Creates a new User - Training Status map, awarding that User the training status.
   * 
   * @param MyURY_TrainingStatus $status The status to be awarded
   * @param User $awarded_to The User to be awarded the training status
   * @param User $awarded_by The User that is granting the training status
   * @return \self
   * @throws MyURYException
   */
  public static function create(MyURY_TrainingStatus $status, User $awarded_to,
          User $awarded_by = null) {
    //Does the User already have this?
    foreach ($awarded_to->getAllTraining(true) as $training) {
      if ($training->getID() === $status->getID()) {
        return $training;
      }
    }
    
    if ($awarded_by === null) {
      $awarded_by = User::getInstance();
    }
    
    //Check whether this user can do that.
    if (in_array(array_map(function($x){return $x->getID();}, $awarded_by->getAllTraining(true)),
            $status->getAwarder()->getID()) === false) {
      throw new MyURYException($awarded_by .' does not have permission to award '.$status);
    }
    //Check whether the target user has the prerequisites
    if ($status->getDepends() !== null and in_array($status->getDepends()->getID(),
            array_map(function($x){return $x->getID();}, $awarded_to->getAllTraining(true))) === false) {
      throw new MyURYException($awarded_to .' does not have the prerequisite training to be awarded '.$status);
    }
    
    $id = self::$db->fetch_column('INSERT INTO public.member_presenterstatus '
            . '(memberid, presenterstatusid, confirmedby) VALUES'
            . '($1, $2, $3) RETURNING memberpresenterstatusid', [
                $awarded_to->getID(),
                $status->getID(),
                $awarded_by->getID()
            ])[0];
    
    //Force the User to be updated on next request.
    self::$cache->delete(User::getCacheKey($awarded_to->getID()));
    
    return new self($id);
  }

}
