<?php

/**
 * Provides the TracklistItem class for MyRadio
 * @package MyRadio_Tracklist
 */

/**
 * The Tracklist Item class provides information about URY's track playing
 * history.
 *
 * @version 20130718
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @package MyRadio_Tracklist
 * @uses \Database
 *
 */
class MyRadio_TracklistItem extends ServiceAPI
{
    private $audiologid;
    private $source;
    private $starttime;
    private $endtime;
    private $state;
    private $timeslot;
    private $bapsaudioid;

    /**
     * MyRadio_Track that was played, or an array of artist, album, track, label, length data.
     */
    private $track;

    protected function __construct($id)
    {
        $this->audiologid = (int) $id;
    
        $result = self::$db->fetch_one(
            'SELECT * FROM tracklist.tracklist
            LEFT JOIN tracklist.track_rec ON tracklist.audiologid = track_rec.audiologid
            LEFT JOIN tracklist.track_notrec ON tracklist.audiologid = track_notrec.audiologid
            WHERE tracklist.audiologid=$1 LIMIT 1',
            array($id)
        );
        if (empty($result)) {
            throw new MyRadioException('The requested TracklistItem does not appear to exist!', 400);
        }
    
        $this->source = $result['source'];
        $this->starttime = strtotime($result['timestart']);
        $this->endtime = strtotime($result['timestop']);
        $this->state = $result['state'];
        $this->timeslot = is_numeric($result['timeslotid']) ? MyRadio_Timeslot::getInstance($result['timeslotid']) : null;
        $this->bapsaudioid = is_numeric($result['bapsaudioid']) ? (int) $result['bapsaudioid'] : null;
    
        $this->track = is_numeric($result['trackid']) ? $result['trackid'] :
            array(
                'title' => $result['track'],
                'artist' => $result['artist'],
                'album' => $result['album'],
                'trackid' => null,
                'trackno' => (int) $result['trackno'],
                'length' => $result['length'],
                'record_label' => $result['label']
            );
    }

    public function getID()
    {
        return $this->audiologid;
    }

    public function getTrack()
    {
        return is_array($this->track) ? $this->track :
            MyRadio_Track::getInstance($this->track);
    }

    public function getStartTime()
    {
        return $this->starttime;
    }

    /**
     * Returns an array of all TracklistItems played during the given Timeslot
     * @param int $timeslotid
     * @return Array
     */
    public static function getTracklistForTimeslot($timeslotid, $offset = 0)
    {
        $result = self::$db->fetch_column(
            'SELECT audiologid FROM tracklist.tracklist
            WHERE timeslotid=$1
            AND (state ISNULL OR state != \'d\')
            AND audiologid > $2
            ORDER BY timestart ASC',
            array($timeslotid, $offset)
        );
    
        $items = array();
        foreach ($result as $item) {
            $items[] = self::getInstance($item);
        }
    
        return $items;
    }

    /**
     * Find all tracks played by Jukebox
     * @param int $start Period to start log from. Default 0.
     * @param int $end Period to end log from. Default time().
     * @param bool $include_playout Optional. Default true. If true, include statistics from when jukebox was not on air,
     * i.e. when it was only feeding campus bars.
     */
    public static function getTracklistForJukebox($start = null, $end = null, $include_playout = true)
    {
        self::wakeup();
    
        $start = $start === null ? '1970-01-01 00:00:00' : CoreUtils::getTimestamp($start);
        $end = $end === null ? CoreUtils::getTimestamp() : CoreUtils::getTimestamp($end);
    
        $result = self::$db->fetch_column(
            'SELECT audiologid FROM tracklist.tracklist WHERE source=\'j\'
            AND timestart >= $1 AND timestart <= $2'
            . ($include_playout ? '' : ' AND state!=\'u\' AND state!=\'d\''),
            array($start, $end)
        );
    
        $items = array();
        foreach ($result as $item) {
            $items[] = self::getInstance((int) $item);
        }
    
        return $items;
    }

    /**
     * Find all tracks played in the given timeframe, as datasources.
     * Not datasource runs out of RAM pretty quick.
     *
     * @param int $start Period to start log from. Required.
     * @param int $end Period to end log from. Default time().
     * @param bool $include_playout If true, includes tracks played on /jukebox or /campus_playout while a show was on.
     */
    public static function getTracklistForTime($start, $end = null, $include_playout = false)
    {
        self::wakeup();
    
        $start = CoreUtils::getTimestamp($start);
        $end = $end === null ? CoreUtils::getTimestamp() : CoreUtils::getTimestamp($end);
    
        $result = self::$db->fetch_column(
            'SELECT audiologid FROM tracklist.tracklist
            WHERE timestart >= $1 AND timestart <= $2 AND (state IS NULL OR state=\'c\''
            . ($include_playout ? 'OR state = \'o\')' : ')')
            . ' ORDER BY timestart ASC',
            array($start, $end)
        );
    
        $return = [];
        foreach ($result as $id) {
            if (sizeof($return) == 100000) {
                return $return;
            }
        
            $obj = self::getInstance($id);
            $data = $obj->toDataSource();
        
            unset($data['audiologid']);
            unset($data['editlink']);
            unset($data['state']);
            unset($data['type']);
            unset($data['length']);
            unset($data['clean']);
            unset($data['digitised']);
            unset($data['deletelink']);
            unset($data['trackno']);
        
            if (is_array($data['album'])) {
                $data['label'] = $data['album']['label'];
                $data['album'] = $data['album']['title'];
            } else {
                $data['label'] = $data['record_label'];
                unset($data['record_label']);
            }
        
            $return[] = $data;
            if (is_object($obj->getTrack())) {
                $obj->getTrack()->removeInstance();
            }
            $obj->removeInstance();
            unset($obj);
        }
    
        return $return;
    }

    /**
     * Takes as input a result set of num_plays and trackid, and generates the extended Datasource output used by
     * getTracklistStats(.*)()
     * @return Array, 2D, with the inner dimension being a MyRadio_Track Datasource output, with the addition of:
     * num_plays: The number of times the track was played
     * total_playtime: The total number of seconds the track has been on air
     * in_playlists: A CSV of playlists the Track is in
     */
    private static function trackAmalgamator($result)
    {
        $data = array();
        foreach ($result as $row) {
            /**
             * @todo Temporary hack due to lack of fkey on tracklist.track_rec
             */
            try {
                $trackobj = MyRadio_Track::getInstance($row['trackid']);
            } catch (MyRadioException $e) {
                continue;
            }
            $track = $trackobj->toDataSource();
            $track['num_plays'] = $row['num_plays'];
            $track['total_playtime'] = $row['num_plays'] * $trackobj->getDuration();
    
            $playlistobjs = iTones_Playlist::getPlaylistsWithTrack($trackobj);
            $track['in_playlists'] = '';
            foreach ($playlistobjs as $playlist) {
                $track['in_playlists'] .= $playlist->getTitle() . ', ';
            }
    
            $data[] = $track;
        }
    
        return $data;
    }

    /**
     * Get an amalgamation of all tracks played by Jukebox. This looks at all played tracks within the proposed timeframe,
     * and outputs the play count of each Track, including the total time played.
     * @param int $start Period to start log from. Default 0.
     * @param int $end Period to end log from. Default time().
     * @param bool $include_playout Optional. Default true. If true, include statistics from when jukebox was not on air,
     * i.e. when it was only feeding campus bars.
     * @return Array, 2D, with the inner dimension being a MyRadio_Track Datasource output, with the addition of:
     * num_plays: The number of times the track was played
     * total_playtime: The total number of seconds the track has been on air
     * in_playlists: A CSV of playlists the Track is in
     */
    public static function getTracklistStatsForJukebox($start = null, $end = null, $include_playout = true)
    {
        self::wakeup();
    
        $start = $start === null ? '1970-01-01 00:00:00' : CoreUtils::getTimestamp($start);
        $end = $end === null ? CoreUtils::getTimestamp() : CoreUtils::getTimestamp($end);
    
        $result = self::$db->fetch_all(
            'SELECT COUNT(trackid) AS num_plays, trackid FROM tracklist.tracklist
            LEFT JOIN tracklist.track_rec ON tracklist.audiologid = track_rec.audiologid
            WHERE source=\'j\' AND timestart >= $1 AND timestart <= $2 AND trackid IS NOT NULL'
            . ($include_playout ? '' : 'AND state != \'o\'')
            . 'GROUP BY trackid ORDER BY num_plays DESC',
            array($start, $end)
        );
    
        return self::trackAmalgamator($result);
    }

    /**
     * Get an amalgamation of all tracks played by BAPS. This looks at all played tracks within the proposed timeframe,
     * and outputs the play count of each Track, including the total time played.
     * @param int $start Period to start log from. Default 0.
     * @param int $end Period to end log from. Default time().
     * @return Array, 2D, with the inner dimension being a MyRadio_Track Datasource output, with the addition of:
     * num_plays: The number of times the track was played
     * total_playtime: The total number of seconds the track has been on air
     * in_playlists: A CSV of playlists the Track is in
     */
    public static function getTracklistStatsForBAPS($start = null, $end = null)
    {
        self::wakeup();

        $start = $start === null ? '1970-01-01 00:00:00' : CoreUtils::getTimestamp($start);
        $end = $end === null ? CoreUtils::getTimestamp() : CoreUtils::getTimestamp($end);

        $result = self::$db->fetch_all(
            'SELECT COUNT(trackid) AS num_plays, trackid FROM tracklist.tracklist
            LEFT JOIN tracklist.track_rec ON tracklist.audiologid = track_rec.audiologid
            WHERE source=\'b\' AND timestart >= $1 AND timestart <= $2 AND trackid IS NOT NULL
            GROUP BY trackid ORDER BY num_plays DESC',
            array($start, $end)
        );

        return self::trackAmalgamator($result);
    }

    /**
     * Returns if the given track has been played in the last $time seconds
     *
     * @param MyRadio_Track $track
     * @param int $time Optional. Default 21600 (6 hours)
     */
    public static function getIfPlayedRecently(MyRadio_Track $track, $time = 21600)
    {
        $result = self::$db->fetch_column(
            'SELECT timestart FROM tracklist.tracklist
            LEFT JOIN tracklist.track_rec ON tracklist.audiologid = track_rec.audiologid
            WHERE timestart >= $1 AND trackid = $2',
            array(CoreUtils::getTimestamp(time() - $time), $track->getID())
        );
    
        return sizeof($result) !== 0;
    }

    /**
     * Check whether queuing the given Track for playout right now would be a
     * breach of our PPL Licence.
     *
     * The PPL Licence states that a maximum of two songs from an artist or album
     * in a two hour period may be broadcast. Any more is a breach of this licence
     * so we should really stop doing it.
     *
     * @param MyRadio_Track $track
     * @param bool $include_queue If true, will include the tracks in the iTones
     * queue.
     * @param int $time. If set, will check if playing it at $time would be a/was
     * a breach. No, this isn't magic and know the future accurately.
     * @return bool
     */
    public static function getIfAlbumArtistCompliant(MyRadio_Track $track, $include_queue = true, $time = null)
    {
        if ($time == null) {
            $time = time();
        }
        $timeout = CoreUtils::getTimestamp($time - (3600 * 2)); //Two hours ago
    
        /**
         * The title check is a hack to work around our default album
         * being URY Downloads
         */
        $result = self::$db->fetch_column(
            'SELECT COUNT(*) FROM tracklist.tracklist
            LEFT JOIN tracklist.track_rec USING (audiologid)
            LEFT JOIN (SELECT recordid, title AS album FROM public.rec_record) AS t1
            USING (recordid)
            LEFT JOIN public.rec_track USING (trackid)
            WHERE (rec_track.recordid=$1 OR rec_track.artist=$2)
            AND timestart >= $3
            AND timestart < $4
            AND album NOT ILIKE \''.Config::$short_name.' Downloads%\'',
            array(
                $track->getAlbum()->getID(),
                $track->getArtist(),
                $timeout,
                CoreUtils::getTimestamp($time)
            )
        );
    
        if ($include_queue) {
            foreach (iTones_Utils::getTracksInAllQueues() as $req) {
                if (empty($req['trackid'])) {
                    continue;
                }
                $t = MyRadio_Track::getInstance($req['trackid']);
            
                /**
                 * The title check is a hack to work around our default album
                 * being URY Downloads
                 */
                if (($t->getAlbum()->getID() == $track->getAlbum()->getID() && stristr($t->getAlbum()->getTitle(), Config::$short_name.' Downloads') === false) or $t->getArtist() === $track->getArtist()) {
                    $result[0] ++;
                }
            }
        }
    
        return ($result[0] < 2);
    }

    public function toDataSource($full = false)
    {
        if (is_array($this->track)) {
            $return = $this->track;
        } else {
            $return = $this->getTrack()->toDataSource($full);
        }
        $return['starttime'] = date('d/m/Y H:i:s', $this->getStartTime());
        //$return['endtime'] = $this->getEndTime();
        $return['state'] = $this->state;
        $return['audiologid'] = $this->audiologid;
    
        return $return;
    }
}
