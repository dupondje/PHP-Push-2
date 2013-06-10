This version has been patched to add Client Certificate Authentication.

config.php offers two new directives, not found in the original distri-
bution.

These are:

SYNC_REQUIRE_CLIENT_CRT_USERNAME_IN

	Contains the Name of the ENV Variable which holds the
	Username out of the Client CRT.

	Default: FALSE (which means disabled)

SYNC_REQUIRE_CLIENT_CRT_ISSUER

	Contains the Subject of your Client-signing CA CRT.
	Checks the Client CRT Issuer DN against a given Subject
	of your "real" CA CRT.
	May only be useful for the paranoids. See code comments.
	
	This directive can only be used in conjunction with
	SYNC_REQUIRE_CLIENT_CRT_USERNAME_IN. Otherwise it'ld
	render really useless.
	
	Default: FALSE (which means disabled)


If this directives are not found, e.g. by using the config.php of an
unpatched Z-Push Version, or if they are configured to FALSE (which is
the initial default value), the behavior of Z-Push is left untouched.

If (and I assume this is the reason for you to use this patched version)
you want to use Client Certificate Authentication, follow these steps:


Preface: Username and Password are *not* obsoleted. The Client Certificate
  will be checked ON TOP. Exactly:
    - Username has to match an existing user
    - Password has to match the respective users password
    + "Some" (desciribed below) Client CRT Part has to match the Username

Prerequisites:

Apache mod_ssl configured and enabled. The Server CRT used for the particular
Host for Z-Push can be configured as usual and doesn't have to (but MAY) be
signed by the Client-signing CA.

Client CA CRT Setup (Usually done on a private machine, NOT on the webserver):
------------------------------------------------------------------------------
E.g.
openssl genrsa -out ca.key 2048
openssl req -new -key ca.key -out ca.csr
openssl x509 -req -days 365 -in ca.csr -signkey ca.key -out ca.crt

The ca.crt needs to be copied to the Webserver.

E.g.
<VirtualHost _default_:443>
...
# Leave that part configured. We want to trust or revoke the big players. 
SSLCertificatePath /etc/ssl/crt/
# Your CA is an *additional* trustsource.
SSLCACertificateFile /etc/ssl/zimbra-clients/ca.crt
...
</VirtualHost>

Let your clients generate their CSR (or maybe do it for them ...):
------------------------------------------------------------------
E.g.
openssl genrsa -out client.key 2048
openssl req -new -key client.key -out client.csr

!!! Mind to use a particular DN Part for the Username !!!
!!! Mind to write down which Part of the DN is used.. !!!
!!! For this Example we assume CN (CommonName)        !!!

For additional paranoia, also note (once, as it's always
the same) the subject of your ca.crt
E.g.
openssl x509 -noout -in ./ca.crt -subject | sed 's,^subject= ,,g'


Sign your clients CSR (Remember, that's on your private machine):
-----------------------------------------------------------------
E.g.
openssl x509 -req -days 365 -CA ca.crt -CAkey ca.key -CAcreateserial -in client.csr -out client.crt

And finally send the client.crt's back to the respective clients.

Usually, your clients will have to change them into PKCS12.
E.g.
openssl pkcs12 -export -clcerts -in client.crt -inkey client.key -out client.p12

Sometimes they'll need a ordinary PEM, but this depends.
E.g.
cat client.key client.crt > client.pem


Now have your clients fiddling their certificates into their apps, meanwhile prepare the Webserver:
---------------------------------------------------------------------------------------------------
E.g.
<VirtualHost _default_:443>
...
<Location /z-push>
   SSLRequireSSL
   SSLVerifyClient require
   SSLVerifyDepth 10
</Location>
...
</VirtualHost>

Configure your Z-Push as usual. Additionally configure the two shiny new directives:

Define the part of the Client CRT which contains the Username (we assumed CN above)
E.g.
define('SYNC_REQUIRE_CLIENT_CRT_USERNAME_IN', 'SSL_CLIENT_S_DN_CN');

Refer to the Cheat Sheet below for the key representatives of the DN.

For additional paranoia, define the Subject of your CA.CRT you noted above. Or, leave it FALSE.
E.g.
define('SYNC_REQUIRE_CLIENT_CRT_ISSUER', '/C=DE/ST=Berlin/L=Berlin/O=My Lovely Own CA/OU=Testing (CA-OU)/CN=my-lovely-own-ca.example.org/emailAddress=s.seitz@heinlein-support.de');



Your Done!

- Stephan Seitz <s.seitz@heinlein-support.de>



--- Cheat Sheet of the Key/Value Pairs as seen in the ENV of a recent apache ---

Array
(
    [HTTPS] => on
    [SSL_TLS_SNI] => localhost
    [SSL_SERVER_S_DN_C] => DE
    [SSL_SERVER_S_DN_ST] => Berlin
    [SSL_SERVER_S_DN_L] => Berlin
    [SSL_SERVER_S_DN_O] => My Lovely Own Webserver
    [SSL_SERVER_S_DN_OU] => Testing (Server-OU)
    [SSL_SERVER_S_DN_CN] => localhost
    [SSL_SERVER_S_DN_Email] => s.seitz@heinlein-support.de
    [SSL_SERVER_I_DN_C] => DE
    [SSL_SERVER_I_DN_ST] => Berlin
    [SSL_SERVER_I_DN_L] => Berlin
    [SSL_SERVER_I_DN_O] => My Lovely Own Webserver
    [SSL_SERVER_I_DN_OU] => Testing (Server-OU)
    [SSL_SERVER_I_DN_CN] => localhost
    [SSL_SERVER_I_DN_Email] => s.seitz@heinlein-support.de
    [SSL_VERSION_INTERFACE] => mod_ssl/2.2.22
    [SSL_VERSION_LIBRARY] => OpenSSL/1.0.1
    [SSL_PROTOCOL] => TLSv1
    [SSL_SECURE_RENEG] => true
    [SSL_COMPRESS_METHOD] => NULL
    [SSL_CIPHER] => DHE-RSA-CAMELLIA256-SHA
    [SSL_CIPHER_EXPORT] => false
    [SSL_CIPHER_USEKEYSIZE] => 256
    [SSL_CIPHER_ALGKEYSIZE] => 256
    [SSL_CLIENT_VERIFY] => NONE
    [SSL_SERVER_M_VERSION] => 1
    [SSL_SERVER_M_SERIAL] => DC184014C3AD5D13
    [SSL_SERVER_V_START] => Feb  7 09:46:40 2013 GMT
    [SSL_SERVER_V_END] => Feb  7 09:46:40 2014 GMT
    [SSL_SERVER_S_DN] => /C=DE/ST=Berlin/L=Berlin/O=My Lovely Own Webserver/OU=Testing (Server-OU)/CN=localhost/emailAddress=s.seitz@heinlein-support.de
    [SSL_SERVER_I_DN] => /C=DE/ST=Berlin/L=Berlin/O=My Lovely Own Webserver/OU=Testing (Server-OU)/CN=localhost/emailAddress=s.seitz@heinlein-support.de
    [SSL_SERVER_A_KEY] => rsaEncryption
    [SSL_SERVER_A_SIG] => sha1WithRSAEncryption
    [SSL_SESSION_ID] => 87A84BAC21DAEA733D94C7D57E97C5EFB95F336606C36BF0077D4E89596E9C18
    [SSL_SERVER_CERT] => -----BEGIN CERTIFICATE-----
MIID3DCCAsQCCQDcGEAUw61dEzANBgkqhkiG9w0BAQUFADCBrzELMAkGA1UEBhMC
REUxDzANBgNVBAgMBkJlcmxpbjEPMA0GA1UEBwwGQmVybGluMSAwHgYDVQQKDBdN
eSBMb3ZlbHkgT3duIFdlYnNlcnZlcjEcMBoGA1UECwwTVGVzdGluZyAoU2VydmVy
LU9VKTESMBAGA1UEAwwJbG9jYWxob3N0MSowKAYJKoZIhvcNAQkBFhtzLnNlaXR6
QGhlaW5sZWluLXN1cHBvcnQuZGUwHhcNMTMwMjA3MDk0NjQwWhcNMTQwMjA3MDk0
NjQwWjCBrzELMAkGA1UEBhMCREUxDzANBgNVBAgMBkJlcmxpbjEPMA0GA1UEBwwG
QmVybGluMSAwHgYDVQQKDBdNeSBMb3ZlbHkgT3duIFdlYnNlcnZlcjEcMBoGA1UE
CwwTVGVzdGluZyAoU2VydmVyLU9VKTESMBAGA1UEAwwJbG9jYWxob3N0MSowKAYJ
KoZIhvcNAQkBFhtzLnNlaXR6QGhlaW5sZWluLXN1cHBvcnQuZGUwggEiMA0GCSqG
SIb3DQEBAQUAA4IBDwAwggEKAoIBAQDIpAHlQN1aSXT6oUQAmyjVNE+ps7l0minn
chOQ0dNSgftQRi1/I60Hik08WpozLO5BffmVw7E85J5MUVAF4STU/dvoAPDfNoLV
mKtdHjeBJ7//F+AeHfMeiH2Pp/z+kAY4jX3oxgtfRBUl99U5Kn7i61cH3w4Zv/X0
iihCSMaBEjCRAHwHmpk85YQWmf8ZWWJaruQLWAr7pBE8kyLay9LbsCPCBnSR4Ehx
tRw+pvx0pQZMpUQUTC6OJ1NL6RP8MBlkEPsoggt41OBD9IqXwGlXK5eXSn7LZeNg
z5WEXbgg05nMr1Ezk1QFPwlkzmGkdvymI7ic+ko4F5qjyzs6qjJVAgMBAAEwDQYJ
KoZIhvcNAQEFBQADggEBALlk9h5b2oeuvXGlmQeQ23TJNsmzvsr6MyPLNprG5/fF
vcFUDpfJ++C0Wi4BeyN/1mEia5vjoGEiFC4/V0QmCRw6AZ0FbbLN2Sd+YSNGEWzP
k135N4afju92r+JMhip9mJN+fjsGw5nw70F5Ivix1DRw68Yl28E88xyIdL60U00A
hmNeC3d4ApAg7CpfUPaZNoErXa/PnXv9tbwQFExHVYMG570xkBsZgd1KahwG1oc7
QMcQTyxMEAtuF9JaZTu4ijwPjtprBmXFGqB2vP4k1kxNO2grrwnH7SgAV0Ke3s9C
ctdm3NFn6JNfyAJU2pgXNjCRukdlP9YwjGyKd+dlMdc=
-----END CERTIFICATE-----

    [SSL_CLIENT_CERT] => 
    [HTTP_HOST] => localhost
    [HTTP_USER_AGENT] => Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:18.0) Gecko/20100101 Firefox/18.0
    [HTTP_ACCEPT] => text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8
    [HTTP_ACCEPT_LANGUAGE] => de-de,de;q=0.8,en-us;q=0.5,en;q=0.3
    [HTTP_ACCEPT_ENCODING] => gzip, deflate
    [HTTP_REFERER] => https://localhost/
    [HTTP_CONNECTION] => keep-alive
    [PATH] => /usr/local/bin:/usr/bin:/bin
    [SERVER_SIGNATURE] => 
    [SERVER_SOFTWARE] => Apache
    [SERVER_NAME] => localhost
    [SERVER_ADDR] => 127.0.0.1
    [SERVER_PORT] => 443
    [REMOTE_ADDR] => 127.0.0.1
    [DOCUMENT_ROOT] => /home/s.seitz/workspace/
    [SERVER_ADMIN] => webmaster@localhost
    [SCRIPT_FILENAME] => /home/s.seitz/workspace/cert-test-index.php
    [REMOTE_PORT] => 33470
    [GATEWAY_INTERFACE] => CGI/1.1
    [SERVER_PROTOCOL] => HTTP/1.1
    [REQUEST_METHOD] => GET
    [QUERY_STRING] => 
    [REQUEST_URI] => /cert-test-index.php
    [SCRIPT_NAME] => /cert-test-index.php
    [PHP_SELF] => /cert-test-index.php
    [REQUEST_TIME] => 1360315109
)

