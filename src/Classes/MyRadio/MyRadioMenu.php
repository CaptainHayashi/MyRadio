<?php

/**
 * This file provides the MyRadioMenu class for MyRadio
 * @package MyRadio_Core
 */

/**
 * Abstractor for the MyRadio Menu
 *
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130930
 * @package MyRadio_Core
 * @uses \CacheProvider
 * @uses \Database
 * @uses \CoreUtils
 */
class MyRadioMenu
{
    /**
     * Returns a customised MyRadio menu for the *currently logged in* user
     * @param  \MyRadio_User $user The currently logged in User's User object
     * @return Array         A complex Menu array array array array array
     */
    public function getMenuForUser(MyRadio_User $user)
    {
        $full = $this->getFullMenu();

        //Iterate over the Full Menu, creating a user menu
        $menu = array();
        foreach ($full as $column) {
            $newColumn = array('title' => $column['title'], 'sections' => array());

            foreach ($column['sections'] as $section) {
                $items = array();
                foreach ($section['items'] as $item) {
                    if ($this->userHasPermission($item)) {
                        $items[] = $item;
                    }
                }
                //Add this section (if it has anything in it)
                if (!empty($items)) {
                    $newColumn['sections'][] = array('title' => $section['title'], 'items' => $items);
                }
            }

            if (!empty($newColumn['sections'])) {
                $menu[] = $newColumn;
            }
        }

        return $menu;
    }

    /**
     * Parses and returns the menu configuration file.
     *
     * @return array The array of menu configuration.
     */
    private function getMenuConfig()
    {
        return yaml_parse_file(Config::$menu_config_file_path);
    }

    /**
     * Returns the set of menu columns currently in use.
     *
     * @param array $config The raw configuration array.
     * @return array The array of column names.
     */
    private function flattenMenuConfig(array $config)
    {
        $columns = [];

        foreach ($config['columns'] as $title => $column) {
            $columns[] = [
                "title" => $title,
                "sections" => $this->getSectionsFromConfig($config, $column)
            ];
        }

        return $columns;
    }

    /**
     * Returns the set of menu sections in a column.
     *
     * @param array $config The menu configuration.
     * @param array $column The column configuration.
     * @return array The array of sections.
     */
    private function getSectionsFromConfig(array $config, array $column)
    {
        $sections = [];

        foreach ($column as $title => $section) {
            $sections[] = [
                "title" => $title,
                "items" => $this->getItemsFromConfig($config, $section)
            ];
        }

        return $sections;
    }

    /**
     * Returns the set of menu entries in a section.
     *
     * @param array $config The menu configuration.
     * @param array $section The section configuration array.
     * @return array The array of sections.
     */
    function getItemsFromConfig(array $config, array $section)
    {
        $items = [];

        foreach ($section as $title) {
            $item = $config['items'][$title];
            $item['title'] = $title;
            if (empty($item['template'])) {
                $item = array_merge($item, $this->breakDownURL($item['url']));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Returns the entire MyRadio Main Menu structure
     * @todo Better Documentation
     */
    private function getFullMenu()
    {
        $config = $this->getMenuConfig();
        return $this->flattenMenuConfig($config);
    }

    /**
     * Gets all items for a module's submenu and puts them in an array.
     * @param  int   $moduleid The id of the module to get items for
     * @return Array An array that can be used by getSubMenuForUser() to build a submenu
     * @todo Caching here breaks submenus
     */
    private function getFullSubMenu($moduleid)
    {
        $db = Database::getInstance();

        $items = $db->fetch_all(
            'SELECT menumoduleid, title, url, description FROM myury.menu_module
            WHERE moduleid=$1 ORDER BY title ASC',
            array($moduleid)
        );
        //Get permissions for each $item
        foreach ($items as $key => $item) {
            $items[$key] = array_merge($items[$key], $this->breakDownURL($item['url']));
        }

        return $items;
    }

    /**
     * Takes a $url database column entry, and breaks it into its components
     * @param  String $url A database-fetched menu item URL
     * @return Array  with four keys - 'url', 'module', 'action'. All are the String names, not IDs.
     */
    private function breakDownURL($url)
    {
        return array(
            'url' => $this->parseURL($url),
            'module' => $this->parseURL($url, 'module'),
            'action' => $this->parseURL($url, 'action')
        );
    }

    /**
     * Check if user has permission to see this menu item
     * @param  Array   $item A MyRadioMenu Menu Item to check permissions for. Should have been passed through
     *                       breadDownURL() previously.
     * @return boolean Whether the user can see this item
     */
    private function userHasPermission($item)
    {
        return empty($item['action']) or
            CoreUtils::requirePermissionAuto($item['module'], $item['action'], false);
    }

    /**
     * @todo Document
     * @param  type          $moduleid
     * @param  \MyRadio_User $user     The currently logged in User's User object
     * @return array
     */
    public function getSubMenuForUser($moduleid, MyRadio_User $user)
    {
        $full = $this->getFullSubMenu($moduleid);

        //Iterate over the Full Menu, creating a user menu
        $menu = array();
        foreach ($full as $item) {
            if ($this->userHasPermission($item)) {
                $menu[] = $item;
            }
        }

        return $menu;
    }

    /**
     * Detects module/action links and rewrites
     * This is a method so it can easily be changed if Apache rewrites
     * @param String $url The URL to parse
     * @todo Rewrite this to make sense
     */
    private function parseURL($url, $return = 'url')
    {
        $exp = explode(',', $url);

        $module = str_replace('module=', '', $exp[0], $count);
        if ($count === 1) {
            //It can be rewritten!
            if (isset($exp[1])) {
                //An action is defined!
                $action = str_replace('action=', '', $exp[1]);
                if (isset($exp[2])) {
                    //An additional query string
                    //This could be multiple variables separated by &
                    $params = $exp[2];
                } else {
                    $params = null;
                }
            } else {
                $action = null;
                $params = null;
            }
        } else {
            //It's not a rewritable
            if ($return !== 'url') {
                return null;
            }
        }
        if ($return === 'module') {
            return $module;
        } elseif ($return === 'action') {
            if (isset($exp[1])) {
                return str_replace('action=', '', $exp[1]);
            } else {
                return Config::$default_action;
            }
        }

        $url = $count === 1 ? CoreUtils::makeURL($module, $action, $params) : $url;

        return $url;
    }
}
