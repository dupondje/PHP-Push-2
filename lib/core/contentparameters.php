<?php
/***********************************************
* File      :   contentparameters.php
* Project   :   Z-Push
* Descr     :   Simple transportation class for
*               requested content parameters and information
*               about the containing folder
*
* Created   :   11.04.2011
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


class ContentParameters extends StateObject {
    protected $unsetdata = array(   'contentclass' => false,
                                    'folderid' => false,
                                    'windowsize' => 10,
                                    'conflict' => false,
                                    'deletesasmoves' => true,
                                    'filtertype' => false,
                                    'truncation' => false,
                                    'rtftruncation' => false,
                                    'mimesupport' => false,
                                    'conversationmode' => false
                                );

    private $synckeyChanged = false;

    /**
     * Expected magic getters and setters
     *
     * GetContentClass() + SetContentClass()
     * GetFolderId() + SetFolderId()
     * GetWindowSize() + SetWindowSize()
     * GetConflict() + SetConflict()
     * GetDeletesAsMoves() + SetDeletesAsMoves()
     * GetFilterType() + SetFilterType()
     * GetTruncation() + SetTruncation
     * GetRTFTruncation() + SetRTFTruncation()
     * GetMimeSupport () + SetMimeSupport()
     * GetMimeTruncation() + SetMimeTruncation()
     * GetConversationMode() + SetConversationMode()
     */

    /**
     * SyncKey methods
     *
     * The current and next synckey is saved as uuid and counter in the CPO
     * so partial and ping can access the latest states.
     */

    /**
     * Returns the latest SyncKey of this folder
     *
     * @access public
     * @return string/boolean       false if no uuid/counter available
     */
    public function GetSyncKey() {
        if (isset($this->uuid) && isset($this->uuidCounter))
            return StateManager::BuildStateKey($this->uuid, $this->uuidCounter);

        return false;
    }

    /**
     * Sets the the current synckey.
     * This is done by parsing it and saving uuid and counter.
     * By setting the current key, the "next" key is obsolete
     *
     * @param string    $synckey
     *
     * @access public
     * @return boolean
     */
    public function SetSyncKey($synckey) {
        list($this->uuid, $this->uuidCounter) = StateManager::ParseStateKey($synckey);

        // remove newSyncKey
        unset($this->uuidNewCounter);

        return true;
    }

    /**
     * Indicates if this folder has a synckey
     *
     * @access public
     * @return booleans
     */
    public function HasSyncKey() {
        return (isset($this->uuid) && isset($this->uuidCounter));
    }

    /**
     * Sets the the next synckey.
     * This is done by parsing it and saving uuid and next counter.
     * if the folder has no synckey until now (new sync), the next counter becomes current asl well.
     *
     * @param string    $synckey
     *
     * @access public
     * @throws FatalException       if the uuids of current and next do not match
     * @return boolean
     */
    public function SetNewSyncKey($synckey) {
        list($uuid, $uuidNewCounter) = StateManager::ParseStateKey($synckey);
        if (!$this->HasSyncKey()) {
            $this->uuid = $uuid;
            $this->uuidCounter = $uuidNewCounter;
        }
        else if ($uuid !== $this->uuid)
            throw new FatalException("ContentParameters->SetNewSyncKey(): new SyncKey must have the same UUID as current SyncKey");

        $this->uuidNewCounter = $uuidNewCounter;
        $this->synckeyChanged = true;
    }

    /**
     * Returns the next synckey
     *
     * @access public
     * @return string/boolean       returns false if uuid or counter are not available
     */
    public function GetNewSyncKey() {
        if (isset($this->uuid) && isset($this->uuidNewCounter))
            return StateManager::BuildStateKey($this->uuid, $this->uuidNewCounter);

        return false;
    }

    /**
     * Indicates if the folder has a next synckey
     *
     * @access public
     * @return boolean
     */
    public function HasNewSyncKey() {
        return (isset($this->uuid) && isset($this->uuidNewCounter));
    }

    /**
     * Return the latest synckey.
     * When this is called the new key becomes the current key (if a new key is available).
     * The current key is then returned.
     *
     * @access public
     * @return string
     */
    public function GetLatestSyncKey() {
        // New becomes old
        if ($this->HasUuidNewCounter()) {
            $this->uuidCounter = $this->uuidNewCounter;
            unset($this->uuidNewCounter);
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ContentParameters->GetLastestSyncKey(): '%s'", $this->GetSyncKey()));
        return $this->GetSyncKey();
    }

    /**
     * Removes the saved SyncKey of this folder
     *
     * @access public
     * @return boolean
     */
    public function RemoveSyncKey() {
        if (isset($this->uuid))
            unset($this->uuid);

        if (isset($this->uuidCounter))
            unset($this->uuidCounter);

        if (isset($this->uuidNewCounter))
            unset($this->uuidNewCounter);

        ZLog::Write(LOGLEVEL_DEBUG, "ContentParameters->RemoveSyncKey(): saved sync key removed");
        return true;
    }

    /**
     * Instantiates/returns the bodypreference object for a type
     *
     * @param int   $type
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function BodyPreference($type) {
        if (!isset($this->bodypref))
            $this->bodypref = array();

        if (isset($this->bodypref[$type]))
            return $this->bodypref[$type];
        else {
            $asb = new BodyPreference();
            $arr = (array)$this->bodypref;
            $arr[$type] = $asb;
            $this->bodypref = $arr;
            return $asb;
        }
    }

    /**
     * Returns available body preference objects
     *
     *  @access public
     *  @return array/boolean       returns false if the client's body preference is not available
     */
    public function GetBodyPreference() {
        if (!isset($this->bodypref) || !(is_array($this->bodypref) || empty($this->bodypref))) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ContentParameters->GetBodyPreference(): bodypref is empty or not set"));
            return false;
        }
        return array_keys($this->bodypref);
    }

    /**
     * Called before the StateObject is serialized
     *
     * @access protected
     * @return boolean
     */
    protected function preSerialize() {
        parent::preSerialize();

        if ($this->changed === true && $this->synckeyChanged)
            $this->lastsynctime = time();

        return true;
    }
}


class BodyPreference extends StateObject {
    protected $unsetdata = array(   'truncationsize' => false,
                                    'allornone' => false,
                                    'preview' => false,
                                );

    /**
     * expected magic getters and setters
     *
     * GetTruncationSize() + SetTruncationSize()
     * GetAllOrNone() + SetAllOrNone()
     * GetPreview() + SetPreview()
     */

    /**
     * Indicates if this object has values
     *
     * @access public
     * @return boolean
     */
    public function HasValues() {
        return (count($this->data) > 0);
    }
}
?>