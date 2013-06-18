<?php

class com_webaccessglobal_ogone extends CRM_Core_Payment {

  CONST CHARSET = 'UFT-8';

  static protected $_mode = null;
  static protected $_params = array();

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {

    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Ogone');
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "PSPID" is not set in the Administer CiviCRM Payment Processor.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return null;
    }
  }

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Submit an Automated Recurring Billing subscription
   *
   * @param  array $params assoc array of input parameters for this transaction
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doTransferCheckout(&$params, $component) {
    CRM_Core_Error::debug_var('$params' , $params);

    $config = CRM_Core_Config::singleton();
    $is_test = 0;

    if ($this->_paymentForm->_mode == 'test') {
      $is_test = 1;
    }


    if ($component != 'contribute' && $component != 'event') {
      CRM_Core_Error::fatal(ts('Component is invalid'));
    }

    //retrive contact
    $addParams = array('contact_id' => $params['contactID']);
    CRM_Contact_BAO_Contact::retrieve($addParams, $defaults);

    $ogoneParams = array(
      'PSPID' => $this->_paymentProcessor['user_name'],
      'orderID' => $params['invoiceID'],
      'amount' => $params['amount'] * 100,
      'currency' => $params['currencyID'],
      'language' => 'en_US',
      'CN' =>  $defaults['display_name'],
    );


    $addressParams = array();
    $phoneParams = array();
    if (!empty($defaults['address'])) {
      foreach ($defaults['address'] as $key => $value) {
        $addressParams[$value['location_type_id']] = $value;
      }
      if (!empty($addressParams[5]['state_province_id']) && !empty($addressParams[5]['country_id']) && (!empty($addressParams[5]['supplemental_address_1']) || !empty($addressParams[5]['street_address']))) {
        $params['street_address'] = $addressParams[5]['street_address'];
        $params['city'] = $addressParams[5]['city'];
        $params['state_province'] = $addressParams[5]['state_province_id'];
        $params['postal_code'] = $addressParams[5]['postal_code'];
      }
      else {
        foreach ($addressParams as $key => $value) {
          if (!empty($value['state_province_id']) && !empty($value['country_id']) && (!empty($value['supplemental_address_1']) || !empty($value['street_address']))) {
            $params['street_address'] = $value['street_address'];
            $params['city'] = $value['city'];
            $params['state_province'] = $value['state_province_id'];
            $params['postal_code'] = $value['postal_code'];
            break;
          }
        }
      }
    }

    $otherVars = array(
      'street_address' => 'owneraddress',
      'city' => 'ownercty',
      'state_province' => 'ownertown',
      'postal_code' => 'ownerZIP',
    );

    foreach (array_keys($params) as $p) {
      // get the base name without the location type suffixed to it
      $parts = explode('-', $p);
      $name = count($parts) > 1 ? $parts[0] : $p;
      if (isset($otherVars[$name])) {
        $value = $params[$p];
        if ($value) {
          if ($name == 'state_province') {
            $stateName = CRM_Core_PseudoConstant::stateProvinceAbbreviation($value);
            $value = $stateName;
          }
          if ($name == 'country') {
            $countryName = CRM_Core_PseudoConstant::country($value);
            $value = $countryName;
          }
          // ensure value is not an array
          // CRM-4174
          if (!is_array($value)) {
            $ogoneParams[$otherVars[$name]] = $value;
          }
        }
      }
    }

    //set IPN URL
    $ogoneParams['accepturl'] =
        $ogoneParams['cancelurl'] =
        $ogoneParams['declineurl'] =
        $ogoneParams['exceptionurl'] =
        CRM_Utils_System::url('civicrm/payment/ipn', "processor_name=Ogone", true, null, false);

    if (array_key_exists('email-5', $params) || array_key_exists('email-Primary', $params))
      $ogoneParams['EMAIL'] = array_key_exists('email-5', $params) ? $params['email-5'] : $params['email-Primary'];

    $ogoneParamplus = 'cntId:' . $params['contributionID'] . '-cId:' . $params['contactID'] . '-module:' . $component;

    if ($component == 'event') {
      $ogoneParamplus .= '-eId:' . $params['eventID'];
      $ogoneParamplus .= '-pId:' . $params['participantID'];
    }
    else {
      $membershipID = CRM_Utils_Array::value('membershipID', $params);
      if ($membershipID) {
        $ogoneParamplus .= '-mId:' . $membershipID;
      }
      $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
      if ($relatedContactID) {
        $ogoneParamplus .= '-rCid:' . $relatedContactID;

        $onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params);
        if ($onBehalfDupeAlert) {
          $ogoneParamplus .= '-oda:' . $onBehalfDupeAlert;
        }
      }
    }
    $ogoneParamplus .= '-test:' . $is_test . '-qfkey:' . $params['qfKey'];

    $ogoneParams['COMPLUS'] = $ogoneParamplus;

    //Convert SHA-IN
    $ogoneParams['SHASign'] = $this->_sha1($ogoneParams);

    // Build our query string;
    $queryString = '';

    foreach ($ogoneParams as $name => $value) {
      $queryString .= $name . '=' . $value . '&';
    }
    // Remove extra &
    $queryString = rtrim($queryString, '&');
    
    CRM_Utils_System::redirect($this->_paymentProcessor['url_site'] . '?' . $queryString);
  }

  /**
   * Get the value of a field if set
   *
   * @param string $field the field
   * @return mixed value of the field, or empty string if the field is
   * not set
   */
  function _getParam($field) {
    return CRM_Utils_Array::value($field, $this->_params, '');
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
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null) {
      self::$_singleton[$processorName] = new com_webaccessglobal_ogone($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  function &error($errorCode = null, $errorMessage = null) {
    $e = & CRM_Core_Error::singleton();
    if ($errorCode) {
      $e->push($errorCode, 0, null, $errorMessage);
    }
    else {
      $e->push(9001, 0, null, 'Unknowns System Error.');
    }
    return $e;
  }

  /**
   * Set a field to the specified value.  Value must be a scalar (int,
   * float, string, or boolean)
   *
   * @param string $field
   * @param mixed $value
   * @return bool false if value is not a scalar, true if successful
   */
  function _setParam($field, $value) {
    if (!is_scalar($value)) {
      return false;
    }
    else {
      $this->_params[$field] = $value;
    }
  }

  /**
   * Conversion in sha1
   */
  function _sha1($params) {
    $sign = $this->_paymentProcessor['password'];

    $params = array_change_key_case($params, CASE_UPPER);
    ksort($params);
    $string = '';
    foreach ($params as $key => $value) {
      $string .= $key . "=" . $value . $sign;
    }

    return strtoupper(sha1($string));
  }

  /**
   * Handle return response from payment processor
   */
  function handlePaymentNotification() {
    require_once 'ogoneipn.php';
    $ogoneIPN = new com_webaccessglobal_ogoneIPN($this->_mode, $this->_paymentProcessor);
    $ogoneIPN->main($_GET);
  }

}
