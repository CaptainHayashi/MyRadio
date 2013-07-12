<?php
/**
 * List of iTones_Playlists
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130712
 * @package MyURY_iTones
 */

CoreUtils::getTemplateObject()->setTemplate('table.twig')
        ->addVariable('title', 'Campus Jukebox Playlists')
        ->addVariable('tabledata', iTones_Playlist::getAlliTonesPlaylists())
        ->addVariable('tablescript', 'myury.datatable.default.js')
        ->render();