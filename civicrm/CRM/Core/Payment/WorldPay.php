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

require_once 'CRM/Core/Payment.php';

class CRM_Core_Payment_WorldPay extends CRM_Core_Payment {
    const
        CHARSET = 'iso-8859-1';

    static protected $_mode = null;

    static protected $_params = array();
    
    static private $_singleton = null;

    /**
     * Constructor
     *
     * @param array $paymentProcessor array containing payment processor values
     * @param string $mode the mode of operation: live or test
     *
     * @return void
     */
    function __construct( $mode, &$paymentProcessor ) {
        error_log(__FILE__.":".__FUNCTION__.": mode = $mode : paymentProcessor = ".print_r($paymentProcessor,true));
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
    }

   /** 
     * singleton function used to manage this object 
     * 
     * @param string $mode the mode of operation: live or test
     *
     * @return object 
     * @static 
     * 
     */ 
    static function &singleton( $mode, &$paymentProcessor ) {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null ) {
            self::$_singleton[$processorName] = new CRM_Core_Payment_WorldPay( $mode, $paymentProcessor );
        }
        return self::$_singleton[$processorName];
    }

    /**
     * not used for payment type = 4 (but required)
     */
    function doDirectPayment( &$params ) {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }

    /**
     * get fields for constructing a WorldPay Payment Token
     *
     * assumes currency code is the same:
     *   WorldPay uses ISO 4217 (from http://www.id3.org/iso4217.html)
     *   CiviCRM uses ???
     */
    function getWorldPayFields(&$params,$testing=true) {
      $fields = array();
      if ($testing) {
        $fields["testMode"]=100;
      }

      $fields["instId"] = $this->_paymentProcessor["user_name"];
      $fields["accId1"] = $this->_paymentProcessor["signature"];
      $fields["authMode"] = "A";

      $fields["currency"] = $params["currencyID"];// may need translation
      $fields["hideCurrency"] = 'false';
      $fields["cartId"] = $params["invoiceID"];// correct?
      $fields["desc"] = $params["description"];
      $name = $this->_getName($params);
      $fields["email"] = $params["email"];
      if (!isset($fields["email"]) && isset($params["email-5"])) {
        $fields["email"]=$params["email-5"];
      }
      
      if ( $name===false || 
        // always going to be here with billing_id=4
        //
           empty($params["street_address-1"]) ||
           empty($params["country-1"]) ||
           empty($params["postal_code-1"]) ) {

        // insufficient details available let them fill it in themselve
        // on the WorldPay pages
        $fields["fixContact"] = 'N';// prob default behaviour anyway
        $fields["hideContact"] = 'N';
      }
      else {
        // never going to get here with billing_id=4
        //
        $fields["address"] = $params["street_address-1"];
        if (!empty($params["supplemental_address_1-1"])) {
          $fields["address"] .= "\n" . $params["supplemental_address_1-1"];
        }
        if (!empty($params["supplemental_address_2-1"])) {
          $fields["address"] .= "\n" . $params["supplemental_address_2-1"];
        }
        if (!empty($params["city-1"])) {
          $fields["address"].= "\n".$params["city-1"];
        }
        $fields["postcode"] = $params["postal_code-1"];
        $countryIsoCodes = CRM_Core_PseudoConstant::countryIsoCode( );
        $fields["country"] = $countryIsoCodes[$params["country-1"]];
        //$fields["tel"]= '';  // could collect phone num...
        if (!empty($params["email-5"])) { // dubious...
          $fields["email"]= $params["email-5"];
        }
        $fields["fixContact"] = 'N';
        $fields["hideContact"] = 'N';
      }

      if ($params["is_recur"]==1) {
        // repeat payment for WorldPay:
        //
        $repeatUnit = $this->getWorldPayUnitCode($params["frequency_unit"]);
        if ($repeatUnit===false) {
          CRM_Core_Error::fatal( ts('Error with WorldPay payment processor for repeat payment : bad frequency-unit from params='.print_r($params,true))); 
        }
        $noPayments = $params["installments"];
        if ($noPayments<1) {
          CRM_Core_Error::fatal( ts('Error with WorldPay payment processor for repeat payment : bad installments count from params='.print_r($params,true)));
        }
        $tomorrow = mktime(0, 0, 0, date("m"), date("d")+1, date("y"));

        // future pay types are:
        // regular - regular payments
        // limited - limited but indeterminate payments
        $fields["futurePayType"]='regular';// regular pay type

        // repeat options are:
        // 0 - fixed amount, can't adjust after start
        // 1 - can adjust payment after start
        // 2 - use Merchant Administration Interface        
        $fields["option"]='0';// fixed amount, can't adjust
        
        // unused fields:
        //$fields["startDelayMult"]='0';// no start delay  
        //$fields["startDelayUnit"]='0';// no start delay 
        //$$fields["initialAmount"]='0';// just use normal_amount

        // fixed repeat payment details
        $fields["intervalUnit"]=$repeatUnit;
        $fields["intervalMult"]=$params["frequency_interval"];
        $fields["normalAmount"]=$params["amount_other"];// same as 1st
        $fields["noOfPayments"]=$noPayments;// -1 to remove 1st ?
        $fields["amount"]=$params["amount_other"];//
        $fields["startDate"]=date("Y-m-d",$tomorrow);
      }
      else {
        // single one off payment
        $fields["amount"]=$params["amount"];
      }
      return $fields;
    }

    function getWorldPayUnitCode($frequencyUnit) {
      switch ($frequencyUnit) {
        case "day": return 1;
        case "week": return 2;
        case "month": return 3;
        case "year": return 4;
        default:
          error_log(__FILE__.":".__FUNCTION__." : unrecognized interval=$frequencyUnit");
          return false;
      }
    }

    /**
     * Transfers Payers browser to the WorldPay website
     */
    function doTransferCheckout( &$params, $component='contribute' ) {
      error_log(__FILE__.":".__FUNCTION__.": params = ".print_r($params,true));

      // 1. add standard WP fields
      if ( $this->_mode == 'test' ) {
        // TEST
        $postFields = $this->getWorldPayFields($params,true);
        error_log(__FILE__.":".__FUNCTION__.": fields to worldpay: " . print_r($postFields, true));
      }  
      else {
        // LIVE
        $postFields = $this->getWorldPayFields($params,false);
      }
      if ($postFields===false) {
        error_log(__FILE__.":".__FUNCTION__." : Failed to process parameters for WorldPay payment processor. params=".print_r($params,true));
        return self::error(9002,'Failed to process parameters for WorldPay payment processor. params='.print_r($params,true));
      }
 
      // 2. add custom WP fields
      // add the callback url for handling payment responses
      // note that callback for futurepay IPN is hardcoded
      // in the MAI, but single payments and futurepay setup
      // use MC_callback if it is set (otherwise they use the
      // hardcoded value)
      $config =& CRM_Core_Config::singleton( );

      // add the civicrm specific details as WorldPay custom params
      $postFields["MC_contact_id"]=$params["contactID"];
      $postFields["MC_contribution_id"]=$params["contributionID"];
      $postFields["MC_module"]=$component;
      if ( $component == 'event' ) {
        $postFields["MC_event_id"]=$params["eventID"];
        $postFields["MC_participant_id"]=$params["participantID"];
      }
      else {
        $membershipID = CRM_Utils_Array::value( 'membershipID', $params );
        if ( $membershipID ) {
          $postFields["MC_membership_id"]=$membershipID;
        }
      }

      if ($params["is_recur"]==1) {
        if (is_numeric($params["contributionRecurID"])) {
          $postFields["MC_contribution_recur_id"]=$params["contributionRecurID"];
          $postFields["MC_contribution_page_id"]=$params["contributionPageID"];
        }
        else {
        
          CRM_Core_Error::fatal( ts( 'Recurring contribution, but no database id' ) );
        }
      }

      // add the civicrm return urls as WorldPay custom params
      $url    = ( $component == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';
      $cancel = ( $component == 'event' ) ? '_qf_Register_display'   : '_qf_Main_display';
      $returnURL = CRM_Utils_System::url( $url,
                                           "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
                                           true, null, false );
      $cancelURL = CRM_Utils_System::url( $url,
                                          "$cancel=1&cancel=1&qfKey={$params['qfKey']}",
                                          true, null, false );
      $postFields["MC_civi_thankyou_url"]=$returnURL;
      $postFields["MC_civi_cancel_url"]=$cancelURL;

      // 3. build the URL for WorldPay
      $uri = '';
      foreach ( $postFields as $key => $value ) {
        if ( $value === null ) {
          error_log(__FILE__.":".__FUNCTION__." : null field value in WorldPay params for key=$key");
          continue;
        }
        $value = urlencode( $value );
/* 
 // this is used in paypal.php not sure if it's necessary
 //  but not using MC_civi_thankyou_url and MC_civi_cancel_url anyway

            if ( $key == 'MC_civi_thankyou_url' ||
                 $key == 'MC_civi_cancel_url') {
                $value = str_replace( '%2F', '/', $value );
            }
*/
        $uri .= "&{$key}={$value}";
      }

      $uri = substr( $uri, 1 );
      $url = $this->_paymentProcessor['url_site'];
      //$sub = empty( $params['is_recur'] ) ? 'xclick' : 'subscriptions';
      $worldPayURL = "{$url}?$uri";

      error_log(__FILE__.":".__FUNCTION__." : WorldPay Juior Select URL=$worldPayURL");

      CRM_Utils_System::redirect( $worldPayURL );
    }

  
    /**
     * This function checks to see if we have the right config values 
     *
     * @return string the error message if any
     * @public
     */
    function checkConfig( ) {
        $error = array();
        if (empty($this->_paymentProcessor['user_name'])) {
          $error[] = ts('Inst_id is not set for this payment processor');
        }
        //todo: - any other config values that need checking

        if (!empty($error)) {
          return implode('<p>',$error);
        }
        else {
          return null;
        }
    }

    /**
     * Handle an error
     */
    function &error ( $errorCode = null, $errorMessage = null ) {
        $e =& CRM_Core_Error::singleton( );
        if ( $errorCode ) {
            $e->push( $errorCode, 0, null, $errorMessage );
        }
        else {
            $e->push( 9001, 0, null, 'Unknown System Error.' );
        }
        return $e;
    }

    /**
     * Checks to see if invoice_id already exists in db
     */
    function _checkDupe( $invoiceId ) {
        require_once 'CRM/Contribute/DAO/Contribution.php';
        $contribution =& new CRM_Contribute_DAO_Contribution( );
        $contribution->invoice_id = $invoiceId;
        return $contribution->find( );
    }

    /**
     * build the billing name
     *
     * @param array $params parameters to check for billing name fields
     *
     * @return string name or false
     */
    function _getName(&$params) {
      $name = array();
      if (!empty($params["first_name"])) {
        $name[]= $params["first_name"];
      }
      if (!empty($params["middle_name"])) {
        $name[]= $params["middle_name"];
      }
      if (!empty($params["last_name"])) {
        $name[]= $params["last_name"];
      }
      if (sizeof($name)==0) {
        return false;
      }
      else {
        return implode(" ",$name);
      }
    }
}         

?>