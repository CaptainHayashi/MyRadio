<?php
/**
 * 
 * @todo Proper Documentation
 * @author Andy Durant <aj@ury.org.uk>
 * @version 21072012
 * @package MyURY_Podcast
 */
require 'Views/MyURY/bootstrap.php';
$twig->setTemplate('MyURY/Podcast/podcastadd.twig')
        ->addVariable('title', 'Podcast Manager - Add Podcast')
        ->addVariable('heading', 'Podcast Manager')
        ->render();
