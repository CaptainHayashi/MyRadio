<?php
/**
 * The most played BAPS tracks for the given timeframe
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130708
 * @package MyURY_Stats
 */
require 'Views/bootstrap.php';

$start = isset($_GET['rangesel-starttime']) ? strtotime($_GET['rangesel-starttime']) : time()-(86400*28);
$end = isset($_GET['rangesel-endtime']) ? strtotime($_GET['rangesel-endtime']) : time();

$twig->setTemplate('table_timeinput.twig')
        ->addVariable('title', 'BAPS Track Statistics')
        ->addVariable('heading', 'BAPS Track Statistics')
        ->addVariable('tabledata', MyURY_TracklistItem::getTracklistStatsForBAPS($start, $end))
        ->addVariable('tablescript', 'myury.stats.jukeboxplaycounter')
        ->addVariable('starttime', CoreUtils::happyTime($start))
        ->addVariable('endtime', CoreUtils::happyTime($end))
        ->render();