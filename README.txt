This is a set of files to allow CiviCRM to use the WorldPay
payment gateway system. It is based upon code first
developed by Dougall Winship.

This should be considered to be experiemental code which
should be extensively tested before being used in a
production environment.

PLEASE NOTE: A row needs to be added to the
civicrm_payment_processor_type table in order for CiviCRM
to register the existence of the WorldPay support. You
should either run the included addworldpayprocessor.php
script or manually add the apporopriate row to the database
in order for the payment gateway to be listed within the
CiviCRM interface.

The latest version of this code is currently available at
https://github.com/idmacdonald/civicrm-worldpay.git

Please email imac@gn.apc.org with any questions or contributions.

-Ian Macdonald
 GreenNet Ltd (http://www.gn.apc.org)
