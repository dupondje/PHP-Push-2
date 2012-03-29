<?php
/***********************************************
* File      :   loopdetection.php
* Project   :   Z-Push
* Descr     :   detects an outgoing loop by looking
*               if subsequent requests do try to get changes
*               for the same sync key. If more than once a synckey
*               is requested, the amount of items to be sent to the mobile
*               is reduced to one. If then (again) the same synckey is
*               requested, we have most probably found the 'broken' item.
*
* Created   :   20.10.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/


class LoopDetection extends InterProcessData {
    private $ignore_next_streamed_message;

    /**
     * Constructor
     *
     * @access public
     */
    public function LoopDetection() {
        // initialize super parameters
        $this->allocate = 204800; // 200 KB
        $this->type = 1337;
        parent::__construct();

        $this->ignore_next_streamed_message = false;
    }

    /**
     * Loop detection mechanism
     *
     *    1. request counter is higher than the previous counter (somehow default)
     *      1.1)   standard situation                                   -> do nothing
     *      1.2)   loop information exists
     *      1.2.1) request counter < maxCounter AND no ignored data     -> continue in loop mode
     *      1.2.2) request counter < maxCounter AND ignored data        -> we have already encountered issue, return to normal
     *
     *    2. request counter is the same as the previous, but no data was sent on the last request (standard situation)
     *
     *    3. request counter is the same as the previous and last time objects were sent (loop!)
     *      3.1)   no loop was detected before, entereing loop mode     -> save loop data, loopcount = 1
     *      3.2)   loop was detected before, but are gone               -> loop resolved
     *      3.3)   loop was detected before, continuing in loop mode    -> this is probably the broken element,loopcount++,
     *      3.3.1) item identified, loopcount >= 3                      -> ignore item, set ignoredata flag
     *
     * @param string $folderid          the current folder id to be worked on
     * @param string $type              the type of that folder (Email, Calendar, Contact, Task)
     * @param string $uuid              the synkkey
     * @param string $counter           the synckey counter
     * @param string $maxItems          the current amount of items to be sent to the mobile
     * @param string $queuedMessages    the amount of messages which were found by the exporter
     *
     * @access public
     * @return boolean      when returning true if a loop has been identified
     */
    public function Detect($folderid, $type, $uuid, $counter, $maxItems, $queuedMessages) {
        // if an incoming loop is already detected, do nothing
        if ($maxItems === 0 && $queuedMessages > 0) {
            ZPush::GetTopCollector()->AnnounceInformation("Incoming loop!", true);
            return true;
        }

        // initialize params
        $this->InitializeParams();

        $loop = false;

        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, $folderid);

            $current = $loopdata[self::$devid][self::$user][$folderid];

            // completely new/unknown UUID
            if (empty($current))
                $current = array("type" => $type, "uuid" => $uuid, "count" => $counter-1, "queued" => $queuedMessages);

            // old UUID in cache - the device requested a new state!!
            else if (isset($current['type']) && $current['type'] == $type && isset($current['uuid']) && $current['uuid'] != $uuid ) {
                ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): UUID changed for folder changed by mobile!");

                // some devices (iPhones) may request new UUIDs after broken items were sent several times
                if (isset($current['queued']) && $current['queued'] > 0 && isset($current['maxCount']) && $current['count']+1 < $current['maxCount']) {
                    ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): UUID changed and while items where sent to device - forcing loop mode");
                    $loop = true; // force loop mode
                    $current['queued'] = $queuedMessages;
                }
                else {
                    $current['queued'] = 0;
                }

                // set new data, unset old loop information
                $current["uuid"] = $uuid;
                $current['count'] = $counter;
                unset($current['loopcount']);
                unset($current['ignored']);
                unset($current['maxCount']);

            }

            // see if there are values
            if (isset($current['uuid']) && $current['uuid'] == $uuid &&
                isset($current['type']) && $current['type'] == $type &&
                isset($current['count'])) {

                // case 1 - standard, during loop-resolving & resolving
                if ($current['count'] < $counter) {

                    // case 1.1
                    $current['count'] = $counter;
                    $current['queued'] = $queuedMessages;

                    // case 1.2
                    if (isset($current['maxCount'])) {
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2 detected");

                        // case 1.2.1
                        // broken item not identified yet
                        if (!isset($current['ignored']) && $counter < $current['maxCount']) {
                            $loop = true; // continue in loop-resolving
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2.1 detected");
                        }
                        // case 1.2.2 - if there were any broken items they should be gone, return to normal
                        else {
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2.2 detected");
                            unset($current['loopcount']);
                            unset($current['ignored']);
                            unset($current['maxCount']);
                        }
                    }
                }

                // case 2 - same counter, but there were no changes before and are there now
                else if ($current['count'] == $counter && $current['queued'] == 0 && $queuedMessages > 0) {
                    $current['queued'] = $queuedMessages;
                }

                // case 3 - same counter, changes sent before, hanging loop and ignoring
                else if ($current['count'] == $counter && $current['queued'] > 0) {

                    if (!isset($current['loopcount'])) {
                        // case 3.1) we have just encountered a loop!
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.1 detected - loop detected, init loop mode");
                        $current['loopcount'] = 1;
                        $current['maxCount'] = $counter + $queuedMessages;
                        $loop = true;   // loop mode!!
                    }
                    else if ($queuedMessages == 0) {
                        // case 3.2) there was a loop before but now the changes are GONE
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.2 detected - changes gone - clearing loop data");
                        $current['queued'] = 0;
                        unset($current['loopcount']);
                        unset($current['ignored']);
                        unset($current['maxCount']);
                    }
                    else {
                        // case 3.3) still looping the same message! Increase counter
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.3 detected - in loop mode, increase loop counter");
                        $current['loopcount']++;

                        // case 3.3.1 - we got our broken item!
                        if ($current['loopcount'] >= 3) {
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.3.1 detected - broken item identified, marking to ignore it");

                            $this->ignore_next_streamed_message = true;
                            $current['ignored'] = true;
                        }
                        $current['maxCount'] = $counter + $queuedMessages;
                        $loop = true;   // loop mode!!
                    }
                }

            }
            if (isset($current['loopcount']))
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->Detect(): loop data: loopcount(%d), maxCount(%d), queued(%d), ignored(%s)", $current['loopcount'], $current['maxCount'], $current['queued'], (isset($current['ignored'])?'true':'false')));

            // update loop data
            $loopdata[self::$devid][self::$user][$folderid] = $current;
            $ok = $this->setData($loopdata);

            $this->releaseMutex();
        }
        // end exclusive block

        if ($loop == true && $this->ignore_next_streamed_message == false) {
            ZPush::GetTopCollector()->AnnounceInformation("Loop detection", true);
        }
        else if ($loop == true && $this->ignore_next_streamed_message == true) {
            ZPush::GetTopCollector()->AnnounceInformation("Broken message identified", true);
        }

        return $loop;
    }

    /**
     * Indicates if the next messages should be ignored (not be sent to the mobile!)
     *
     * @param boolean $markAsIgnored    (opt) to peek without setting the next message to be
     *                                  ignored, set this value to false
     * @access public
     * @return boolean
     */
    public function IgnoreNextMessage($markAsIgnored = true) {
        if (Request::GetCommandCode() == ZPush::COMMAND_SYNC && $this->ignore_next_streamed_message === true) {
            if ($markAsIgnored)
                $this->ignore_next_streamed_message = false;
            return true;
        }
        return false;
    }

    /**
     * Clears loop detection data
     *
     * @param string    $user           (opt) user which data should be removed - user can not be specified without
     * @param string    $devid          (opt) device id which data to be removed
     *
     * @return boolean
     * @access public
     */
    public function ClearData($user = false, $devid = false) {
        $stat = true;
        $ok = false;

        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            if ($user == false && $devid == false)
                $loopdata = array();
            elseif ($user == false && $devid != false)
                $loopdata[$devid] = array();
            elseif ($user != false && $devid != false)
                $loopdata[$devid][$user] = array();
            elseif ($user != false && $devid == false) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("Not possible to reset loop detection data for user '%s' without a specifying a device id", $user));
                $stat = false;
            }

            if ($stat)
                $ok = $this->setData($loopdata);

            $this->releaseMutex();
        }
        // end exclusive block

        return $stat && $ok;
    }

    /**
     * Returns loop detection data for a user and device
     *
     * @param string    $user
     * @param string    $devid
     *
     * @return array/boolean    returns false if data not available
     * @access public
     */
    public function GetCachedData($user, $devid) {
        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();
            $this->releaseMutex();
        }
        // end exclusive block
        if (isset($loopdata) && isset($loopdata[$devid]) && isset($loopdata[$devid][$user]))
            return $loopdata[$devid][$user];

        return false;
    }

    /**
     * Builds an array structure for the loop detection data
     *
     * @param array $loopdata    reference to the topdata array
     *
     * @access private
     * @return
     */
    private function checkArrayStructure(&$loopdata, $folderid) {
        if (!isset($loopdata) || !is_array($loopdata))
            $loopdata = array();

        if (!isset($loopdata[self::$devid]))
            $loopdata[self::$devid] = array();

        if (!isset($loopdata[self::$devid][self::$user]))
            $loopdata[self::$devid][self::$user] = array();

        if (!isset($loopdata[self::$devid][self::$user][$folderid]))
            $loopdata[self::$devid][self::$user][$folderid] = array();
    }
}

?>