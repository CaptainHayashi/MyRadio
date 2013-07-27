<?php

/**
 * This file provides the MyURYForm class for MyURY
 * @package MyURY_Core
 */

/**
 * Abstractor for MyURY Form Definitions
 * 
 * A MyURYForm object is used as follows
 *  
 * - A Form definition PHP file that defines the form elements and parameters
 * - A Form setter that sets the values of the form
 * - A Form getter that gets the vales of the form
 * - A Form Viewer that loads the Form definition and sets the values
 * - A Form Saver that loads the Form definiton, reads submitted values
 *   and calls getter to interpret them
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130727
 * @package MyURY_Core
 */
class MyURYForm {

  /**
   * The name of the form
   * @var String 
   */
  private $name = 'autofrm';

  /**
   * The module that it will submit to
   * Best practice is this should be the current module
   * @var String 
   */
  private $module;

  /**
   * The action that it will submit to
   * @var String 
   */
  private $action;

  /**
   * Whether to enable detailed output of what is happening (or isn't)
   * @var bool
   */
  private $debug = false;

  /**
   * Additional classes to add to the base form element
   * @var Array 
   */
  private $classes = array();

  /**
   * Whether to enable Form Validation
   * @var bool
   */
  private $validate = true;

  /**
   * Whether to use GET instead of POST 
   * @var bool
   */
  private $get = false;

  /**
   * The Twig template to use for the form. Must be form.twig or a child.
   * @var String 
   */
  private $template = 'form.twig';

  /**
   * The form fields in the form (an array of MyURYFormField objects)
   * @var Array 
   */
  private $fields = array();

  /**
   * The title of the page (the human readable name)
   * @var String
   */
  private $title = null;

  /**
   * Logging output
   * @var Array 
   */
  private $debug_log = array();

  /**
   * Fields that cannot be edited by params
   * @var Array 
   */
  private $restricted_fields = array('name', 'module', 'action', 'fields', 'restricted_fields', 'debug_log');

  /**
   * Creates a new MyURYForm object with the given parameters
   * @param string $name The name/id of the form
   * @param string $module The module the form submits to
   * @param string $action The action the form submits to
   * @param array $params One or more of the following additional settings<br>
   * debug - Verbose logging output - default false<br>
   * classes - An array of additional classes to apply to the form - default empty<br>
   * validate - Whether to validate the field input client-side - default true<br>
   * get - Whether to use the GET submission method - default false<br>
   * template - The Twig template to use for the form - default form.twig
   * 
   * @throws MyURYException Thrown on failure of a sanity check
   */
  public function __construct($name, $module, $action, $params = array()) {
    //Sanity check - does the target exist?
    if (!CoreUtils::isValidController($module, $action)) {
      throw new MyURYException('The Module/Action target of this MyURYForm is invalid.');
    }
    //Set essential parameters
    $this->name = $name;
    $this->module = $module;
    $this->action = $action;

    //Check all optional parameters
    foreach ($params as $k => $v) {
      //Sanity checks - is this a valid parameter and is it not blacklisted?
      if (isset($this->$k) === false && @$this->$k !== null)
        throw new MyURYException('Tried to set MyURYForm parameter ' . $k . ' but it does not exist.');
      if (in_array($k, $this->restricted_fields))
        throw new MyURYException('Tried to set MyURYForm parameter ' . $k . ' but it is not editable.');
      $this->$k = $v;
    }
  }

  /**
   * Changes the template to use when rendering
   * 
   * @todo Check if template exists first
   * @param String $template The path to the template, relative to Templates
   */
  public function setTemplate($template) {
    $this->template = $template;
    return $this;
  }

  /**
   * Adds a new MyURYFormField to this MyURYForm. You should initialise a new MyURYFormField and pass the object
   * straight into the parameter of this method
   * @param \MyURYFormField $field The new MyURYFormField to add to this MyURYForm
   * @return \MyURYForm Returns this MyURYForm for easy chaining
   * @throws MyURYException Thrown if there are duplicate fields with the same name
   */
  public function addField(MyURYFormField $field) {
    //Sanity check - is this name in use
    foreach ($this->fields as $f) {
      if ($f->getName() === $field->getName())
        throw new MyURYException('Tried to create a duplicate MyURYFormField ' . $f->getName());
    }
    $this->fields[] = $field;
    return $this;
  }

  /**
   * Allows you to update a MyURYFormField contained within this object with a new value to be used when rendering
   * @param String $fieldname The unique name of the MyURYFormField to edit
   * @param mixed $value The new value of the MyURYFormField. The variable type depends on the MyURYFormField type
   * @return void
   * @throws MyURYException When trying to update a MyURYFormField that is not attached to this MyURYForm
   */
  public function setFieldValue($fieldname, $value) {
    $name = explode('.', $fieldname)[0];
    foreach ($this->fields as $k => $field) {
      if ($field->getName() === $name) {
        $this->fields[$k]->setValue($value, $fieldname);
        return $this;
      }
    }
    throw new MyURYException('Cannot set value for field ' . $fieldname . ' as it does not exist.');
    return $this;
  }

  /**
   * Sets this MyURYForm as an editing form - it will take existing values and render them for editing and updating
   * @param mixed $identifier Usually a primary key, something unique that the receiving controller will use to know
   * which instance of an entry is being updated
   * @param Array $values A key=>value array of input names and their values. These will literally be sent to setFieldValue
   * iteratively
   * 
   * Note: This method should only be called once in the object's lifetime
   */
  public function editMode($identifier, $values) {
    $this->addField(new MyURYFormField('myuryfrmedid', MyURYFormField::TYPE_HIDDEN, array('value' => $identifier)));

    foreach ($values as $k => $v) {
      $this->setFieldValue($k, $v);
    }
    return $this;
  }

  /**
   * Renders a page using the template engine
   * @param Array $frmcustom An optional array of custom fields to send to the Renderer. Useful when using a custom
   * template which needs additional data.
   */
  public function render($frmcustom = array()) {
    $fields = array();
    foreach ($this->fields as $field) {
      $fields[] = $field->render();
    }
    
    $twig = CoreUtils::getTemplateObject()->setTemplate($this->template)
            ->addVariable('frm_name', $this->name)
            ->addVariable('frm_classes', $this->getClasses())
            ->addVariable('frm_action', CoreUtils::makeURL($this->module, $this->action))
            ->addVariable('frm_method', $this->get ? 'get' : 'post')
            ->addVariable('title', isset($this->title) ? $this->title : $this->name)
            ->addVariable('serviceName', isset($this->module) ? $this->module : $this->name)
            ->addVariable('frm_fields', $fields)
            ->addVariable('frm_custom', $frmcustom);

    $twig->render();
  }

  /**
   * Returns a space-seperated String of classes applying to this MyURYForm, ready to render
   * @return String a space-seperated list of classes
   */
  private function getClasses() {
    $classes = 'myuryfrm';
    foreach ($this->classes as $class) {
      $classes .= " $class";
    }

    return $classes;
  }

  /**
   * Processes data submitted from this MyURYForm, returning an Array of the values
   * @return Array An array of form data that was submitted using this form definition
   */
  public function readValues() {
    $return = array();
    foreach ($this->fields as $field) {
      $return[$field->getName()] = $field->readValue($this->name . '-');
    }
    //Edit Mode requests
    if (isset($_REQUEST[$this->name.'-myuryfrmedid'])) {
      $return['id'] = (int)$_REQUEST[$this->name.'-myuryfrmedid'];
    }
    return $return;
  }

}