<?php
/**
 * Sets up civicrm for the worldpay payment processor. This script is written to
 * run from the command line (PHP CLI) hence \n rather than <br> in the echos.
 *   
 * Tasks:
 *   
 * 1. adds worldpay payment processor details to civicrm_payment_processor_type
 *  
 * 2. adds a new table to civicrm for recording futurepay payment ids so the
 *    IPN can be identified and processed by civicrm.
 * 
 * !! IMPORTANT : Do not leave this script on the server !!
 *   
 */ 

 // todo:- make a mysql connection to the civicrm database here!
 //
 // [ in the original code I opened a connection to the civicrm db using an
 //   include file which also did a bunch of other stuff ... here you'll have 
 //   to add the code to do this manually for yourself instead... ]

 $query = "
SELECT id FROM civicrm_payment_processor_type
WHERE name='WorldPay'";
 echo "mysql>$query\n";
 $id = CRM_Core_DAO::singleValueQuery( $query, CRM_Core_DAO::$_nullArray );
 if ($id) {
   die("WorldPay payment processor already exists in the CiviCRM database\n");
 }

 // For Billing Modes see /CRM/Core/Payment.php
 //   BILLING_MODE_FORM   = 1 - form
 //   BILLING_MODE_BUTTON = 2 - button
 //                         3 - paypal specific 
 //   BILLING_MODE_NOTIFY = 4 - transfer checkout

 $setFields = array (
'domain_id' => 1,
'name' => 'WorldPay',
'title' => 'WorldPay',
//'description' => null,
'is_active' => 1,
'is_default' => 0,
'user_name_label' => 'User Name',
'password_label' => 'Password',
'signature_label' => 'Signature',
'subject_label' => 'Subject',
'class_name' => 'Payment_WorldPay',
'url_site_default' => 'https://select.worldpay.com/wcc/purchase',
//'url_api_default' => null,
'url_recur_default' => 'https://select.worldpay.com/wcc/purchase',
//'url_button_default' => null,
'url_site_test_default' => 'https://select-test.worldpay.com/wcc/purchase',
//'url_api_test_default' => null,
'url_recur_test_default' => 'https://select-test.worldpay.com/wcc/purchase',
//'url_button_test_default' => null,
'billing_mode' => 4,
'is_recur' => 1);

 // this way doesn't handle nulls properly so comment 'em out above instead...
 $fieldNames = implode(",",array_keys($setFields));
 $fieldValues = "'".implode("','",array_values($setFields))."'";
 $query = "
INSERT INTO civicrm_payment_processor_type($fieldNames) VALUES($fieldValues)";

 echo "mysql>$query\n";
 $dao = CRM_Core_DAO::executeQuery( $query, CRM_Core_DAO::$_nullArray );

 if ($dao) {
   echo "Added WorldPay processor type to the CiviCRM database\n";
 }
 else {
   echo "Failed to add WorldPay processor type to the CiviCRM database\n";

 }


if (addContactLinkTable()) {
  echo "WorldPay FuturePay Table Added to CiviCRM\n";
}
else {
  echo "WorldPay FuturePay Table FAILED TO BE ADDED!!\n";
}

////////////////////////////////////////////////////////////////////////////////

function addContactLinkTable() {
  global $civicrm_root;
  require_once $civicrm_root."/CRM/Core/Error.php";
  $query = "
CREATE TABLE worldpay_futurepay_ids (
futurepay_id int(10) unsigned not null primary key,
contact_id int(10) unsigned not null,
contribution_id int(10) unsigned not null,
contribution_recur_id int(10) unsigned not null,
contribution_page_id int(10) unsigned not null,
membership_id int(10) unsigned )";
  $dao = CRM_Core_DAO::executeQuery( $query, CRM_Core_DAO::$_nullArray );
  $msg = "adding table=worldpay_futurepay_ids mysql=$query result=".print_r($dao,true);
  CRM_Core_Error::debug_log_message($msg);
  error_log($msg);
  return ($dao?true:false);
}

?>
