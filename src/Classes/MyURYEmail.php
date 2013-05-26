<?php

/**
 * This file provides the MyURYError class for MyURY
 * @package MyURY_Core
 */

/**
 * Provides email functions so that MyURY can send email.
 * 
 * @author Andy Durant <aj@ury.org.uk>
 * @version 20130525
 * @package MyURY_Core
 */
class MyURYEmail {

  private static $db;
  // Defaults
  private static $headers = 'Content-type: text/plain; charset=utf-8';
  private static $sender = 'From: MyURY <no-reply@ury.org.uk>';
  private static $footer = 'This email was sent automatically from MyURY. You can opt out of URY Emails by visiting https://ury.york.ac.uk/members/memberadmin/edit.php.';
  // Standard
  /**
   * @var string carriage return + newline
   */
  private static $rtnl = "\r\n";
  private $email_id;
  private $r_lists;
  private $r_users;
  private $subject;
  private $body;
  private $from;
  private $timestamp;

  public function __construct($eid) {
    self::$db = Database::getInstance();

    $info = self::$db->fetch_all('SELECT * FROM mail.email WHERE email_id=$1', array($eid));

    $this->subject = $info['subject'];
    $this->body = $info['body'];
    $this->from = (empty($info['from']) ? null : User::getInstance($info['from']));
    $this->timestamp = strtotime($info['timestamp']);
    $this->email_id = $eid;

    $users = self::$db->fetch_column('SELECT memberid FROM mail.email_recipient_user WHERE email_id=$1', array($eid));
    foreach ($users as $user) {
      $this->r_users[] = User::getInstance($user);
    }

    $lists = self::$db->fetch_all('SELECT listid FROM mail.email_recipient_list WHERE email_id=$1
      LEFT JOIN public.mail_list ON email_recipient_list.listid = mail_list.listid', array($eid));
    foreach ($lists as $list) {
      $this->r_lists[] = MyURY_List::getInstance($list);
    }
  }

  /**
   * Create a new email
   * @param User $from The User who sent the email. If null, uses myury@ury.org.uk
   * @param array $to A 2D array of 'lists' = [l1, l2], 'members' = [m1, m2]
   * @param String $subject email subject
   * @param String $body email body
   * @param int $timestamp Send time. If null, use now.
   */
  public static function create($to, $subject, $body, $from = null, $timestamp = null) {
    self::$db = Database::getInstance();

    $params = array($subject, $body);
    if ($timestamp !== null)
      $params[] = CoreUtils::getTimestamp($timestamp);
    if ($from !== null)
      $params[] = $from->getID();

    $eid = $db->fetch_column('INSERT INTO mail.email (subject, body'
            . ($timestamp !== null ? ', timestamp' : '') . ($from !== null ? ', sender' : '') . ')
              VALUES ($1, $2' . ($timestamp !== null or $from !== null ? ', $3' : '')
            . ($timestamp !== null && $from !== null ? ', $4' : '') . ') RETURNING email_id'
            , $params);

    $eid = $eid[0];

    foreach ($to['lists'] as $list) {
      if (is_object($list))
        $list = $list->getID();
      $db->query('INSERT INTO mail.email_recipient_list (email_id, list_id) VALUES ($1, $2)', array($eid, $listid));
    }
    foreach ($to['members'] as $member) {
      if (is_object($member))
        $member = $member->getID();
      $db->query('INSERT INTO mail.email_recipient_member (email_id, member_id) VALUES ($1, $2)', array($eid, $member));
    }

    return new self($eid);
  }

  /**
   * 
   * @return string default headers for sending email - Plain text and sent from no-reply
   */
  private static function getDefaultHeader() {
    return self::$headers . self::$rtnl . self::$sender;
  }

  /**
   * @return string The header line for From:
   */
  private static function setSender($from) {
    if (empty($from))
      return self::$headers;
    return self::$headers . self::$rtnl . 'From: ' . $from->getName() . '<' . $from->getEmail() . '>';
  }

  private static function addFooter($message) {
    return $message . self::$rtnl . self::$rtnl . self::$footer;
  }

  /**
   * Actually send the email
   */
  public function send() {
    //Don't send if it's scheduled in the future.
    if ($this->timestamp < time())
      return;
    foreach ($this->r_users as $user) {
      if (!$this->getSentToUser($user)) {
        //Don't send if the user has opted out
        if ($user->getReceiveEmail()) {
          $u_subject = str_ireplace('#NAME', $user->getFName(), $this->subject);
          $u_message = str_ireplace('#NAME', $user->getFName(), $this->body);
          mail($user->getName() . '<' . $user->getEmail() . '>', $u_subject, self::addFooter($u_message), self::setSender($this->from));
        }
        $this->setSentToUser($user);
      }
    }

    foreach ($this->r_lists as $list) {
      if (!$this->getSentToList($list)) {
        foreach ($list->getMembers() as $user) {
          //Don't send if the user has opted out
          if (!$this->getSentToUser($user)) {
            $u_subject = str_ireplace('#NAME', $user->getFName(), $this->subject);
            $u_message = str_ireplace('#NAME', $user->getFName(), $this->body);
            mail($user->getName() . '<' . $user->getEmail() . '>', $u_subject, self::addFooter($u_message), self::setSender($this->from));
          }
        }
        $this->setSentToList($user);
      }
    }

    return;
  }

  public function getSentToUser(User $user) {
    $r = self::$db->fetch_column('SELECT sent FROM email_recipient_member WHERE email_id=$1 AND memberid=$2 LIMIT 1', array($this->email_id, $user->getID()));

    return $r[0] === 't';
  }

  public function setSentToUser(User $user) {
    self::$db->query('UPDATE email_recipient_member SET sent=\'t\' WHERE email_id=$1 AND memberid=$2', array($this->email_id, $user->getID()));
  }

  public function getSentToList(MyURY_List $list) {
    $r = self::$db->fetch_column('SELECT sent FROM email_recipient_list WHERE email_id=$1 AND listid=$2 LIMIT 1', array($this->email_id, $list->getID()));

    return $r[0] === 't';
  }

  public function setSentToList(MyURY_List $list) {
    self::$db->query('UPDATE email_recipient_list SET sent=\'t\' WHERE email_id=$1 AND listid=$2', array($this->email_id, $list->getID()));
  }

  /**
   * Sends an email to the specified User
   * @param User $to
   * @param string $subject email subject
   * @param sting $message email message
   * @todo Check if "Receive Emails" is enabled for the User
   */
  public static function sendEmailToUser(User $to, $subject, $message, $from = null) {
    new self(array('members' => array($to)), $subject, $message, $from);
    return true;
  }
  
  /**
   * Sends an email to the specified MyURY_List
   * @param MyURY_List $to
   * @param string $subject email subject
   * @param sting $message email message
   * @todo Check if "Receive Emails" is enabled for the User
   */
  public static function sendEmailToList(MyURY_List $to, $subject, $message, $from = null) {
    new self(array('lists' => array($to)), $subject, $message, $from);
    return true;
  }

  /**
   * Sends an email to all the specified Users, with certain customisation abilities:
   * #NAME is replaced with the User's first name
   * 
   * @param Array $to An array of User objects
   * @param string $subject email subject
   * @param sting $message email message
   */
  public static function sendEmailToUserSet($to, $subject, $message, $from = null) {

    foreach ($to as $user) {
      if (!is_a($user, User)) {
        throw new MyURYException($user . ' is not an instance of User or a derivative!');
      }

      new self(array('members' => $to), $subject, $message, $from);
    }
  }

  /**
   * 
   * @param string $to email address or "Name <email>"
   * @param string $subject email subject
   * @param sting $message email message
   */
  public static function sendEmailFromComputing($to, $subject, $message) {
    mail($to, $subject, $message, self::setSender('URY Computing Team <computing@ury.org.uk>'));
    return TRUE;
  }

  /**
   * 
   * @param string $subject email subject
   * @param sting $message email message
   */
  public static function sendEmailToComputing($subject, $message) {
    mail("URY Computing Team <alerts.myury@ury.org.uk>", $subject, self::addFooter($message), self::getDefaultHeader());
    return TRUE;
  }

}

