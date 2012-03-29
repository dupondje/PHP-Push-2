<?php
/***********************************************
* File      :   getitemestimate.php
* Project   :   Z-Push
* Descr     :   Provides the GETITEMESTIMATE command
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

class GetItemEstimate extends RequestProcessor {

    /**
     * Handles the GetItemEstimate command
     * Returns an estimation of how many items will be synchronized at the next sync
     * This is mostly used to show something in the progress bar
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $sc = new SyncCollections();

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
            $cpo = new ContentParameters();
            $cpostatus = false;

            if (Request::GetProtocolVersion() >= 14.0) {
                if(self::$decoder->getElementStartTag(SYNC_SYNCKEY)) {
                    try {
                        $cpo->SetSyncKey(self::$decoder->getElementContent());
                    }
                    catch (StateInvalidException $siex) {
                        $cpostatus = SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED;
                    }

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                    $cpo->SetFolderId( self::$decoder->getElementContent());

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

                if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                    while(1) {
                        if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                            $cpo->SetFilterType(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                            $cpo->SetContentClass(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_MAXITEMS)) {
                            $cpo->SetWindowSize($maxitems = self::$decoder->getElementContent());
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
            }
            else {
                //get items estimate does not necessarily send the folder type
                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE)) {
                    $cpo->SetContentClass(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                    $cpo->SetFolderId(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(!self::$decoder->getElementStartTag(SYNC_FILTERTYPE))
                    return false;

                $cpo->SetFilterType(self::$decoder->getElementContent());

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                    return false;

                try {
                    $cpo->SetSyncKey(self::$decoder->getElementContent());
                }
                catch (StateInvalidException $siex) {
                    $cpostatus = SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED;
                }

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false; //SYNC_GETITEMESTIMATE_FOLDER

            // Process folder data

            //In AS 14 request only collectionid is sent, without class
            if (! $cpo->HasContentClass() && $cpo->HasFolderId())
                $cpo->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($cpo->GetFolderId()));

            // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
            if (! $cpo->HasFolderId() && $cpo->HasContentClass()) {
                $cpo->SetFolderId(self::$deviceManager->GetFolderIdFromCacheByClass($cpo->GetContentClass()));
            }

            // Add collection to SC and load state
            $sc->AddCollection($cpo);
            if ($cpostatus) {
                // the CPO has a folder id now, so we can set the status
                $sc->AddParameter($cpo, "status", $cpostatus);
            }
            else {
                try {
                    $sc->AddParameter($cpo, "state", self::$deviceManager->GetStateManager()->GetSyncState($cpo->GetSyncKey()));

                    // if this is an additional folder the backend has to be setup correctly
                    if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($cpo->GetFolderId())))
                        throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
                }
                catch (StateNotFoundException $snfex) {
                    $sc->AddParameter($cpo, "status", SYNC_GETITEMESTSTATUS_SYNCKKEYINVALID);
                    self::$topCollector->AnnounceInformation("StateNotFoundException", true);
                }
                catch (StatusException $stex) {
                    $sc->AddParameter($cpo, "status", SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED);
                    self::$topCollector->AnnounceInformation("StatusException SYNCSTATENOTPRIMED", true);
                }
            }
        }
        if(!self::$decoder->getElementEndTag())
            return false; //SYNC_GETITEMESTIMATE_FOLDERS

        if(!self::$decoder->getElementEndTag())
            return false; //SYNC_GETITEMESTIMATE_GETITEMESTIMATE

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
        {
            $status = SYNC_GETITEMESTSTATUS_SUCCESS;
            // look for changes in all collections

            try {
                $sc->CountChanges();
            }
            catch (StatusException $ste) {
                $status = SYNC_GETITEMESTSTATUS_COLLECTIONINVALID;
            }
            $changes = $sc->GetChangedFolderIds();

            foreach($sc as $folderid => $cpo) {
                self::$encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
                {
                    if ($sc->GetParameter($cpo, "status"))
                        $status = $sc->GetParameter($cpo, "status");

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                    {
                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
                        self::$encoder->content($cpo->GetContentClass());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                        self::$encoder->content($cpo->GetFolderId());
                        self::$encoder->endTag();

                        if (isset($changes[$folderid]) && $changes[$folderid] !== false) {
                            self::$encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);
                            self::$encoder->content($changes[$folderid]);
                            self::$encoder->endTag();

                            if ($changes[$folderid] > 0)
                                self::$topCollector->AnnounceInformation(sprintf("%s %d changes", $cpo->GetContentClass(), $changes[$folderid]), true);
                        }
                    }
                    self::$encoder->endTag();
                }
                self::$encoder->endTag();
            }
        }
        self::$encoder->endTag();

        return true;
    }
}
?>