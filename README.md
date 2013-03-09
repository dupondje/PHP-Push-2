PHP-Push-2
========

Description
-----------

PHP-Push-2 is Z-Push-2 - Open Source ActiveSync - from SVN upstream with CalDAV and CardDAV backend support.

Features
--------

Use combined backends from Z-Push-2 to allow:

* Mail - IMAP
  * Requirements: IMAP Server

* Calendar - CalDAV
  * Requirements: libawl-php

* Contacts - LDAP
  * Requirements: LDAP Server

* Contacts - CardDAV
  * Requirements: libawl-php

Requirements
-----------
* A working caldav/carddav server (e.g. SOGo,ownCloud,SabreDAV)
  * Did not test other than SOGo but it should work with any caldav/cardav groupware, feedback are welcome.
* An ActiveSync compatible mobile device
	* [Comparison of Exchange ActiveSync clients](http://en.wikipedia.org/wiki/Comparison_of_Exchange_ActiveSync_clients)
* PHP5 with the following libraries for a Debian/Ubuntu system

        $ apt-get install php5-curl php5-ldap php5-imap php-mail libawl-php


* PHP5 with the following libraries for a Redhat system

        $ yum install php-curl php-common php-ldap php-imap php-imap libawl-php


* libawl-php is part of Redhat and Debian, however it is not available for SME and CentOS. You can find the package at http://debian.mcmillan.net.nz/packages/awl/

Thanks
------

PHP-Push-2 is possible thanks to the following projects:

* [Open Groupware](http://www.sogo.nu/)
* [Open Source ActiveSync implementation](http://z-push.sourceforge.net/soswp)
* [CardDAV-PHP](https://github.com/graviox/CardDAV-PHP)
* [vCard-parser](https://github.com/nuovo/vCard-parser/)


See also
-------

* CarDAV and CalDAV RFC:
  * http://tools.ietf.org/html/rfc6350
  * http://tools.ietf.org/html/rfc2425
  * http://tools.ietf.org/html/rfc4791
  * http://tools.ietf.org/html/rfc2426

* ActiveSync Contact and Calendar Protocol Specification
  * http://msdn.microsoft.com/en-us/library/cc425499%28EXCHG.80%29.aspx
  * http://msdn.microsoft.com/en-us/library/dd299451(v=exchg.80).aspx
  * http://msdn.microsoft.com/en-us/library/dd299440(v=exchg.80).aspx
  * http://msdn.microsoft.com/en-us/library/cc463911(v=exchg.80).aspx

Libraries used
------------

* [CardDAV-Client](https://github.com/graviox/CardDAV-PHP/)
	* Thanks to Christian Putzke for updating his library
* [vCard-parser](https://github.com/nuovo/vCard-parser/)
	* Thanks to Nuovo for updating his library
* [CalDAV-Client](http://wiki.davical.org/w/Developer_Setup)

Donate
------------

[![PayPal - Donate](https://www.paypal.com/en_US/i/btn/btn_donate_LG.gif)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=TMZ6YBPDLAN84&lc=US&item_name=A%20more%20awesome%20PHP-Push-2&currency_code=EUR&bn=PP%2dDonationsBF%3abtn_donate_LG%2egif%3aNonHosted)

We are building PHP-Push-2 in my spare time, so if you want to buy me a coke while I'm coding, that would be awesome!


Installation
------------

Clone from github:

    $ cd /var/www
    $ git clone https://github.com/dupondje/PHP-Push-2.git
    $ cd PHP-Push-2


Read the Z-Push install instructions in the INSTALL file, or this document: [Configure Z-Push (Remote ActiveSync for Mobile Devices)](http://doc.zarafa.com/7.0/Administrator_Manual/en-US/html/_zpush.html)

Note: Z-Push is mean to be use with mod_php, if using FastCGI, additional configuration are need in Apache, please refer to the [wiki](https://github.com/xbgmsharp/PHP-Push-2/wiki).


Configuration
-------------
If you wan to use the configuration sample to work with SOGo online demo.

Those files are pre-configured to work with the [SOGo Online Demo](http://www.sogo.nu/english/tour/online_demo.html)


File 'config.php' is the original file from Z-Push SVN repository:

    $ cp config.php config.php.org
    $ cp config.inc.php config.php

File 'backend/combined/config.php' is the original file from Z-Push SVN repository:

Nothing is need to be change in this file. It only combined 3 backends.

    $ cp backend/combined/config.php backend/combined/config.php.org
    $ cp backend/combined/config.inc.php backend/combined/config.php

Permission

    $ mkdir -p /var/lib/z-push/ /var/log/z-push/

* Debian system

        $ chown -R www-data:www-data /var/log/z-push/ /var/lib/z-push/


* RedHat system

        $ chown -R apache:apache /var/log/z-push/ /var/lib/z-push/


### Edit config.php
 * Set TimeZone
 * Configure the BackendIMAP settings section
 * Configure the BackendCARDDAV setting section
 * Configure the BackendCALDAV setting section

### Edit backend/searchldap/config.php
 * This file allows you to enable GAL search support from your LDAP tree.

Test
----
Using a browser, login to https://sogo.mydomain.com/Microsoft-Server-ActiveSync

You should see a webpage that says "Z-Push - Open Source ActiveSync" with the message "GET not supported."

If so, congratulations!

If not, please READ the [wiki](https://github.com/dupondje/PHP-Push-2/wiki).

You can now configure your smartphone or tablet.


Update
------
To update to the latest version:

    $ cd /var/www/PHP-Push-2
    $ git pull


Contributing
------------

1. Fork it
2. Create a branch (`git checkout -b my_markup`)
3. Commit your changes (`git commit -am "Added Snarkdown"`)
4. Push to the branch (`git push origin my_markup`)
5. Create an [Issue][1] with a link to your branch
6. Or Send me a [Pull Request][2]

[1]: https://github.com/dupondje/PHP-Push-2/issues
[2]: https://github.com/dupondje/PHP-Push-2/pull/new/master

