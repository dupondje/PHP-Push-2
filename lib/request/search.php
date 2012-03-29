<?php
/***********************************************
* File      :   search.php
* Project   :   Z-Push
* Descr     :   Provides the SEARCH command
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

class Search extends RequestProcessor {

    /**
     * Handles the Search command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $searchrange = '0';

        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_SEARCH))
            return false;

        // TODO check: possible to search in other stores?
        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_STORE))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_NAME))
            return false;
        $searchname = strtoupper(self::$decoder->getElementContent());
        if(!self::$decoder->getElementEndTag())
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_QUERY))
            return false;
        $searchquery = self::$decoder->getElementContent();
        if(!self::$decoder->getElementEndTag())
            return false;

        if(self::$decoder->getElementStartTag(SYNC_SEARCH_OPTIONS)) {
            while(1) {
                if(self::$decoder->getElementStartTag(SYNC_SEARCH_RANGE)) {
                    $searchrange = self::$decoder->getElementContent();
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
        if(!self::$decoder->getElementEndTag()) //store
            return false;

        if(!self::$decoder->getElementEndTag()) //search
            return false;

        // get SearchProvider
        $searchprovider = ZPush::GetSearchProvider();
        $status = SYNC_SEARCHSTATUS_SUCCESS;
        $rows = array();

        // TODO support other searches
        if ($searchprovider->SupportsType($searchname)) {
            $storestatus = SYNC_SEARCHSTATUS_STORE_SUCCESS;
            try {
                if ($searchname == ISearchProvider::SEARCH_GAL) {
                    //get search results from the searchprovider
                    $rows = $searchprovider->GetGALSearchResults($searchquery, $searchrange);
                }
            }
            catch (StatusException $stex) {
                $storestatus = $stex->getCode();
            }
        }
        else {
            $rows = array();
            $status = SYNC_SEARCHSTATUS_SERVERERROR;
            ZLog::Write(LOGLEVEL_WARN, sprintf("Searchtype '%s' is not supported.", $searchname));
            self::$topCollector->AnnounceInformation(sprintf("Unsupported type '%s''", $searchname), true);
        }
        $searchprovider->Disconnect();

        self::$topCollector->AnnounceInformation(sprintf("'%s' search found %d results", $searchname, $rows['searchtotal']), true);

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SEARCH_SEARCH);

            self::$encoder->startTag(SYNC_SEARCH_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            if ($status == SYNC_SEARCHSTATUS_SUCCESS) {
                self::$encoder->startTag(SYNC_SEARCH_RESPONSE);
                self::$encoder->startTag(SYNC_SEARCH_STORE);

                    self::$encoder->startTag(SYNC_SEARCH_STATUS);
                    self::$encoder->content($storestatus);
                    self::$encoder->endTag();

                    if (is_array($rows) && !empty($rows)) {
                        $searchrange = $rows['range'];
                        unset($rows['range']);
                        $searchtotal = $rows['searchtotal'];
                        unset($rows['searchtotal']);
                        foreach ($rows as $u) {
                            self::$encoder->startTag(SYNC_SEARCH_RESULT);
                                self::$encoder->startTag(SYNC_SEARCH_PROPERTIES);

                                    self::$encoder->startTag(SYNC_GAL_DISPLAYNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_DISPLAYNAME]))?$u[SYNC_GAL_DISPLAYNAME]:"No name");
                                    self::$encoder->endTag();

                                    if (isset($u[SYNC_GAL_PHONE])) {
                                        self::$encoder->startTag(SYNC_GAL_PHONE);
                                        self::$encoder->content($u[SYNC_GAL_PHONE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_OFFICE])) {
                                        self::$encoder->startTag(SYNC_GAL_OFFICE);
                                        self::$encoder->content($u[SYNC_GAL_OFFICE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_TITLE])) {
                                        self::$encoder->startTag(SYNC_GAL_TITLE);
                                        self::$encoder->content($u[SYNC_GAL_TITLE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_COMPANY])) {
                                        self::$encoder->startTag(SYNC_GAL_COMPANY);
                                        self::$encoder->content($u[SYNC_GAL_COMPANY]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_ALIAS])) {
                                        self::$encoder->startTag(SYNC_GAL_ALIAS);
                                        self::$encoder->content($u[SYNC_GAL_ALIAS]);
                                        self::$encoder->endTag();
                                    }

                                    // Always send the firstname, even empty. Nokia needs this to display the entry
                                    self::$encoder->startTag(SYNC_GAL_FIRSTNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_FIRSTNAME]))?$u[SYNC_GAL_FIRSTNAME]:"");
                                    self::$encoder->endTag();

                                    self::$encoder->startTag(SYNC_GAL_LASTNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_LASTNAME]))?$u[SYNC_GAL_LASTNAME]:"No name");
                                    self::$encoder->endTag();

                                    if (isset($u[SYNC_GAL_HOMEPHONE])) {
                                        self::$encoder->startTag(SYNC_GAL_HOMEPHONE);
                                        self::$encoder->content($u[SYNC_GAL_HOMEPHONE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_MOBILEPHONE])) {
                                        self::$encoder->startTag(SYNC_GAL_MOBILEPHONE);
                                        self::$encoder->content($u[SYNC_GAL_MOBILEPHONE]);
                                        self::$encoder->endTag();
                                    }

                                    self::$encoder->startTag(SYNC_GAL_EMAILADDRESS);
                                    self::$encoder->content((isset($u[SYNC_GAL_EMAILADDRESS]))?$u[SYNC_GAL_EMAILADDRESS]:"");
                                    self::$encoder->endTag();

                                self::$encoder->endTag();//result
                            self::$encoder->endTag();//properties
                        }

                        if ($searchtotal > 0) {
                            self::$encoder->startTag(SYNC_SEARCH_RANGE);
                            self::$encoder->content($searchrange);
                            self::$encoder->endTag();

                            self::$encoder->startTag(SYNC_SEARCH_TOTAL);
                            self::$encoder->content($searchtotal);
                            self::$encoder->endTag();
                        }
                    }

                self::$encoder->endTag();//store
                self::$encoder->endTag();//response
            }
        self::$encoder->endTag();//search

        return true;
    }
}
?>