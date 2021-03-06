<?php

/**
 * Ajax handler for Timelord
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 20130905
 * @package MyRadio_Timelord
 */
$sel = new MyRadio_Selector();
$data = [
    'selector' => $sel->query(),
    'shows' => MyRadio_Timeslot::getCurrentAndNext(null, 2),
    'breaking' => MyRadioNews::getLatestNewsItem(3),
    'ob' => MyRadio_Selector::remoteStreams(),
    'silence' => $sel->isSilence(),
    'obit' => $sel->isObitHappening()
];

require 'Views/MyRadio/datatojson.php';