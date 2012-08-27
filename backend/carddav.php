<?php
/***********************************************
* File      :   CardDAV.php
* Project   :   SOGoSync
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               CardDAV interface
*
* Created   :   29.03.2012
*
* Copyright 2012 <xbgmsharp@gmail.com>
*
* Modified by Francisco Miguel Biete <fmbiete@gmail.com>
* Work with DaviCal
*
************************************************/

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/carddav.php');
include_once('include/z_RTF.php');
include_once('include/vCard.php');

class BackendCardDAV extends BackendDiff {
	// SOGoSync version
	const SOGOSYNC_VERSION = '0.4.0';
	// SOGoSync vcard Prodid
	const SOGOSYNC_PRODID = 'SOGoSync';

	private $_carddav;
	private $_carddav_path;
	private $_collection = array();

	/**
	 * Login to the CardDAV backend
	 * @see IBackend::Logon()
	 */
	public function Logon($username, $domain, $password)
	{
		// Confirm PHP-CURL Installed; If Not, Exit
		if (!function_exists("curl_init")) {
			ZLog::Write(LOGLEVEL_ERROR, sprintf("ERROR: Carddav Backend requires PHP-CURL"));
			return false;
		}

		$url = str_replace('%u', $username, CARDDAV_URL);
		ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCardDAV->Logon('%s')", $url));
		$this->_carddav = new carddav_backend($url);
		$this->_carddav->set_auth($username, $password);

		if ($this->_carddav->check_connection())
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCardDAV->Logon(): User '%s' is authenticated on CardDAV", $username));
			$this->url = $url;
			return true;
		}
		else
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCardDAV->Logon(): User '%s' is not authenticated on CardDAV", $username));
			return false;
		}
	}

	/**
	 * The connections to CardDAV are always directly closed. So nothing special needs to happen here.
	 * @see IBackend::Logoff()
	 */
	public function Logoff()
	{
		return true;
	}

	/**
	 * CardDAV doesn't need to handle SendMail
	 * @see IBackend::SendMail()
	 */
	public function SendMail($sm)
	{
		return false;
	}

	/**
	 * No attachments in CardDAV
	 * @see IBackend::GetAttachmentData()
	 */
	public function GetAttachmentData($attname)
	{
		return false;
	}

	/**
	 * Deletes are always permanent deletes. Messages doesn't get moved.
	 * @see IBackend::GetWasteBasket()
	 */
	public function GetWasteBasket()
	{
		return false;
	}

	/**
	 * Only 1 addressbook allowed.
	 * @see BackendDiff::GetFolderList()
	 */
	public function GetFolderList()
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetFolderList(): Getting all folders."));
		
		$folderlist = array();
		$folderlist[] = $this->StatFolder(CARDDAV_PRINCIPAL);

		return $folderlist;
	}

	/**
	 * Returning a SyncFolder
	 * @see BackendDiff::GetFolder()
	 */
	public function GetFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetFolder('%s')", $id));
		
		$folder = new SyncFolder();
		$folder->serverid = CARDDAV_PRINCIPAL;
		$folder->displayname = "CardDAV AddressBook";
		$folder->parentid = "0";
		$folder->type = SYNC_FOLDER_TYPE_USER_CONTACT;

		return $folder;
	}

	/**
	 * Returns information on the folder.
	 * @see BackendDiff::StatFolder()
	 */
	public function StatFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatFolder('%s')", $id));

		$val = $this->GetFolder($id);
		$folder = array();
		$folder["id"] = $id;
		$folder["parent"] = $val->parentid;
		$folder["mod"] = $val->displayname;
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatFolder(Abook Id [%s] Abook Name [%s])", $folder["id"], $folder["mod"]));
		return $folder;
	}

	/**
	 * ChangeFolder is not supported under CardDAV
	 * @see BackendDiff::ChangeFolder()
	 */
	public function ChangeFolder($folderid, $oldid, $displayname, $type)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
		return false;
	}

	/**
	 * DeleteFolder is not supported under CardDAV
	 * @see BackendDiff::DeleteFolder()
	 */
	public function DeleteFolder($id, $parentid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->DeleteFolder('%s','%s')", $id, $parentid));
		return false;
	}

	/**
	 * Get a list of all the messages.
	 * @see BackendDiff::GetMessageList()
	 */
	public function GetMessageList($folderid, $cutoffdate)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessageList('%s','%s')", $folderid, $cutoffdate));

		// Get list of vcard for one addressbook ($folderid)
		// for each vcard send the etag as MOD and the UID as ID

		$messagelist = array();
		$url = $this->url . $folderid . "/";
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessageList('%s')", $url));
		$this->_carddav->set_url($url);
		$vcardlist = $this->_carddav->get_all_vcards(false, false);
		if ($vcardlist === false)
		{
			ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV->GetMessageList(): Empty AddressBook"));
			return $messagelist;
		}
		$xmlvcardlist = new SimpleXMLElement($vcardlist);
		foreach ($xmlvcardlist->element as $vcard)
		{
			$id = (string)$vcard->id->__toString();
			//ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessageList(Add vcard to collection '%s')", $vcard->vcard->__toString()));
			$this->_collection[$id] = $vcard;
			$messagelist[] = $this->StatMessage($folderid, $id);
		}
		return $messagelist;
	}

	/**
	 * Get a SyncObject by its ID
	 * @see BackendDiff::GetMessage()
	 */
	public function GetMessage($folderid, $id, $contentparameters)
	{
		// for one vcard ($id) of one addressbook ($folderid)
		// send all vcard details in a SyncContact format
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessage('%s','%s')", $folderid,  $id));

		$data = null;
		// We have an ID and the vcard data
		if (array_key_exists($id, $this->_collection) && isset($this->_collection[$id]->vcard))
		{
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessage(array_key_exists and vcard)"));
		}
		else
		{
			$url = $this->url . $folderid . "/";
			$this->_carddav->set_url($url);
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->GetMessage('%s')", $url));
			$xmldata = $this->_carddav->get_xml_vcard($id);
			if ($xmldata === false)
			{
				ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV->GetMessage(): vCard not found"));
				return false;
			}
			$xmlvcard = new SimpleXMLElement($xmldata);
			foreach($xmlvcard->element as $vcard)
			{
				$this->_collection[$id] = $vcard;
			}
		}
		$data = (string)$this->_collection[$id]->vcard->__toString();
		return $this->_ParseVCardToAS($data, $contentparameters);
	}

	/**
	 * Return id, flags and mod of a messageid
	 * @see BackendDiff::StatMessage()
	 */
	public function StatMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatMessage('%s','%s')", $folderid,  $id));

		// for one vcard ($id) of one addressbook ($folderid)
		// send the etag as mod and the UUID as id
		// the same as in GetMsgList

		$data = null;
		// We have an ID and no vcard data
		if (array_key_exists($id, $this->_collection) && isset($this->_collection[$id]->id) && isset($this->_collection[$id]->etag))
		{
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatMessage(array_key_exists)"));
		}
		else
		{
			$url = $this->url . $folderid . "/";
			$this->_carddav->set_url($url);
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatMessage('%s')", $url));
			$xmldata = $this->_carddav->get_xml_vcard($id);
			if ($xmldata === false)
			{
				ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV->StatMessage(): VCard not found"));
				return false;
			}
			$xmlvcard = new SimpleXMLElement($xmldata);
			foreach($xmlvcard->element as $vcard)
			{
				$this->_collection[$id] = $vcard;
			}
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatMessage(get_xml_vcard true)"));
		}
		$data = $this->_collection[$id];
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->StatMessage(id '%s', mod '%s')", $data->id->__toString(), $data->etag->__toString()));
		$message = array();
		$message['id'] = (string)$data->id->__toString();
		$message['flags'] = "1";
		$message['mod'] = (string)$data->etag->__toString();
		return $message;
	}

	/**
	 * Change/Add a message with contents received from ActiveSync
	 * @see BackendDiff::ChangeMessage()
	 */
	public function ChangeMessage($folderid, $id, $message)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeMessage('%s','%s')", $folderid,  $id));
		if (defined(CARDDAV_READONLY) && CARDDAV_READONLY)
		{
			return false;
		}

		$data = null;
		$UUID = null;
		if ($id)
		{
			$data = $this->_ParseASCardToVCard($message, $id);
		}
		else
		{
			$UUID = $this->generate_uuid();
			$data = $this->_ParseASCardToVCard($message, $UUID);
		}

		$url = $this->url . $folderid . "/";
		$this->_carddav->set_url($url);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeMessage('%s')", $url));

		if ($id)
		{
			$this->_carddav->update($data, $id);
		}
		else
		{
			$id = $this->_carddav->add($data, str_replace(".vcf", null, $UUID));
		}

		return $this->StatMessage($folderid, $id);
	}

	/**
	 * Change the read flag is not supported.
	 * @see BackendDiff::SetReadFlag()
	 */
	public function SetReadFlag($folderid, $id, $flags)
	{
		return false;
	}

	/**
	 * Delete a message from the CardDAV server.
	 * @see BackendDiff::DeleteMessage()
	 */
	public function DeleteMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->DeleteMessage('%s','%s')", $folderid,  $id));
		if (defined(CARDDAV_READONLY) && CARDDAV_READONLY)
		{
			return false;
		}
		$url = $this->url . $folderid . "/";
		$this->_carddav->set_url($url);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->DeleteMessage('%s')", $url));
		return $this->_carddav->delete($id);
	}

	/**
	 * Move a message is not supported by CardDAV.
	 * @see BackendDiff::MoveMessage()
	 */
	public function MoveMessage($folderid, $id, $newfolderid)
	{
		return false;
	}

	/**
	 * Convert a VCard to ActiveSync format
	 * @param vcard $data
	 * @param ContentParameters $contentparameters
	 * @return SyncContact
	 */
	private function _ParseVCardToAS($data, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseVCardToAS(vCard[%s])", $data));
		$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

		$vCard = new vCard(false, $data);
		if (count($vCard) != 1)
		{
			ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV->_ParseVCardToAS(): Error parsing vCard[%s]", $data));
			return false;
		}

		$card = $vCard->access();

		$mapping = array(
			'fn' => 'fileas',
			'n' => array('LastName' => 'lastname', 'FirstName' => 'firstname'),
			//'nickname' => 'nickname', // handle manually
			'tel' => array('home' => 'homephonenumber',
						'cell' => 'mobilephonenumber',
						'work' => 'businessphonenumber',
						'fax' => 'businessfaxnumber',
						'pager' => 'pagernumber'),
			'email' => array('work' => 'email1address', 'home' => 'email2address'),
			'url' => array('work' => 'webpage', 'home' => 'webpage'), // does not exist in ActiveSync
			'bday' => 'birthday',
			//'role' => 'jobtitle', iOS take it as 'TITLE' Does not make sense??
			'title' => 'jobtitle',
			'note' => 'body',
			'org' => array('Name' => 'companyname', 'Unit1' => 'department'),
			'adr' => array ('work' =>
								array('POBox' => '',
									'ExtendedAddress' => '',
									'StreetAddress' => 'businessstreet',
									'Locality' => 'businesscity',
									'Region' => 'businessstate',
									'PostalCode' => 'businesspostalcode',
									'Country' => 'businesscountry'),
								'home' =>
								array('POBox' => '',
									'ExtendedAddress' => '',
									'StreetAddress' => 'homestreet',
									'Locality' => 'homecity',
									'Region' => 'homestate',
									'PostalCode' => 'homepostalcode',
									'Country' => 'homecountry')
			),
			'photo' => array('jpeg' => 'picture'),
			//'categories' => 'categories', // handle manually
			'x-aim' => 'imaddress',
		);

		$message = new SyncContact();

		foreach ($mapping as $vcard_attribute => $ms_attribute)
		{
			//ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseVCardToAS(vCard[%s] => ms[%s])", $vcard_attribute, $ms_attribute));
			if (empty($card[$vcard_attribute]))
			{
				continue;
			}
			if (is_array($card[$vcard_attribute]))
			{
				// tel;email;url;org
				foreach ($card[$vcard_attribute] as $key => $value) {
					if (empty($value) || empty($ms_attribute[$key]))
					{
						continue;
					}
					// adr
					if (is_array($value))
					{
						foreach ($value as $adrkey => $adrvalue) {
							if (empty($adrvalue) || empty($ms_attribute[$key][$adrkey]))
							{
								continue;
							}
							$message->$ms_attribute[$key][$adrkey] = $adrvalue;
						}
					}
					else
					{
					 $message->$ms_attribute[$key] = $value;
					}
				}
			}
			else
			{
				if ($vcard_attribute === "note" && !empty($card[$vcard_attribute]))
				{
					$body = $card[$vcard_attribute];
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
					$message->bodysize = strlen($body);
				}
				else if ($vcard_attribute === "bday" && !empty($card[$vcard_attribute]))
				{
					$tz = date_default_timezone_get();
					date_default_timezone_set('UTC');
					$message->$ms_attribute = strtotime($card[$vcard_attribute]);
					date_default_timezone_set($tz);
				}
				else
				{
					$message->$ms_attribute = $card[$vcard_attribute];
				}
			}
		}

		if ( isset($card['nickname']) && !empty($card['nickname']) && is_array($card['nickname']) )
		{
			$message->nickname = $card['nickname'][0];
		}
		if ( isset($card['categories']) && !empty($card['categories']) )
		{
			$message->categories = implode(',', $card['categories']);
			$itemTagNames = array();
			for ($i=0;$i<count($card['categories']);$i++) {
				//print $card['categories'][$i] ."\n";
				$itemTagNames[] = $card['categories'][$i];
			}
			//print "message->categories=". $card['categories'] ."\n";
			$message->categories = $itemTagNames;
		}

		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseVCardToAS(vCard fileas [%s])", $message->fileas));
		return $message;
	}

	/**
	 * Generate a VCard from a SyncContact(Exception).
	 * @param string $data
	 * @param string $id
	 * @return VCard
	 */
	private function _ParseASCardToVCard($message, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseASCardToVCard()"));

		$mapping = array(
			'fileas' => 'FN',
			'lastname;firstname' => 'N',
			'nickname' => 'NICKNAME',
			'homephonenumber' => 'TEL;TYPE=home',
			'mobilephonenumber' => 'TEL;TYPE=cell',
			'businessphonenumber' => 'TEL;TYPE=work',
			'businessfaxnumber' => 'TEL;TYPE=fax',
			'pagernumber' => 'TEL;TYPE=pager',
			'email1address' => 'EMAIL;TYPE=work',
			'email2address' => 'EMAIL;TYPE=home',
			//'webpage' => 'URL;TYPE=home', does not exist in ActiveSync
			'webpage' => 'URL;TYPE=work',
			//'birthday' => 'BDAY', // handle separetly
			//'jobtitle' => 'ROLE', // iOS take it as 'TITLE' Does not make sense??
			'jobtitle' => 'TITLE',
			'body' => 'NOTE',
			'companyname;department' => 'ORG',
			';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;TYPE=work',
			';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;TYPE=home',
			//'picture' => 'PHOTO;BASE64', // handle separetly
			//'categories' => 'CATEGORIES', // handle separetly, but i am unable to create categories form iOS
			'imaddress' => 'X-AIM',
		);

		$data = "BEGIN:VCARD\n";
		$data .= "UID:". $id .".vcf\n";
		$data .= "VERSION:3.0\nPRODID:-//". self::SOGOSYNC_PRODID ." ". self::SOGOSYNC_VERSION ."//NONSGML ". self::SOGOSYNC_PRODID . " AddressBook//EN\n";

		foreach($mapping as $ms => $vcard){
			$val = '';
			$value = explode(';', $ms);
			foreach($value as $i)
			{
				if(!empty($message->$i))
				{
					$val .= $message->$i;
					$val.=';';
				}
			}
			$val = substr($val,0,-1);
			if(empty($val)) { continue; }
			$data .= $vcard.":".$val."\n";
		}
		if(!empty($message->categories))
			$data .= "CATEGORIES:".implode(',', $message->categories)."\n";
		if(!empty($message->picture))
			// FIXME first line 50 char next one 74
			// Apparently iOS send the file on BASE64
			$data .= "PHOTO;ENCODING=BASE64;TYPE=JPEG:".substr(chunk_split($message->picture, 50, "\n "), 0, -1);
		if(isset($message->birthday))
			$data .= "BDAY:".date('Y-m-d', $message->birthday)."\n";
		$data .= "END:VCARD";

		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseASCardToVCard('vCard[%s]", $data));
		return $data;
	}

	/**
	 * Generate a VCard UID.
	 * @return UID
	 */
	private function generate_uuid()
	{
		// Which format?
		$md5 = md5(uniqid('', true));

		// 20120427T111858Z-6F53-4F561080-F-7B4FC200
		return strtoupper(gmdate("Ymd\THis\Z") .'-'. substr($md5, 0, 4 ) . '-' .
			substr($md5, 4, 8) . '-' .
			substr($md5, 12, 1) . '-' .
			substr($md5, 14, 8));
	}
}

?>
