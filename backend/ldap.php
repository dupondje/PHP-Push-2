<?php
/***********************************************
* File      :   ldap.php
* Project   :   PHP-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               (Open)LDAP interface
*
* Created   :   07.04.2012
*
* Copyright 2012 Jean-Louis Dupond
************************************************/

include_once('lib/default/diffbackend/diffbackend.php');

class BackendLDAP extends BackendDiff {
	
	private $ldap_link;
	private $user;
	
	public function Logon($username, $domain, $password)
	{
		$this->user = $username;
		$user_dn = str_replace('%u', $username, LDAP_USER_DN);
		$this->ldap_link = ldap_connect(LDAP_SERVER, LDAP_PORT);
		ldap_set_option($this->ldap_link, LDAP_OPT_PROTOCOL_VERSION, 3);
		if (ldap_bind($this->ldap_link, $user_dn, $password))
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logon(): User '%s' is authenticated on LDAP", $username));
			return true;
		}
		else
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logon(): User '%s' is not authenticated on LDAP. Error: ", $username, ldap_error($this->ldap_link)));
			return false;
		}
	}
	
	public function Logoff()
	{
		if (ldap_unbind($this->ldap_link))
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logoff(): Disconnection successfull."));
		}
		else
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendLDAP->Logoff(): Disconnection failed. Error: %s", ldap_error($this->ldap_link)));
		}
		return true;
	}
	
	public function SendMail($sm)
	{
		return false;
	}
	
	public function GetAttachmentData($attname)
	{
		return false;
	}
	
	public function GetWasteBasket()
	{
		return false;
	}
	
	public function GetFolderList()
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetFolderList(): Getting all folders."));
		$contacts = array();
		$folder = $this->StatFolder("contacts");
		$contacts[] = $folder;
		return $contacts;
	}
	
	public function GetFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetFolder('%s')", $id));
		if ($id == "contacts")
		{
			$folder = new SyncFolder();
			$folder->serverid = $id;
			$folder->parentid = "0";
			$folder->displayname = "Contacts";
			$folder->type = SYNC_FOLDER_TYPE_CONTACT;
			return $folder;
		}
	}
	
	public function StatFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->StatFolder('%s')", $id));
		$folder = $this->GetFolder($id);
		$stat = array();
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;
		return $stat;
	}
	
	public function ChangeFolder($folderid, $oldid, $displayname, $type)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
		return false;
	}
	
	public function DeleteFolder($id, $parentid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->DeleteFolder('%s','%s')", $id, $parentid));
		return false;
	}
	
	public function GetMessageList($folderid, $cutoffdate)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetMessageList('%s','%s')", $folderid, $cutoffdate));
		
		$cutoff = date("YmdHis\Z", $cutoffdate);
		$filter = sprintf('(modifyTimestamp>="%s")', $cutoff);
		$attributes = array("entryUUID", "modifyTimestamp");
		$messages = array();

		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$base_dn = str_replace('%u', $this->user, $base_dn);
			$results = ldap_list($this->ldap_link, $base_dn, $filter, $attributes);
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetMessageList(): Got %s contacts.", ldap_count_entries($this->ldap_link, $results)));
			$entries = ldap_get_entries($this->ldap_link, $results);
			foreach ($entries as $entry)
			{
				$message = array();
				$message["id"] = $entry["entryuuid"][0];
				$message["mod"] = $entry["modifytimestamp"][0];
				$message["flags"] = "1";
				$messages[] = $message;
			}
		}
		return $messages;
	}
	
	public function GetMessage($folderid, $id, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendLDAP->GetMessage('%s','%s')", $folderid,  $id));
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$base_dn = str_replace('%u', $this->user, $base_dn);
			$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")");
			if ($result_id)
			{
				$entry_id = ldap_first_entry($this->ldap_link, $result_id);
				if ($entry_id)
				{
					return $this->_ParseLDAPMessage($result_id, $entry_id);
				}
			}
		}
	}
	
	private function _ParseLDAPMessage($result_id, $entry_id)
	{
		$contact = new SyncContact();
		
		$values = ldap_get_attributes($ldap_link, $entry_id);
		for ($i = 0; i < $values["count"]; $i++)
		{
			$name = $values[$i];
			$value = $values[$name][0];
			
			switch ($name)
			{
				//person
				case "cn":
					$contact->fileas = $value;
					break;
				case "sn":
					$contact->lastname = $value;
					break;
				//inetOrgPerson
				case "departmentNumber":
					$contact->department = $value;
					break;
				case "givenName":
					$contact->firstname = $value;
					break;
				case "homePhone":
					$contact->homephonenumber = $value;
					break;
				case "jpegPhoto":
					$contact->picture = base64_encode($value);
					break;
				case "labeledURI":
					$contact->webpage = $value;
					break;
				case "mail":
					$contact->email1address = $value;
					if ($values[$name]["count"] >= 2)
					{
						$contact->email2address = $values[$name][1];
					}
					if ($values[$name]["count"] >= 3)
					{
						$contact->email3address = $values[$name][2];
					}
					break;
				case "mobile":
					$contact->mobilephonenumber = $value;
					break;
				case "o":
					$contact->companyname = $value;
					break;
				case "pager":
					$contact->pagernumber = $value;
					break;
				case "secretary":
					$contact->assistantname = $value;
					break;
				//organizationalPerson					
				case "l":
					$contact->businesscity = $value;
					break;
				case "ou":
					$contact->department = $value;
					break;
				case "physicalDeliveryOfficeName":
					$contact->officelocation = $value;
					break;
				case "postalCode":
					$contact->businesspostalcode = $value;
					break;
				case "st":
					$contact->businessstate = $value;	
				case "street":
					$contact->businessstreet = $value;
					break;
				case "telephoneNumber":
					$contact->businessphonenumber = $value;
					if ($values[$name]["count"] >= 2)
					{
						$contact->business2phonenumber = $values[$name][1];
					}
					break;
				case "title":
					$contact->title = $value;
					break;
				case "description":
					$contact->body = $value;
					$contact->bodysize = strlen($value);
					$contact->bodytruncated = "0";
					break;
			}
		}
		return $contact;
	}
	
	public function StatMessage($folderid, $id)
	{
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$base_dn = str_replace('%u', $this->user, $base_dn);
			$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")", array("modifyTimestamp"));
			if ($result_id)
			{
				$entry_id = ldap_first_entry($this->ldap_link, $result_id);
				if ($entry_id)
				{
					$mod = ldap_get_values($this->ldap_link, $entry_id, "modifyTimestamp");
					$message = array();
					$message["id"] = $id;
					$message["mod"] = $mod[0];
					$message["flags"] = "1";
					return $message;
				}
			}
		}
	}
	
	//TODO: Implement
	public function ChangeMessage($folderid, $id, $message)
	{
		return false;
	}
	
	public function SetReadFlag($folderid, $id, $flags)
	{
		return false;
	}
	
	public function DeleteMessage($folderid, $id)
	{
		$base_dns = explode("|", LDAP_BASE_DNS);
		foreach ($base_dns as $base_dn)
		{
			$base_dn = str_replace('%u', $this->user, $base_dn);
			$result_id = ldap_list($this->ldap_link, $base_dn, "(entryUUID=".$id.")", array("entryUUID"));
			if ($result_id)
			{
				$entry_id = ldap_first_entry($this->ldap_link, $result_id);
				if ($entry_id)
				{
					$dn = ldap_get_dn($this->ldap_link, $entry_id);
					return ldap_delete($this->ldap_link, $dn);
				}
			}
		}
		return false;
	}
	
	public function MoveMessage($folderid, $id, $newfolderid)
	{
		return false;
	}
}
?>
