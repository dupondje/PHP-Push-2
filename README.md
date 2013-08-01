PHP-Push-2
========

PHP-Push-2 is a modiefied version of [Z-Push]-2, an open source ActiveSync implementation, with CalDAV and CardDAV support.

Features
--------

Using the "combined backend" PHP-Push-2 supports the following features:

  * Mail - IMAP
  * Calendar - CalDAV
  * Contacts - LDAP
  * Contacts - CardDAV

Requirements
-----------
  * A supported CalDAV/CardDAV server (e.g. [SOGo], [ownCloud], [SabreDAV])
    * Did not test other than SOGo but it should work with any caldav/cardav groupware, feedback are welcome
  * An [ActiveSync compatible](http://en.wikipedia.org/wiki/Comparison_of_Exchange_ActiveSync_clients) mobile device
  * PHP5 with the following libraries are required:
    * php5-curl
    * php5-ldap for LDAP support
    * libawl-php for CardDAV and CalDAV support
    * php5-imap and php-mail for IMAP support
  
Debian/Ubuntu systems

        $ apt-get install php5-curl php5-ldap php5-imap php-mail libawl-php


Redhat systems

        $ yum install php-curl php-common php-ldap php-imap php-imap libawl-php

Thanks
------

PHP-Push-2 is possible thanks to the following projects:

  * [SOGo] Open Groupware
  * [Z-Push] Open Source ActiveSync implementation
  * [CardDAV-PHP]
  * [vCard-parser]


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

  * [CardDAV-Client]
    * Thanks to Christian Putzke for updating his library
  * [vCard-parser]
    * Thanks to Nuovo for updating his library
  * [CalDAV-Client]

Donate
------------

[![PayPal - Donate](https://www.paypal.com/en_US/i/btn/btn_donate_LG.gif)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=TMZ6YBPDLAN84&lc=US&item_name=A%20more%20awesome%20PHP-Push-2&currency_code=EUR&bn=PP%2dDonationsBF%3abtn_donate_LG%2egif%3aNonHosted)

We are building PHP-Push-2 in our spare time, so if you want to buy us a coke, that would be awesome!

Installation
------------

Clone from Github:

    $ cd /var/www
    $ git clone https://github.com/dupondje/PHP-Push-2.git
    $ cd PHP-Push-2

Read the Z-Push install instructions in the INSTALL file, or this document: [Configure Z-Push (Remote ActiveSync for Mobile Devices)](http://doc.zarafa.com/7.0/Administrator_Manual/en-US/html/_zpush.html).

Note: Z-Push is meant to be used with mod_php. If you want to use it with FastCGI additional configuration is needed for the Apache web server. Please refer to the [wiki].

Configuration
-------------

### Deploy a PHP-Push-2 instance for the [SOGo Online Demo]
The following guide sets up your PHP-Push-2 instance for the [SOGo Online Demo].

    $ cp config.inc.php config.php
    $ cp backend/combined/config.inc.php backend/combined/config.php

* Permissions

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

Using a browser login to https://fqdn/Microsoft-Server-ActiveSync. You should see a webpage that says "Z-Push - Open Source ActiveSync" stating "GET not supported."

If this page is not displayed, please READ the [wiki].

Update
------

To update to the latest version pull from the Git repository:

    $ cd /var/www/PHP-Push-2
    $ git pull


Contributing
------------

1. Fork
2. Create a branch (`git checkout -b my_markup`)
3. Commit your changes (`git commit -am "Added Snarkdown"`)
4. Push to the branch (`git push origin my_markup`)
5. Create an [Issue][1] with a link to your branch
6. Or Send me a [Pull Request][2]


[1]: https://github.com/dupondje/PHP-Push-2/issues
[2]: https://github.com/dupondje/PHP-Push-2/pull/new/master
[CardDAV-PHP]: https://github.com/graviox/CardDAV-PHP
[CardDAV-Client]: https://github.com/graviox/CardDAV-PHP
[CalDAV-Client]: http://wiki.davical.org/w/Developer_Setup
[ownCloud]: http://owncloud.org
[SabreDAV]: http://code.google.com/p/sabredav
[SOGo]: http://www.sogo.nu
[SOGo Online Demo]: http://www.sogo.nu/english/tour/online_demo.html
[vCard-parser]: https://github.com/nuovo/vCard-parser
[wiki]: https://github.com/dupondje/PHP-Push-2/wiki
[Z-Push]: http://z-push.sourceforge.net