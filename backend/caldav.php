<?php
/***********************************************
* File      :   caldav.php
* Project   :   PHP-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               CalDAV interface
*
* Created   :   29.03.2012
*
* Copyright 2012 Jean-Louis Dupond
************************************************/

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/caldav-client-v2.php');
include_once('iCalendar.php');

class BackendCalDAV extends BackendDiff {
    
    private $_caldav;
    private $_caldav_path;
    private $_calendars;

    private $_collection = array();
    
    /*
     * Logon to the CalDAV Server
     */
    public function Logon($username, $domain, $password)
    {
        $this->_caldav_path = CALDAV_PATH;
        str_replace('%u', $username, $this->_caldav_path);
        $this->caldav = new CalDAVClient(CALDAV_SERVER . $this->caldav_path, $username, $password);
        $options = $this->_caldav->DoOptionsRequest();
        if (isset($options["PROPFIND"]))
        {
            ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCalDAV->Logon(): User '%s' is authenticated on CalDAV", $username));
            return true;
        }
        else
        {
            ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCalDAV->Logon(): User '%s' is not authenticated on CalDAV", $username));
            return false;
        }
    }
    
    /*
     * Get a list of all the folders we are going to sync.
     * Each caldav calendar can contain tasks, so duplicate each calendar found.
     */
    public function GetFolderList()
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetFolderList(): Getting all folders."));
        $i = 0;
        $folders = array();
        $calendars = $this->_caldav->FindCalendars();
        foreach ($calendars as $val)
        {
            $i++;
            $folder = new SyncFolder();
            $folder->parentid = "0";
            $folder->displayname = $val->displayname;
            $folder->serverid = "calendar" . $i;
            $this->_calendars[$folder->serverid] = array("url" => $val->url, "type" => "calendar");
            $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
            $folders[] = $folder;
            $folder->serverid = "task" . $i;
            $this->_calendars[$folder->serverid] = array("url" => $val->url, "type" => "task");
            $folder->type = SYNC_FOLDER_TYPE_TASK;
        }
        return $folders;
    }
    
    /*
     * return folder type
     */
    public function GetFolder($id)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetFolder('%s')", $id));
        $val = $this->_caldav->GetCalendarDetails($this->_calendars[$id]["url"]);
        $folder = new SyncFolder();
        $folder->parentid = "0";
        $folder->displayname = $val->displayname;
        $folder->serverid = $id;
        if ($this->_calendars[$id]["type"] == "calendar")
        {
            $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
        }
        else
        {
            $folder->type = SYNC_FOLDER_TYPE_TASK;
        }
        return $folder;
    }
    
    /*
     * return folder information
     */
    public function StatFolder($id)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->StatFolder('%s')", $id));
        $val = $this->GetFolder($id);
        $folder = array();
        $folder["id"] = $id;
        $folder["parent"] = $val->parentid;
        $folder["mod"] = $val->serverid;
        return $folder;
    }
    
    /*
     * ChangeFolder is not supported under CalDAV
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
        return false;
    }
    
    /*
     * DeleteFolder is not supported under CalDAV
     */
    public function DeleteFolder($id, $parentid)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->DeleteFolder('%s','%s')", $id, $parentid));
        return false;
    }
    
    /*
     * Get a list of all the messages
     */
    public function GetMessageList($folderid, $cutoffdate)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetMessageList('%s','%s')", $folderid, $cutoffdate));
        
        /* Calculating the range of events we want to sync */
        $begin = date("Ymd\THis\Z", $cutoffdate);
        $diff = time() - $cutoffdate;
        $finish = date("Ymd\THis\Z", 2147483647);

        $folder_url = $this->_calendars[$id]["url"];
        $type = $this->_calendars[$id]["type"];
        
        if ($type == "calendar")
        {
            $msgs = $this->_caldav->GetEvents($begin, $finish, $folder_url);
        }
        else
        {
            $msgs = $this->_caldav->GetTodos($begin, $finish, $folder_url);
        }

        $messages = array();
        foreach ($msgs as $e)
        {
            $id = $e['href'];
            $this->_collection[$id] = $e;
            $messages[] = $this->StatMessage($folderid, $id);
        }
        return $messages;
    }

    /*
     * Get a SyncObject by id
     */
    public function GetMessage($folderid, $id, $contentparameters)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetMessage('%s','%s')", $folderid,  $id));
        $type = $this->_calendars[$folderid]["type"];
        $data = $this->_collection[$id]['data'];
        
        if ($type == "calendar")
        {
            return $this->_ParseVEventToEx($data);
        }
        if ($type == "tasks")
        {
            return $this->_ParseVTodoToEx($data);
        }
        return false;
    }

    /*
     * Return id, flags and mod of a messageid
     */
    public function StatMessage($folderid, $id)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->StatMessage('%s','%s')", $folderid,  $id));
        $data = $this->_collection[$id];
        $message = array();
        $message['id'] = $data['href'];
        $message['flags'] = "1";
        $message['mod'] = $data['etag'];
        return $message;
    }

    /*
     * Change a message received from your phone/pda on the server
     */
    public function ChangeMessage($folderid, $id, $message)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->ChangeMessage('%s','%s')", $folderid,  $id));

        $type = $this->_calendars[$folderid]["type"];
        if ($type == "calendar")
        {
            $data = $this->_ParseExEventToVEvent($message);
        }
        if ($type == "tasks")
        {
            $data = $this->_ParseExTaskToVTodo($message);
        }
        
        if ($id)
        {
            $etag = $this->StatMessage($folderid, $id)['mod'];
        }
        else
        {
            $etag = "*";
        }
        $base_url = $this->_calendars[$id]["url"];
        $etag_new = $this->_caldav->DoPUTRequest($base_url.$id, $data, $etag);

        $item = array();
        $item['href'] = $id;
        $item['etag'] = $etag_new;
        $item['data'] = $data;
        $this->_collection[$id] = $item;

        return $this->StatMessage($folderid, $id);
    }

    /*
     * Change the read flag is not supported
     */
    public function SetReadFlag($folderid, $id, $flags)
    {
        return false;
    }

    /*
     * Delete a message from the CalDAV server
     */
    public function DeleteMessage($folderid, $id)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->DeleteMessage('%s','%s')", $folderid,  $id));
        $http_status_code = $this->cdc->DoDELETERequest($id);
        if ($http_status_code == "204") {
            return true;
        }
        return false;
    }

    /*
     * Move a message is not supported
     */
    public function MoveMessage($folderid, $id, $newfolderid)
    {
        return false;
    }

    //TODO: Implement
    private function _ParseVEventToEx($data)
    {
    }

    //TODO: Implement
    private function _ParseExEventToVEvent($data)
    {
    }

    //TODO: Implement
    private function _ParseVTodoToEx($data)
    {
    }

    //TODO: Implement
    private function _ParseExTaskToVTodo($data)
    {
    }
}

?>
