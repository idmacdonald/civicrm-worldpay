<?php

/**
 * CiviCRM payment processor for WorldPay (closely based on the other processors).
 *
 * Copyright (C) 2011-2013 GreenNet Ltd (imac@gn.apc.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

require_once 'CRM/Core/Payment/BaseIPN.php';
require_once 'CRM/Utils/Request.php';

class CRM_Core_Payment_WorldPayIPN extends CRM_Core_Payment_BaseIPN {

  static $_paymentProcessor = null;

  function __construct( ) {
    parent::__construct( );
  }

  /**
   * retrieve a value from the specified location
   */
  static function retrieve( $name, $type, $location = 'POST', $abort = true ) {
    static $store = null;
    $value = CRM_Utils_Request::retrieve( $name, $type, $store,
                                          false, null, $location );
    if ( $abort && $value === null ) {
      error_log(__FILE__.":".__FUNCTION__." : Could not find a required entry for $name in $location" );
      exit();
    }
    return $value;
  }

  /**
   * handle an actual future pay payment, this is distinct from
   * the initial futurepay agreement IPN which is sent when
   * the agreement is first made by the user
   */
  function handleFuturePayPayment(&$input, &$ids, &$objects) {
    $recur =& $objects['contributionRecur'];
   
    // make sure the invoice ids match
    // make sure the invoice is valid and matches what we have in the contribution record
    if ( $recur->invoice_id != $input['invoice'] ) {
      error_log(__FILE__.":".__FUNCTION__." : Failure : Invoice values dont match between database and IPN request");
      return false;
    }

    $now = date( 'YmdHis' );
 
    // fix dates that already exist
    $dates = array( 'create', 'start', 'end', 'cancel', 'modified' );
    foreach ( $dates as $date ) {
      $name = "{$date}_date";
      if ( $recur->$name ) {
        $recur->$name = CRM_Utils_Date::isoToMysql( $recur->$name );
      }
    }

    //contribution_status_id:
    //0=Completed,1=Pending,2=Cancelled,3=Overdue,4=Failed,5=InProgress
    if ($input['transStatus']=='Y') {//rawAuthMessage=Authorised
      // futurepay payment accepted
     if ($input['rawAuthMessage']!='Authorised') {
        error_log(__FILE__.":".__FUNCTION__." : Warning futurepay transStatus=".$input['transStatus']." but rawAuthMessage=".$input['rawAuthMessage']);
      }
      if (_getContributionCount($recur)>=$recur->installments) {
        // final installement
        $recur->contribution_status_id=0;//0=Completed
        $recur->end_date=$now;
      }
      else {
        $recur->contribution_status_id=5;//5=In Progress
        $recur->modified_date = $now;
      }
    }
    else if ($input['transStatus']=='N') {//rawAuthMessage=Declined
      // futurepay payment declined
      $recur->contribution_status_id=4;//4=Failed
      $recur->end_date = $now;
      if ($input['rawAuthMessage']!='Declined') {
        error_log(__FILE__.":".__FUNCTION__." : Warning futurepay transStatus=".$input['transStatus']." but rawAuthMessage=".$input['rawAuthMessage']);
      }
      $checkForEndOfAgreement=false;
    }
    else if ($input['futurePayStatusChange']=='Merchant Cancelled') {
      $recur->contribution_status_id=3;//3= cancelled
      $recur->cancel_date = $now;
      $input['reasonCode']='FuturePay cancelled by Merchant';
    }
    else if ($input['futurePayStatusChange']=='Customer Cancelled') {
      $recur->contribution_status_id=3;//3= cancelled
      $recur->cancel_date = $now;
      $input['reasonCode']='FuturePay cancelled by Donor';
    }
    else {
      error_log(__FILE__.":".__FUNCTION__." : Unrecognized FuturePay operation. input=".print_r($input,true));
      return false;
    }
    $recur->save();
    
    if ($input['transStatus']=='Y') {
      // create a new contribution for this recurring contribution
      $contributionType = $objects['contributionType'];
      $contribution =& new CRM_Contribute_DAO_Contribution();
      $contribution->domain_id = CRM_Core_Config::domainID( );
      $contribution->contact_id = $ids["contact"];
      $contribution->contribution_type_id = $contributionType->id;
      $contribution->contribution_page_id = $ids['contributionPage'];
      $contribution->contribution_recur_id = $ids['contributionRecur'];
      $contribution->receive_date = $now;
      $contribution->invoice_id =  md5( uniqid( rand( ), true ) );
      $contribution->total_amount = $input["amount"];
      $objects['contribution'] =& $contribution;
      
      require_once 'CRM/Core/Transaction.php';
      $transaction = new CRM_Core_Transaciton();
      $this->completeTransaction($input,$ids,$objects,$transaction,true);//true=recurring
      // completeTransaction handles the transaction commit
      return true;
    }
  }

  /**
   * handle the start of a future pay agreement, this is *not*
   * an actual payment, so we must remove the contribution which 
   * was created for it by CiviCRM prior to user being redirected 
   * to theWorldPay servers
   */
  function handleFuturepayAgreement(&$input, &$ids, &$objects) {
    $recur = $objects['contributionRecur'];
    $contribution =& $objects['contribution'];


    // remove the contribution which was added since
    // this is a new futurepay notification rather than
    // an actual payment
    $res=CRM_Contribute_BAO_Contribution::deleteContribution($contribution->id);
    if (!$res) {
      error_log(__FILE__.":".__FUNCTION__." : Problem encountered while deleting unwanted Contribution id=".$contribution->id." res=".print_r($res,true));
        return false;
    }

    $now = date( 'YmdHis' );

    if ($input["transStatus"]=="Y") {
      // futurepay agreemement made

      // update and save recur details
      $recur->create_date=$now;
      $recur->contribution_status_id=1;//pending ... no contribution yet made
      $recur->save();
    }
    else if ($input["transStatus"]=="C") {
      // futurepay agreement cancelled 
      $recur->contribution_status_id=3;//3= cancelled
      $recur->cancel_date = $now;
      $input['reasonCode']='FuturePay cancelled';
    }
    else {
      error_log(__FILE__.":".__FUNCTION__." : Unrecognized transStatus for single payment=".$input["transStatus"]);
      return false;
    }

    return true;
  }

  /**
   * handle a single one off payment
   */
  function handleSinglePayment(&$input, &$ids, &$objects) {
    $contribution =& $objects['contribution'];
    require_once 'CRM/Core/Transaction.php';
    $transaction = new CRM_Core_Transaction();
    if ($input["transStatus"]=="Y") {
      $this->completeTransaction($input,$ids,$objects,$transaction,false);// false= not recurring
      $output = '<div id="logo"><h1>Thank you</h1><p>Your payment was successful.</p><p>Please <a href="' . $ids["thankyoupage"] . '">click here to return to our web site</a></p>';
      echo CRM_Utils_System::theme( 'page', $output, true, false );
    }
    else if ($input["transStatus"]=="C") {
      $this->cancelled($objects,$transaction);
      $output = '<h1>Transaction Cancelled</h1><p>Your payment was cancelled or rejected.</p><p>Please contact us by phone or email regarding your payment.</p><p><a href="' . $ids["cancelpage"] . '">Click here to return to our web site</a></p>';
      echo CRM_Utils_System::theme( 'page', $output, true, false );
    }
    else {
      CRM_Core_Error::debug_log_message("Unrecognized transStatus for single payment=".$input["transStatus"]);
      error_log(__FILE__.":".__FUNCTION__." : Unrecognized transStatus for single payment=".$input["transStatus"]);
      $output = '<h1>Error</h1><p>There was an error while processing your payment.</p><p>Please contact us by phone or email regarding your payment.</p><p><a href="' . $ids["cancelpage"] . '">Click here to return to our web site</a></p>';
      echo CRM_Utils_System::theme( 'page', $output, true, false );
      return false;
    }
    // completeTransaction handles the transaction commit
    return true;
  }	


  /**
   *  handle WorldPay payment response
   */
  function main( $isFuturePay ) {
    error_log(__FILE__.":".__FUNCTION__." : handling WorldPay payment response IPN");

    $objects = $ids = $input = array( );

    $component = self::retrieve('MC_module','String','POST',true);
    $input["component"] = $component;
    $contact = self::retrieve('MC_contact_id','Integer','POST',true);

    $this->getInput($input,$ids);

    if (!$isFuturePay) {
      // not a future payment notication but may be a future payment
      // agreement being made...

      // get ids
      $ids["contact"] = self::retrieve('MC_contact_id','String','POST',true);
      $ids["contribution"] = self::retrieve('MC_contribution_id','String','POST',true);
      $ids["thankyoupage"] = self::retrieve('MC_civi_thankyou_url','String','POST',true);
      $ids["cancelpage"] = self::retrieve('MC_civi_cancel_url','String','POST',true);
      if ($component=='event') {
        $ids["event"] = self::retrieve('MC_event_id','String','POST',true);
        $ids["participant"] = self::retrieve('MC_participant_id','String','POST',true);
      }
      else {
        // contribution optional id's
        $ids['membership'] = self::retrieve('MC_membership_id','String','POST',false);
        $ids['contributionRecur'] = self::retrieve( 'MC_contribution_recur_id', 'Integer', 'GET', false );
        $ids['contributionPage']  = self::retrieve( 'MC_contribution_page_id' , 'Integer', 'GET', false );
      }

      if (is_numeric($input["futurePayId"])) {
        // this is a futurepay agreement being made 
        // so store the ids and do nothing more here
        // since no money has actually been transferred yet
        // ...watch futurepay repsonse for such transfers
        self::storeFuturePayIds($component,$input["futurePayId"],$ids);
        return;
      }
    }
    else {
      if (!is_numeric($input["futurePayId"])) {
        CRM_Core_Error::debug_log_message("futurePayId is missing for recurring payment.");
        error_log(__FILE__.":".__FUNCTION__." : futurePayId is missing for recurring payment. input=".print_r($input,true));
        exit();
      }
      self::retrieveFuturePayIds($component,$input["futurePayId"],$ids);
      if (!$ids) {
        CRM_Core_Error::debug_log_message("Some sort of error occured retrieving ids for futurePayId=$futurePayId");
        error_log(__FILE__.":".__FUNCTION__." : Some sort of error occured retrieving ids for futurePayId=$futurePayId");
        exit();
      }
    }

    // validateData also loads the obects
    if (!$this->validateData($input,$ids,$objects)) {
      error_log(__FILE__.":".__LINE__.": validate data failed, input=".print_r($input,true)." ids=".print_r($ids,true));
      return false;
    }

    self::$_paymentProcessor =& $objects['paymentProcessor'];
    if ($isFuturePay) {
      // future pay payment notification
      return $this->handleFuturepayPayment($input,$ids,$objects);
    }
    else if (is_numeric($input["futurePayId"])) {
      // future pay payment initial agreement set up
      // appears like a single payment but no money changes hands
      return $this->handleFuturepayAgreement($input,$ids,$objects);
    }
    else {
      // single payment
      error_log(__FILE__.":".__FUNCTION__." : now processing single payment");
      return $this->handleSinglePayment($input,$ids,$objects);
    }
  }

  ///////////////////////

  function getInput( &$input, &$ids ) {
    if ( ! $this->getBillingID( $ids ) ) {
      return false;
    }

  // Parameters generated by puchase token:
    $input['cartId'] = self::retrieve('cartId','String','POST',true);
    $input['amount'] = self::retrieve('amount','Money','POST',true);
    $test = self::retrieve('testMode','Integer','POST',false);
    if ($test == '100') {
      $input['is_test'] = 1;
    }
    $input['currency'] = self::retrieve('currency','String','POST',true);
    // authMode = A
    $splitName = self::splitName(self::retrieve('name','String','POST',true ));

    if (isset($splitName["first_name"])) {
      $input["first_name"] = $splitName["first_name"];
    }
    if (isset($splitName["middle_name"])) {
      $input["middle_name"] = $splitName["middle_name"];
    }
    if (isset($splitName["first_name"])) {
      $input["last_name"] = $splitName["last_name"];
    }
    $billingID = $ids['billing'];
    $lookup = array( "street_address-{$billingID}" => 'address',
                     "postal_code-{$billingID}"    => 'postcode',
                     "country-{$billingID}"        => 'country',
                     "phone-{$billingID}"          => 'phone',
                     "fax-{$billingID}"            => 'fax',
                     "email-{$billingId}"          => 'email' );
    foreach ( $lookup as $name => $paypalName ) {
      $value = self::retrieve( $paypalName, 'String', 'POST', false );
      $input[$name] = $value ? $value : null;
    }

  // Payment response parameters:
    // transId and transTime not present if this is a cancelled payment
    $input['trxn_id'] = self::retrieve('transId','Integer','POST',false);
    $input['transStatus'] = self::retrieve('transStatus','String','POST',true);
    $dateMillis = self::retrieve('transTime','Integer','POST',false);
    $input["trxn_date"] =  date( 'YmdHis', $dateMillis/1000);
    $input['authAmount'] = self::retrieve('authAmount','Money','POST',false);
    $input['authCurrency'] = self::retrieve('authCurrency','String','POST',true);
    // unused: authAmountString
    $input['rawAuthMessage'] = self::retrieve('rawAuthMessage','String','POST',true);
    // unused: rawAuthCode = A:Authorised 
    // unused: callbackPW
    $input['cardType'] = self::retrieve('cardType','String','POST',true);
    $input['AVS'] = self::retrieve('AVS','String','POST',true);
    // unused: ipAddress
    // unused: charenc

  // Optional FuturePay parameters:
    $input['futurePayId'] = self::retrieve('futurePayId','Integer','POST',false);
    $input['futurePayStatusChange'] = self::retrieve('futurePayStatusChange','String','POST',false);
  }

  /**
   * store futurepay ids when a future pay agreement is first
   * made and recorded via IPN
   */
  function storeFuturePayIds($component,$futurePayId,&$ids) {
    if ($component=='event') { // sanity check
      CRM_Core_Error::debug_log_message("Error while storing ids,FuturePay should not be used for event payments.");
      error_log(__FILE__.":".__FUNCTION__." : Error while storing ids, FuturePay should not be used for event payments.");
      return false;
    }
    $setIds = array ();
    $setIds["contact_id"] = $ids["contact"];
    $setIds["contribution"] = $ids["contribution"];
    $setIds["contribution_recur_id"]=$ids["contribution_recur"];
    $setIds["contribution_page_id"]=$ids["contribution_page"];
    if (isset($ids["membership"])) {
      $setIds["membership_id"]=$ids["membership"];
    }
    
    $fieldsNames = implode(",",array_keys($setIds));
    $fieldValues = implode(",",array_values($setIds));

    $query = "INSERT INTO worldpay_futurepay_ids($fieldNames) ";
    $query.= "VALUES($fieldValues)";
    $dao = CRM_Core_DAO::executeQuery( $query, CRM_Core_DAO::$_nullArray );
    $res = ( $dao ? true : false);

    return $res;
  }

  /**
   * retrieve futurepay ids when a futurepay payment notification
   * is retrieved via IPN
   */
  function retrieveFuturePayIds($component,$futurePayId,&$ids) {
    if ($component=='event') { // sanity check
      CRM_Core_Error::debug_log_message("Error while retrieving ids,FuturePay should not be used for event payments.");
      error_log(__FILE__.":".__FUNCTION__." : Error while retrieving ids, FuturePay should not be used for event payments.");
      return false;
    }
    $query = "SELECT * FROM worldpay_futurepay_ids WHERE futurepay_id=$futurePayId";
    $dao = CRM_Core_DAO::executeQuery( $query, CRM_Core_DAO::$_nullArray );
    if (!$dao->fetch()) {
      error_log(__FILE__.":".__FUNCTION__." : failed to retrieve futurepay details : sql=$query");
      return false;
    }
    $ids["contact"]=$dao->contact_id;
    $ids["contribution"]=$dao->contribution_id;
    $ids["contribution_recur"]=$dao->contribution_recur_id;
    $ids["contribution_page"]=$dao->contribution_page_id;
    if (is_numeric($dao->membership_id)) {
      $ids["membership"]=$dao->membership_id;
    }
    return true;
  }

  /**
   * splits the name since worldpay names are single field but
   * civicrm names have firstname middlename lastname
   */
  static function splitName($fullName) {
    $name = array();
    $splitstr = explode(" ",$fullName);
    if (sizeof($splitstr)==1) {
      $name["last_name"] = $fullName;
    }
    if (sizeof($splitstr)==2) {
      $name["last_name"] = $fullName[1];
      $name["first_name"] = $fullName[0];
    }
    if (sizeof($splitstr)==3) {
      $name["last_name"] = $fullName[2];
      $name["middle_name"] = $fullName[1];
      $name["first_name"] = $fullName[0];
    }
    else {
      $startLast = strlen($fullName[0]) + 1 + strlen($fullName[1]) + 1;
      $name["last_name"] = substr($fullName,$startLast);
      $name["middle_name"] = $fullName[1];
      $name["first_name"] = $fullName[0];
    }
    return $name;
  }

}


?>