<?php
/***********************************************
* File      :   sync.php
* Project   :   Z-Push
* Descr     :   Provides the SYNC command
*
* Created   :   16.02.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
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

class Sync extends RequestProcessor {

    /**
     * Handles the Sync command
     * Performs the synchronization of messages
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        // Contains all requested folders (containers)
        $sc = new SyncCollections();
        $status = SYNC_STATUS_SUCCESS;
        $wbxmlproblem = false;
        $emtpysync = false;

        // Start Synchronize
        if(self::$decoder->getElementStartTag(SYNC_SYNCHRONIZE)) {

            // AS 1.0 sends version information in WBXML
            if(self::$decoder->getElementStartTag(SYNC_VERSION)) {
                $sync_version = self::$decoder->getElementContent();
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("WBXML sync version: '%s'", $sync_version));
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Synching specified folders
            if(self::$decoder->getElementStartTag(SYNC_FOLDERS)) {
                while(self::$decoder->getElementStartTag(SYNC_FOLDER)) {
                    $actiondata = array();
                    $actiondata["requested"] = true;
                    $actiondata["clientids"] = array();
                    $actiondata["modifyids"] = array();
                    $actiondata["removeids"] = array();
                    $actiondata["fetchids"] = array();
                    $actiondata["statusids"] = array();

                    // read class, synckey and folderid without CPO for now
                    $class = $synckey = $folderid = false;

                    //for AS versions < 2.5
                    if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                        $class = self::$decoder->getElementContent();
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Sync folder: '%s'", $class));

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // SyncKey
                    if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                        return false;
                    $synckey = self::$decoder->getElementContent();
                    if(!self::$decoder->getElementEndTag())
                        return false;

                    // FolderId
                    if(self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
                        $folderid = self::$decoder->getElementContent();

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
                    if (! $folderid && $class) {
                        $folderid = self::$deviceManager->GetFolderIdFromCacheByClass($class);
                    }

                    // folderid HAS TO BE known by now, so we retrieve the correct CPO for an update
                    $cpo = self::$deviceManager->GetStateManager()->GetSynchedFolderState($folderid);

                    // update folderid.. this might be a new object
                    $cpo->SetFolderId($folderid);

                    if ($class !== false)
                        $cpo->SetContentClass($class);

                    // new/resync requested
                    if ($synckey == "0")
                        $cpo->RemoveSyncKey();
                    else if ($synckey !== false)
                        $cpo->SetSyncKey($synckey);

                    // Get class for as versions >= 12.0
                    if (! $cpo->HasContentClass()) {
                        try {
                            $cpo->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($cpo->GetFolderId()));
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("GetFolderClassFromCacheByID from Device Manager: '%s' for id:'%s'", $cpo->GetContentClass(), $cpo->GetFolderId()));
                        }
                        catch (NoHierarchyCacheAvailableException $nhca) {
                            $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                            self::$deviceManager->ForceFullResync();
                        }
                    }

                    // done basic CPO initialization/loading -> add to SyncCollection
                    $sc->AddCollection($cpo);
                    $sc->AddParameter($cpo, "requested", true);

                    if ($cpo->HasContentClass())
                        self::$topCollector->AnnounceInformation(sprintf("%s request", $cpo->GetContentClass()), true);
                    else
                        ZLog::Write(LOGLEVEL_WARN, "Not possible to determine class of request. Request did not contain class and apparently there is an issue with the HierarchyCache.");

                    // SUPPORTED properties
                    if(self::$decoder->getElementStartTag(SYNC_SUPPORTED)) {
                        $supfields = array();
                        while(1) {
                            $el = self::$decoder->getElement();

                            if($el[EN_TYPE] == EN_TYPE_ENDTAG)
                                break;
                            else
                                $supfields[] = $el[EN_TAG];
                        }
                        self::$deviceManager->SetSupportedFields($cpo->GetFolderId(), $supfields);
                    }

                    // Deletes as moves can be an empty tag as well as have value
                    if(self::$decoder->getElementStartTag(SYNC_DELETESASMOVES)) {
                        $cpo->SetDeletesAsMoves(true);
                        if (($dam = self::$decoder->getElementContent()) !== false) {
                            $cpo->SetDeletesAsMoves((boolean)$dam);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }

                    // Get changes can be an empty tag as well as have value
                    // code block partly contributed by dw2412
                    if(self::$decoder->getElementStartTag(SYNC_GETCHANGES)) {
                        $sc->AddParameter($cpo, "getchanges", true);
                        if (($gc = self::$decoder->getElementContent()) !== false) {
                            $sc->AddParameter($cpo, "getchanges", $gc);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }

                    if(self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                        $cpo->SetWindowSize(self::$decoder->getElementContent());

                        // also announce the currently requested window size to the DeviceManager
                        self::$deviceManager->SetWindowSize($cpo->GetFolderId(), $cpo->GetWindowSize());

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // conversation mode requested
                    if(self::$decoder->getElementStartTag(SYNC_CONVERSATIONMODE)) {
                        $cpo->SetConversationMode(true);
                        if(($conversationmode = self::$decoder->getElementContent()) !== false) {
                            $cpo->SetConversationMode((boolean)$conversationmode);
                            if(!self::$decoder->getElementEndTag())
                            return false;
                        }
                    }

                    // Do not truncate by default
                    $cpo->SetTruncation(SYNC_TRUNCATION_ALL);
                    // set to synchronize all changes. The mobile could overwrite this value
                    $cpo->SetFilterType(SYNC_FILTERTYPE_ALL);

                    if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                        while(1) {
                            if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                                $cpo->SetFilterType(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            if(self::$decoder->getElementStartTag(SYNC_TRUNCATION)) {
                                $cpo->SetTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            if(self::$decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
                                $cpo->SetRTFTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
                                $cpo->SetMimeSupport(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
                                $cpo->SetMimeTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_CONFLICT)) {
                                $cpo->SetConflict(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            while (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
                                    $bptype = self::$decoder->getElementContent();
                                    $cpo->BodyPreference($bptype);
                                    if(!self::$decoder->getElementEndTag()) {
                                        return false;
                                    }
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
                                    $cpo->BodyPreference($bptype)->SetTruncationSize(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
                                    $cpo->BodyPreference($bptype)->SetAllOrNone(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
                                    $cpo->BodyPreference($bptype)->SetPreview(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            $e = self::$decoder->peek();
                            if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                                self::$decoder->getElementEndTag();
                                break;
                            }
                        }
                    }

                    // limit items to be synchronized to the mobiles if configured
                    if (defined('SYNC_FILTERTIME_MAX') && SYNC_FILTERTIME_MAX > SYNC_FILTERTYPE_ALL &&
                        (!$cpo->HasFilterType() || $cpo->GetFilterType() > SYNC_FILTERTIME_MAX)) {
                            $cpo->SetFilterType(SYNC_FILTERTIME_MAX);
                    }

                    // set default conflict behavior from config if the device doesn't send a conflict resolution parameter
                    if (! $cpo->HasConflict()) {
                        $cpo->SetConflict(SYNC_CONFLICT_DEFAULT);
                    }

                    // Get our syncstate
                    if ($status == SYNC_STATUS_SUCCESS) {
                        try {
                            $sc->AddParameter($cpo, "state", self::$deviceManager->GetStateManager()->GetSyncState($cpo->GetSyncKey()));

                            // if this request was made before, there will be a failstate available
                            $actiondata["failstate"] = self::$deviceManager->GetStateManager()->GetSyncFailState();

                            // if this is an additional folder the backend has to be setup correctly
                            if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($cpo->GetFolderId())))
                                throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
                        }
                        catch (StateNotFoundException $snfex) {
                            $status = SYNC_STATUS_INVALIDSYNCKEY;
                            self::$topCollector->AnnounceInformation("StateNotFoundException", true);
                        }
                        catch (StatusException $stex) {
                           $status = $stex->getCode();
                           self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
                        }

                        // Check if the hierarchycache is available. If not, trigger a HierarchySync
                        if (self::$deviceManager->IsHierarchySyncRequired()) {
                            $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                            ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache is also not available. Triggering HierarchySync to device");
                        }
                    }

                    if(self::$decoder->getElementStartTag(SYNC_PERFORM)) {
                        // We can not proceed here as the content class is unknown
                        if ($status != SYNC_STATUS_SUCCESS) {
                            ZLog::Write(LOGLEVEL_WARN, "Ignoring all incoming actions as global status indicates problem.");
                            $wbxmlproblem = true;
                            break;
                        }

                        $performaction = true;

                        if ($status == SYNC_STATUS_SUCCESS) {
                            try {
                                // Configure importer with last state
                                $importer = self::$backend->GetImporter($cpo->GetFolderId());

                                // if something goes wrong, ask the mobile to resync the hierarchy
                                if ($importer === false)
                                    throw new StatusException(sprintf("HandleSync() could not get an importer for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

                                // if there is a valid state obtained after importing changes in a previous loop, we use that state
                                if ($actiondata["failstate"] && isset($actiondata["failstate"]["failedsyncstate"])) {
                                    $importer->Config($actiondata["failstate"]["failedsyncstate"], $cpo->GetConflict());
                                }
                                else
                                    $importer->Config($sc->GetParameter($cpo, "state"), $cpo->GetConflict());
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }
                        }

                        $nchanges = 0;
                        while(1) {
                            // ADD, MODIFY, REMOVE or FETCH
                            $element = self::$decoder->getElement();

                            if($element[EN_TYPE] != EN_TYPE_STARTTAG) {
                                self::$decoder->ungetElement($element);
                                break;
                            }

                            // before importing the first change, load potential conflicts
                            // for the current state

                            // TODO check if the failsyncstate applies for conflict detection as well
                            if ($status == SYNC_STATUS_SUCCESS && $nchanges == 0)
                                $importer->LoadConflicts($cpo, $sc->GetParameter($cpo, "state"));

                            if ($status == SYNC_STATUS_SUCCESS)
                                $nchanges++;

                            if(self::$decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                                $serverid = self::$decoder->getElementContent();

                                if(!self::$decoder->getElementEndTag()) // end serverid
                                    return false;
                            }
                            else
                                $serverid = false;

                            if(self::$decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
                                $clientid = self::$decoder->getElementContent();

                                if(!self::$decoder->getElementEndTag()) // end clientid
                                    return false;
                            }
                            else
                                $clientid = false;

                            // Get the SyncMessage if sent
                            if(self::$decoder->getElementStartTag(SYNC_DATA)) {
                                $message = ZPush::getSyncObjectFromFolderClass($cpo->GetContentClass());
                                $message->Decode(self::$decoder);

                                // set Ghosted fields
                                $message->emptySupported(self::$deviceManager->GetSupportedFields($cpo->GetFolderId()));
                                if(!self::$decoder->getElementEndTag()) // end applicationdata
                                    return false;
                            }

                            if ($status != SYNC_STATUS_SUCCESS) {
                                ZLog::Write(LOGLEVEL_WARN, "Ignored incoming change, global status indicates problem.");
                                continue;
                            }

                            // Detect incoming loop
                            // messages which were created/removed before will not have the same action executed again
                            // if a message is edited we perform this action "again", as the message could have been changed on the mobile in the meantime
                            $ignoreMessage = false;
                            if ($actiondata["failstate"]) {
                                // message was ADDED before, do NOT add it again
                                if ($element[EN_TAG] == SYNC_ADD && $actiondata["failstate"]["clientids"][$clientid]) {
                                    $ignoreMessage = true;

                                    // make sure no messages are sent back
                                    self::$deviceManager->SetWindowSize($cpo->GetFolderId(), 0);

                                    $actiondata["clientids"][$clientid] = $actiondata["failstate"]["clientids"][$clientid];
                                    $actiondata["statusids"][$clientid] = $actiondata["failstate"]["statusids"][$clientid];

                                    ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Incoming new message '%s' was created on the server before. Replying with known new server id: %s", $clientid, $actiondata["clientids"][$clientid]));
                                }

                                // message was REMOVED before, do NOT attemp to remove it again
                                if ($element[EN_TAG] == SYNC_REMOVE && $actiondata["failstate"]["removeids"][$serverid]) {
                                    $ignoreMessage = true;

                                    // make sure no messages are sent back
                                    self::$deviceManager->SetWindowSize($cpo->GetFolderId(), 0);

                                    $actiondata["removeids"][$serverid] = $actiondata["failstate"]["removeids"][$serverid];
                                    $actiondata["statusids"][$serverid] = $actiondata["failstate"]["statusids"][$serverid];

                                    ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Message '%s' was deleted by the mobile before. Replying with known status: %s", $clientid, $actiondata["statusids"][$serverid]));
                                }
                            }

                            if (!$ignoreMessage) {
                                switch($element[EN_TAG]) {
                                    case SYNC_MODIFY:
                                        try {
                                            $actiondata["modifyids"][] = $serverid;

                                            if (!$message->Check()) {
                                                $actiondata["statusids"][$serverid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                            }
                                            else {
                                                if(isset($message->read)) // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                                                    $importer->ImportMessageReadFlag($serverid, $message->read);
                                                elseif (!isset($message->flag))
                                                    $importer->ImportMessageChange($serverid, $message);

                                                // email todoflags - some devices send todos flags together with read flags,
                                                // so they have to be handled separately
                                                if (isset($message->flag)){
                                                    $importer->ImportMessageChange($serverid, $message);
                                                }

                                                $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                            }
                                        }
                                        catch (StatusException $stex) {
                                            $actiondata["statusids"][$serverid] = $stex->getCode();
                                        }

                                        break;
                                    case SYNC_ADD:
                                        try {
                                            if (!$message->Check()) {
                                                $actiondata["clientids"][$clientid] = false;
                                                $actiondata["statusids"][$clientid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                            }
                                            else {
                                                $actiondata["clientids"][$clientid] = false;
                                                $actiondata["clientids"][$clientid] = $importer->ImportMessageChange(false, $message);
                                                $actiondata["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
                                            }
                                        }
                                        catch (StatusException $stex) {
                                           $actiondata["statusids"][$clientid] = $stex->getCode();
                                        }
                                        break;
                                    case SYNC_REMOVE:
                                        try {
                                            $actiondata["removeids"][] = $serverid;
                                            // if message deletions are to be moved, move them
                                            if($cpo->GetDeletesAsMoves()) {
                                                $folderid = self::$backend->GetWasteBasket();

                                                if($folderid) {
                                                    $importer->ImportMessageMove($serverid, $folderid);
                                                    $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                                    break;
                                                }
                                                else
                                                    ZLog::Write(LOGLEVEL_WARN, "Message should be moved to WasteBasket, but the Backend did not return a destination ID. Message is hard deleted now!");
                                            }

                                            $importer->ImportMessageDeletion($serverid);
                                            $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                        }
                                        catch (StatusException $stex) {
                                           $actiondata["statusids"][$serverid] = $stex->getCode();
                                        }
                                        break;
                                    case SYNC_FETCH:
                                        array_push($actiondata["fetchids"], $serverid);
                                        break;
                                }
                                self::$topCollector->AnnounceInformation(sprintf("Incoming %d", $nchanges),($nchanges>0)?true:false);
                            }

                            if(!self::$decoder->getElementEndTag()) // end add/change/delete/move
                                return false;
                        }

                        if ($status == SYNC_STATUS_SUCCESS) {
                            ZLog::Write(LOGLEVEL_INFO, sprintf("Processed '%d' incoming changes", $nchanges));
                            try {
                                // Save the updated state, which is used for the exporter later
                                $sc->AddParameter($cpo, "state", $importer->GetState());
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }
                        }

                        if(!self::$decoder->getElementEndTag()) // end PERFORM
                            return false;
                    }

                    // save the failsave state
                    if (!empty($actiondata["statusids"])) {
                        unset($actiondata["failstate"]);
                        $actiondata["failedsyncstate"] = $sc->GetParameter($cpo, "state");
                        self::$deviceManager->GetStateManager()->SetSyncFailState($actiondata);
                    }

                    // save actiondata
                    $sc->AddParameter($cpo, "actiondata", $actiondata);

                    if(!self::$decoder->getElementEndTag()) // end collection
                        return false;

                    // AS14 does not send GetChanges anymore. We should do it if there were no incoming changes
                    if (!isset($performaction) && !$sc->GetParameter($cpo, "getchanges") && $cpo->HasSyncKey())
                        $sc->AddParameter($cpo, "getchanges", true);
                } // END FOLDER

                if(!$wbxmlproblem && !self::$decoder->getElementEndTag()) // end collections
                    return false;
            } // end FOLDERS

            if (self::$decoder->getElementStartTag(SYNC_HEARTBEATINTERVAL)) {
                $hbinterval = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) // SYNC_HEARTBEATINTERVAL
                    return false;
            }

            if (self::$decoder->getElementStartTag(SYNC_WAIT)) {
                $wait = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) // SYNC_WAIT
                    return false;

                // internally the heartbeat interval and the wait time are the same
                // heartbeat is in seconds, wait in minutes
                $hbinterval = $wait * 60;
            }

            if (self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                $sc->SetGlobalWindowSize(self::$decoder->getElementContent());
                if(!self::$decoder->getElementEndTag()) // SYNC_WINDOWSIZE
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_PARTIAL))
                $partial = true;
            else
                $partial = false;

            if(!$wbxmlproblem && !self::$decoder->getElementEndTag()) // end sync
                return false;
        }
        // we did not receive a SYNCHRONIZE block - assume empty sync
        else {
            $emtpysync = true;
        }
        // END SYNCHRONIZE

        // check heartbeat/wait time
        if (isset($hbinterval)) {
            if ($hbinterval < 60 || $hbinterval > 3540) {
                $status = SYNC_STATUS_INVALIDWAITORHBVALUE;
                ZLog::Write(LOGLEVEL_WARN, sprintf("HandleSync(): Invalid heartbeat or wait value '%s'", $hbinterval));
            }
        }

        // Partial & Empty Syncs need saved data to proceed with synchronization
        if ($status == SYNC_STATUS_SUCCESS && (! $sc->HasCollections() || $partial === true )) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Partial or Empty sync requested. Retrieving data of synchronized folders."));

            // Load all collections - do not overwrite existing (received!), laod states and check permissions
            try {
                $sc->LoadAllCollections(false, true, true);
            }
            catch (StateNotFoundException $snfex) {
                $status = SYNC_STATUS_INVALIDSYNCKEY;
                self::$topCollector->AnnounceInformation("StateNotFoundException", true);
            }
            catch (StatusException $stex) {
               $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
               self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
            }

            // update a few values
            foreach($sc as $folderid => $cpo) {
                // manually set getchanges parameter for this collection
                $sc->AddParameter($cpo, "getchanges", true);

                // set new global windowsize without marking the CPO as changed
                if ($sc->GetGlobalWindowSize())
                    $cpo->SetWindowSize($sc->GetGlobalWindowSize(), false);

                // announce WindowSize to DeviceManager
                self::$deviceManager->SetWindowSize($folderid, $cpo->GetWindowSize());
            }
            if (!$sc->HasCollections())
                $status = SYNC_STATUS_SYNCREQUESTINCOMPLETE;
        }

        // HEARTBEAT & Empty sync
        if ($status == SYNC_STATUS_SUCCESS && (isset($hbinterval) || $emtpysync == true)) {
            $interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;

            if (isset($hbinterval))
                $sc->SetLifetime($hbinterval);

            $foundchanges = false;

            // wait for changes
            try {
                // if doing an empty sync, check only once for changes
                if ($emtpysync) {
                    $foundchanges = $sc->CountChanges();
                }
                // wait for changes
                else {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Entering Heartbeat mode"));
                    $foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval);
                }
            }
            catch (StatusException $stex) {
               $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
               self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
            }

            // in case of an empty sync with no changes, we can reply with an empty response
            if ($emtpysync && !$foundchanges){
                ZLog::Write(LOGLEVEL_DEBUG, "No changes found for empty sync. Replying with empty response");
                return true;
            }

            if ($foundchanges) {
                foreach ($sc->GetChangedFolderIds() as $folderid => $changecount) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): heartbeat: found %d changes in '%s'", $changecount, $folderid));
                }
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Start Output"));

        // Start the output
        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SYNCHRONIZE);
        {
            // global status
            if ($status != SYNC_STATUS_SUCCESS) {
                self::$encoder->startTag(SYNC_STATUS);
                    self::$encoder->content($status);
                self::$encoder->endTag();
            }
            else {
                self::$encoder->startTag(SYNC_FOLDERS);
                {
                    foreach($sc as $folderid => $cpo) {
                        // get actiondata
                        $actiondata = $sc->GetParameter($cpo, "actiondata");

                        if (! $sc->GetParameter($cpo, "requested"))
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): partial sync for folder class '%s' with id '%s'", $cpo->GetContentClass(), $cpo->GetFolderId()));

                        // TODO do not get Exporter / Changes if this is a fetch operation

                        // initialize exporter to get changecount
                        $changecount = 0;
                        // TODO observe if it works correct after merge of rev 716
                        // TODO we could check against $sc->GetChangedFolderIds() on heartbeat so we do not need to configure all exporter again
                        if($status == SYNC_STATUS_SUCCESS && ($sc->GetParameter($cpo, "getchanges") || ! $cpo->HasSyncKey())) {
                            try {
                                // Use the state from the importer, as changes may have already happened
                                $exporter = self::$backend->GetExporter($cpo->GetFolderId());

                                if ($exporter === false)
                                    throw new StatusException(sprintf("HandleSync() could not get an exporter for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }

                            try {
                                // Stream the messages directly to the PDA
                                $streamimporter = new ImportChangesStream(self::$encoder, ZPush::getSyncObjectFromFolderClass($cpo->GetContentClass()));

                                $exporter->Config($sc->GetParameter($cpo, "state"));
                                $exporter->ConfigContentParameters($cpo);
                                $exporter->InitializeExporter($streamimporter);

                                $changecount = $exporter->GetChangeCount();
                            }
                            catch (StatusException $stex) {
                                if ($stex->getCode() === SYNC_FSSTATUS_CODEUNKNOWN && $cpo->HasSyncKey())
                                    $status = SYNC_STATUS_INVALIDSYNCKEY;
                                else
                                    $status = $stex->getCode();
                            }
                            if (! $cpo->HasSyncKey())
                                self::$topCollector->AnnounceInformation(sprintf("Exporter registered. %d objects queued.", $changecount), true);
                            else if ($status != SYNC_STATUS_SUCCESS)
                                self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
                        }

                        if (! $sc->GetParameter($cpo, "requested") && $cpo->HasSyncKey() && $changecount == 0)
                            continue;

                        // Get a new sync key to output to the client if any changes have been send or will are available
                        if (!empty($actiondata["modifyids"]) ||
                            !empty($actiondata["clientids"]) ||
                            !empty($actiondata["removeids"]) ||
                            $changecount > 0 || (! $cpo->HasSyncKey() && $status == SYNC_STATUS_SUCCESS))
                                $cpo->SetNewSyncKey(self::$deviceManager->GetStateManager()->GetNewSyncKey($cpo->GetSyncKey()));

                        self::$encoder->startTag(SYNC_FOLDER);

                        if($cpo->HasContentClass()) {
                            self::$encoder->startTag(SYNC_FOLDERTYPE);
                                self::$encoder->content($cpo->GetContentClass());
                            self::$encoder->endTag();
                        }

                        self::$encoder->startTag(SYNC_SYNCKEY);
                        if($status == SYNC_STATUS_SUCCESS && $cpo->HasNewSyncKey())
                            self::$encoder->content($cpo->GetNewSyncKey());
                        else
                            self::$encoder->content($cpo->GetSyncKey());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_FOLDERID);
                            self::$encoder->content($cpo->GetFolderId());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_STATUS);
                            self::$encoder->content($status);
                        self::$encoder->endTag();

                        // Output IDs and status for incoming items & requests
                        if($status == SYNC_STATUS_SUCCESS && (
                            !empty($actiondata["clientids"]) ||
                            !empty($actiondata["modifyids"]) ||
                            !empty($actiondata["removeids"]) ||
                            !empty($actiondata["fetchids"]) )) {

                            self::$encoder->startTag(SYNC_REPLIES);
                            // output result of all new incoming items
                            foreach($actiondata["clientids"] as $clientid => $serverid) {
                                self::$encoder->startTag(SYNC_ADD);
                                    self::$encoder->startTag(SYNC_CLIENTENTRYID);
                                        self::$encoder->content($clientid);
                                    self::$encoder->endTag();
                                    if ($serverid) {
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                    }
                                    self::$encoder->startTag(SYNC_STATUS);
                                        self::$encoder->content((isset($actiondata["statusids"][$clientid])?$actiondata["statusids"][$clientid]:SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR));
                                    self::$encoder->endTag();
                                self::$encoder->endTag();
                            }

                            // loop through modify operations which were not a success, send status
                            foreach($actiondata["modifyids"] as $serverid) {
                                if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_MODIFY);
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                        self::$encoder->startTag(SYNC_STATUS);
                                            self::$encoder->content($actiondata["statusids"][$serverid]);
                                        self::$encoder->endTag();
                                    self::$encoder->endTag();
                                }
                            }

                            // loop through remove operations which were not a success, send status
                            foreach($actiondata["removeids"] as $serverid) {
                                if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_REMOVE);
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                        self::$encoder->startTag(SYNC_STATUS);
                                            self::$encoder->content($actiondata["statusids"][$serverid]);
                                        self::$encoder->endTag();
                                    self::$encoder->endTag();
                                }
                            }

                            if (!empty($actiondata["fetchids"]))
                                self::$topCollector->AnnounceInformation(sprintf("Fetching %d objects ", count($actiondata["fetchids"])), true);

                            foreach($actiondata["fetchids"] as $id) {
                                $data = false;
                                try {
                                    $fetchstatus = SYNC_STATUS_SUCCESS;
                                    $data = self::$backend->Fetch($cpo->GetFolderId(), $id, $cpo);

                                    // check if the message is broken
                                    if (ZPush::GetDeviceManager(false) && ZPush::GetDeviceManager()->DoNotStreamMessage($id, $data)) {
                                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): message not to be streamed as requested by DeviceManager.", $id));
                                        $fetchstatus = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                    }
                                }
                                catch (StatusException $stex) {
                                   $fetchstatus = $stex->getCode();
                                }

                                self::$encoder->startTag(SYNC_FETCH);
                                    self::$encoder->startTag(SYNC_SERVERENTRYID);
                                        self::$encoder->content($id);
                                    self::$encoder->endTag();

                                    self::$encoder->startTag(SYNC_STATUS);
                                        self::$encoder->content($fetchstatus);
                                    self::$encoder->endTag();

                                    if($data !== false && $status == SYNC_STATUS_SUCCESS) {
                                        self::$encoder->startTag(SYNC_DATA);
                                            $data->Encode(self::$encoder);
                                        self::$encoder->endTag();
                                    }
                                    else
                                        ZLog::Write(LOGLEVEL_WARN, sprintf("Unable to Fetch '%s'", $id));
                                self::$encoder->endTag();

                            }
                            self::$encoder->endTag();
                        }

                        if($sc->GetParameter($cpo, "getchanges") && $cpo->HasFolderId() && $cpo->HasContentClass() && $cpo->HasSyncKey()) {
                            $windowSize = self::$deviceManager->GetWindowSize($cpo->GetFolderId(), $cpo->GetContentClass(), $cpo->GetUuid(), $cpo->GetUuidCounter(), $changecount);

                            if($changecount > $windowSize) {
                                self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                            }
                        }

                        // Stream outgoing changes
                        if($status == SYNC_STATUS_SUCCESS && $sc->GetParameter($cpo, "getchanges") === true && $windowSize > 0) {
                            self::$topCollector->AnnounceInformation(sprintf("Streaming data of %d objects", (($changecount > $windowSize)?$windowSize:$changecount)));

                            // Output message changes per folder
                            self::$encoder->startTag(SYNC_PERFORM);

                            $n = 0;
                            while(1) {
                                try {
                                    $progress = $exporter->Synchronize();
                                    if(!is_array($progress))
                                        break;
                                    $n++;
                                }
                                catch (SyncObjectBrokenException $mbe) {
                                    $brokenSO = $mbe->GetSyncObject();
                                    if (!$brokenSO) {
                                        ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): Catched SyncObjectBrokenException but broken SyncObject available. This should be fixed in the backend."));
                                    }
                                    else {
                                        if (!isset($brokenSO->id)) {
                                            $brokenSO->id = "Unknown ID";
                                            ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): Catched SyncObjectBrokenException but no ID of object set. This should be fixed in the backend."));
                                        }
                                        self::$deviceManager->AnnounceIgnoredMessage($cpo->GetFolderId(), $brokenSO->id, $brokenSO);
                                    }
                                }

                                if($n >= $windowSize) {
                                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Exported maxItems of messages: %d / %d", $n, $changecount));
                                    break;
                                }

                            }
                            self::$encoder->endTag();
                            self::$topCollector->AnnounceInformation(sprintf("Outgoing %d objects%s", $n, ($n >= $windowSize)?" of ".$changecount:""), true);
                        }

                        self::$encoder->endTag();

                        // Save the sync state for the next time
                        if($cpo->HasNewSyncKey()) {
                            self::$topCollector->AnnounceInformation("Saving state");

                            try {
                                if (isset($exporter) && $exporter)
                                    $state = $exporter->GetState();

                                // nothing exported, but possibly imported
                                else if (isset($importer) && $importer)
                                    $state = $importer->GetState();

                                // if a new request without state information (hierarchy) save an empty state
                                else if (! $cpo->HasSyncKey())
                                    $state = "";
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }


                            if (isset($state) && $status == SYNC_STATUS_SUCCESS)
                                self::$deviceManager->GetStateManager()->SetSyncState($cpo->GetNewSyncKey(), $state, $cpo->GetFolderId());
                            else
                                ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): error saving '%s' - no state information available", $cpo->GetNewSyncKey()));
                        }

                        // save CPO
                        // TODO check if we need changed data in case of a StatusException
                        if ($status == SYNC_STATUS_SUCCESS)
                            $sc->SaveCollection($cpo);

                    } // END foreach collection
                }
                self::$encoder->endTag(); //SYNC_FOLDERS
            }
        }
        self::$encoder->endTag(); //SYNC_SYNCHRONIZE

        return true;
    }
}

?>