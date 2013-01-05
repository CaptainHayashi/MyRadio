<?php

/**
 * A basic text field to enable users to explain why they want to cancel the episode
 * 
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @version 05012013
 * @package MyURY_Scheduler
 */

$form = (new MyURYForm('sched_cancel', $module, 'doCancelEpisode',
                array(
                    'debug' => false,
                    'title' => 'Cancel Episode'
                )
        ))->addField(
                new MyURYFormField('reason', MyURYFormField::TYPE_BLOCKTEXT,
                        array('label' => 'Please explain why this Episode should be removed from the Schedule'))
        )->addField(
                new MyURYFormField('show_season_timeslot_id', MyURYFormField::TYPE_HIDDEN,
                        array('value' => $_GET['show_season_timeslot_id'])));