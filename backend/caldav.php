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
    private $_collection = array();
    
    /*
     * Logon to the CalDAV Server
     */
    public function Logon($username, $domain, $password)
    {
        $this->_caldav_path = str_replace('%u', $username, CALDAV_PATH);
        $this->_caldav = new CalDAVClient(CALDAV_SERVER . $this->_caldav_path, $username, $password);
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
     * The connections to CalDAV are always directly closed. So nothing special needs to happen here.
     */
    public function Logoff()
    {
    	return true;
    }
    
    /*
     * CalDAV doesn't need to handle SendMail.
     */
    public function SendMail($sm)
    {
    	return false;
    }
    
    /*
     * No attachments in CalDAV
     */
    public function GetAttachmentData($attname)
    {
    	return false;
    }
    
    /*
     * Deletes are always permanent deletes. Messages doesn't get moved.
     */
    public function GetWasteBasket()
    {
    	return false;
    }
    
    /*
     * Get a list of all the folders we are going to sync.
     * Each caldav calendar can contain tasks, so duplicate each calendar found.
     */
    public function GetFolderList()
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetFolderList(): Getting all folders."));
        /*
        $i = 0;
        $folders = array();
        $calendars = $this->_caldav->FindCalendars();
        foreach ($calendars as $val)
        {
            $i++;
            $folder = array();
            $id = "calendar" . $i;
            $this->_calendars[$id] = array("url" => $val->url, "type" => "calendar");
            $folders[] = $this->StatFolder($id);
            $id = "task" . $i;
            $this->_calendars[$id] = array("url" => $val->url, "type" => "task");
            $folders[] = $this->StatFolder($id);
        }
        */
        //Use static folders for now.
        $folders = array();
        $id = "calendar";
        $folders[] = $this->StatFolder($id);
        $id = "tasks";
        $folders[] = $this->StatFolder($id);
        return $folders;
    }
    
    /*
     * return folder type
     */
    public function GetFolder($id)
    {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->GetFolder('%s')", $id));
        $val = $this->_caldav->GetCalendarDetails($this->_caldav_path);
        $folder = new SyncFolder();
        $folder->parentid = "0";
        $folder->displayname = $val->displayname;
        $folder->serverid = $id;
        if ($id == "calendar")
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
        
        if ($folderid == "calendar")
        {
            $msgs = $this->_caldav->GetEvents($begin, $finish);
        }
        else
        {
            $msgs = $this->_caldav->GetTodos($begin, $finish);
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
        $data = $this->_collection[$id]['data'];
        
        if ($folderid == "calendar")
        {
            return $this->_ParseVEventToAS($data, $contentparameters);
        }
        if ($folderid == "tasks")
        {
            return $this->_ParseVEventToAS($data, $contentparameters);
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
        	$mod = $this->StatMessage($folderid, $id);
            $etag = $mod['mod'];
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
        $http_status_code = $this->_caldav->DoDELETERequest($id);
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

    /*
     * Convert a iCAL VEvent to ActiveSync format
     */
    private function _ParseVEventToAS($data, $contentparameters)
    {
    	ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_ParseVEventToAS(): Parsing VEvent"));
    	$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
    	$message = new SyncAppointment();
    	
    	$ical = new iCalComponent($data);
    	$timezones = $ical->GetComponents("VTIMEZONE");
    	if (count($timezones) > 0)
    	{
    		$timezone = $timezones[0]->GetPValue("TZID");
    		$message->timezone = $this->_GetTimezoneString($timezone);
    	}
    	
    	$vevents = $ical->GetComponents("VTIMEZONE", false);
    	foreach ($vevents as $event)
    	{
    		$reccurence_id = reset($event->GetProperties("RECURRENCE-ID"));
    		if ($reccurence_id)
    		{
    			$exception = new SyncAppointmentException();
    			$tzid = $reccurence_id->GetParameterValue("TZID");
    			if (!$tzid)
    			{
    				$tzid = $timezone;
    			}
    			$exception->exceptionstarttime = $this->_MakeUTCDate($reccurence_id->Value(), $tzid);
    			$exception->deleted = "0";
    			$exception = $this->_ParseVEventToSyncObject($event, $exception, $truncsize);
    			$message->exception[] = $exception;
    		}
    		else
    		{
    			$message = $this->_ParseVEventToSyncObject($event, $message, $truncsize);
    		}
    	}
    	return $message;
    }
    
    private function _ParseVEventToSyncObject($event, $message, $truncsize)
    {
    	$properties = $event->GetProperties();
    	foreach ($properties as $property)
    	{
    		switch ($property->Name())
    		{
    			case "LAST-MODIFIED":
    				$message->dtstamp = $this->_MakeUTCDate($property->Value());
    				break;
    				
    			case "DTSTART":
    				$message->starttime = $this->_MakeUTCDate($property->Value(), $property->GetParameterValue("TZID"));
    				if (strlen($property->Value()) == 8)
    				{
    					$message->alldayevent = "1";
    				}
    				break;
    				
    			case "SUMMARY":
    				$message->subject = $property->Value();
    				break;
    				
    			case "UID":
    				$message->uid = $property->Value();
    				break;
    				
    			case "ORGANIZER":
    				$org_str = str_replace(":MAILTO:", ";MAILTO=", $property->Value());
    				$orgs = explode(";", $org_str);
    				foreach ($orgs as $org)
    				{
    					$o = explode("=", $org);
    					if ($o[0] == "CN")
    					{
    						$message->organizername = $o[1];
    					}
    					if ($o[0] == "MAILTO")
    					{
    						$message->organizeremail = $o[1];
    					}
    				}
    				break;
    				
    			case "LOCATION":
    				$message->location = $property->Value();
    				break;

    			case "DTEND":
    				$message->endtime = $this->_MakeUTCDate($property->Value(), $property->GetParameterValue("TZID"));
    				if (strlen($property->Value()) == 8)
    				{
    					$message->alldayevent = "1";
    				}
    				break;
    				
    			case "RRULE":
    				$message->recurrence = $this->_ParseRecurrence($property->Value());
    				break;

    			case "CLASS":
    				switch ($property->Value())
    				{
    					case "PUBLIC":
    						$message->sensitivity = "0";
    						break;
    					case "PRIVATE":
    						$message->sensitivity = "2";
    						break;    						
    					case "CONFIDENTIAL":
    						$message->sensitivity = "3";
    						break;
    				}
    				break;
    				
    			case "TRANSP":
    				switch ($property->Value())
    				{
    					case "TRANSPARENT":
    						$message->busystatus = "0";
    						break;
    					case "OPAQUE":
    						$message->busystatus = "2";
    						break;
    				}
    				break;
    				
    			case "STATUS":
    				switch ($property->Value())
    				{
    					case "TENTATIVE":
    						$message->meetingstatus = "1";
    						break;
    					case "CONFIRMED":
    						$message->meetingstatus = "3";
    						break;
    					case "CANCELLED":
    						$message->meetingstatus = "5";
    						break;
    				}
    				break;
    				
    			case "ATTENDEE":
    				$att_str = str_replace(":MAILTO:", ";MAILTO=", $property->Value());
    				$attendees = explode(";", $att_str);
    				$attendee = new SyncAttendee();
    				foreach ($attendees as $att)
    				{
    					$a = explode("=", $att);
    					if ($a[0] == "CN")
    					{
    						$attendee->name = $a[1];
    					}
    					if ($a[0] == "MAILTO")
    					{
    						$attendee->email = $a[1];
    					}
    				}
    				if (is_array($message->attendees))
    				{
    					$message->attendees[] = $attendee;
    				}
    				else
    				{
    					$message->attendees = array($attendee);
    				}
    				break;
    				
    			case "DESCRIPTION":
    				$body = $property->Value();
    				// truncate body, if requested
    				if(strlen($body) > $truncsize) {
    					$body = Utils::Utf8_truncate($body, $truncsize);
    					$message->bodytruncated = 1;
    				} else {
    					$body = $body;
    					$message->bodytruncated = 0;
    				}
    				$body = str_replace("\n","\r\n", str_replace("\r","",$body));
    				$message->body = $body;
    				break;
    				
    			case "CATEGORIES":
    				$categories = explode(",", $property->Value());
    				$message->categories = $categories;
    				break;    			
    		}
    	}
    	
    	$valarm = current($event->GetComponents("VALARM"));
    	if ($valarm)
    	{
    		$properties = $event->GetProperties();
    		foreach ($properties as $property)
    		{
    			switch ($property->Name())
    			{
    				case "TRIGGER":
    					$val = $property->Value();
    					$val = str_replace("-", "", $val);
    					$interval = new DateInterval($val);
    					$message->reminder = $interval->format("i");
    					break;
    			}
    		}
    	}
    	
    	return $message;
    }
    
    private function _ParseRecurrence($rrules)
    {
    	$recurrence = new SyncRecurrence();
    	$rrules = explode(";", $rrule);
    	foreach ($rrules as $rrule)
    	{
    		$rule = explode("=", $rrule);
    		switch ($rule[0])
    		{
    			case "FREQ":
		    		switch ($rule[1])
		    		{
		    			case "DAILY":
		    				$recurrence->type = "0";
		    				break;
		    			case "WEEKLY":
		    				$recurrence->type = "1";
		    				break;
		    			case "MONTHLY":
		    				$recurrence->type = "2";
		    				break;
		    			case "YEARLY":
		    				$recurrence->type = "5";
		    		}
		    		break;
		    		
    			case "UNTIL":
    				$recurrence->until = $this->_MakeUTCDate($rule[1]);
    				break;
    				
    			case "COUNT":
    				$recurrence->occurrences = $rule[1];
    				break;
    				
    			case "INTERVAL":
    				$recurrence->interval = $rule[1];
    				break;

    			case "BYDAY":
    				$dval = 0;
    				$days = explode(",", $rule[1]);
    				foreach ($days as $day)
    				{
    					switch ($day)
    					{
    						//   1 = Sunday
    						//   2 = Monday
    						//   4 = Tuesday
    						//   8 = Wednesday
    						//  16 = Thursday
    						//  32 = Friday
    						//  62 = Weekdays  // not in spec: daily weekday recurrence
    						//  64 = Saturday
    						case "SU":
    							$dval += 1;
    							break;
    						case "MO":
    							$dval += 2;
    							break;
    						case "TU":
    							$dval += 4;
    							break;
    						case "WE":
    							$dval += 8;
    							break;
    						case "TH":
    							$dval += 16;
    							break;
    						case "FR":
    							$dval += 32;
    							break;
    						case "SA":
    							$dval += 64;
    							break;
    					}
    				}
    				$recurrence->dayofweek = $dval;
    				break;
    				
    			//Only 1 BYMONTHDAY is supported, so BYMONTHDAY=2,3 will only include 2
    			case "BYMONTHDAY":
    				$days = explode(",", $rule[1]);
    				$recurrence->dayofmonth = $days[0];
    				break;
    			
    			case "BYMONTH":
    				$recurrence->monthofyear = $rule[1];
    				break;
    				
    			default:
    				ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCalDAV->_ParseRecurrence(): '%s' is not yet supported.", $rule[0]));
    		}
    	}
    	return $recurrence;
    }

    //TODO: Implement
    private function _ParseASEventToVEvent($data)
    {
    }

    //TODO: Implement
    private function _ParseVTodoToAS($data)
    {
    }

    //TODO: Implement
    private function _ParseASTaskToVTodo($data)
    {
    }
    
    private function _MakeUTCDate($value, $timezone = null)
    {
    	if ($timezone)
    	{
    		$tz = timezone_open($timezone);
    	}
    	if (!$tz)
    	{
    		//If there is no timezone set, we use the default timezone
    		$tz = timezone_open(date_default_timezone_get());
    	}
    	//20110930T090000Z
    	date_create_from_format('Ymd\THis\Z', $time, timezone_open("UTC"));
    	if (!$date)
    	{
    		//20110930T090000
    		$date = date_create_from_format('Ymd\THis', $timestr, $tz);
    	}
    	if (!$date)
    	{
    		//20110930
    		$date = date_create_from_format('Ymd', $timestr, $tz);
    	}
    	return date_timestamp_get($date);
    }
    
    private function _GetTimezoneString($timezone, $with_names = true)
    {
    	// UTC needs special handling
    	if ($timezone == "UTC")
    		return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
    	try {
    		//Generate a timezone string (PHP 5.3 needed for this)
    		$timezone = new DateTimeZone($timezone);
    		$trans = $timezone->getTransitions(time());
    		$stdTime = null;
    		$dstTime = null;
    		if (count($trans) < 3)
    		{
    			throw new Exception();
    		}
    		if ($trans[1]['isdst'] == 1)
    		{
    			$dstTime = $trans[1];
    			$stdTime = $trans[2];
    		}
    		else
    		{
    			$dstTime = $trans[2];
    			$stdTime = $trans[1];
    		}
    		$stdTimeO = new DateTime($stdTime['time']);
    		$stdFirst = new DateTime(sprintf("first sun of %s %s", $stdTimeO->format('F'), $stdTimeO->format('Y')));
    		$stdInterval = $stdTimeO->diff($stdFirst);
    		$stdDays = $stdInterval->format('%d');
    		$stdBias = $stdTime['offset'] / -60;
    		$stdName = $stdTime['abbr'];
    		$stdYear = 0;
    		$stdMonth = $stdTimeO->format('n');
    		$stdWeek = floor($stdDays/7)+1;
    		$stdDay = $stdDays%7;
    		$stdHour = $stdTimeO->format('H');
    		$stdMinute = $stdTimeO->format('i');
    		$stdTimeO->add(new DateInterval('P7D'));
    		if ($stdTimeO->format('n') != $stdMonth)
    		{
    			$stdWeek = 5;
    		}
    		$dstTimeO = new DateTime($dstTime['time']);
    		$dstFirst = new DateTime(sprintf("first sun of %s %s", $dstTimeO->format('F'), $dstTimeO->format('Y')));
    		$dstInterval = $dstTimeO->diff($dstFirst);
    		$dstDays = $dstInterval->format('%d');
    		$dstName = $dstTime['abbr'];
    		$dstYear = 0;
    		$dstMonth = $dstTimeO->format('n');
    		$dstWeek = floor($dstDays/7)+1;
    		$dstDay = $dstDays%7;
    		$dstHour = $dstTimeO->format('H');
    		$dstMinute = $dstTimeO->format('i');
    		if ($dstTimeO->format('n') != $dstMonth)
    		{
    			$dstWeek = 5;
    		}
    		$dstBias = ($dstTime['offset'] - $stdTime['offset']) / -60;
    		if ($with_names)
    		{
    			return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, $stdName, 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, $dstName, 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
    		}
    		else
    		{
    			return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, '', 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, '', 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
    		}
    	}
    	catch (Exception $e) {
    		// If invalid timezone is given, we return UTC
    		return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
    	}
    	return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
    }
}

?>
