<?php

class com_webaccessglobal_ogoneIPN extends CRM_Core_Payment_BaseIPN {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  protected static $_mode = null;

  static function retrieve($name, $type, $object, $abort = true) {
    $value = CRM_Utils_Array::value($name, $object);
    if ($abort && $value === null) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name");
      echo "Failure: Missing Parameter - " . $name . "<p>";
      exit();
    }

    if ($value) {
      if (!CRM_Utils_Type::validate($value, $type)) {
        CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
        echo "Failure: Invalid Parameter<p>";
        exit();
      }
    }

    return $value;
  }

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    parent::__construct();

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
   */
  static function &singleton($mode, $component, &$paymentProcessor) {
    if (self::$_singleton === null) {
      self::$_singleton = new ogoneipn($mode, $paymentProcessor);
    }
    return self::$_singleton;
  }

  /**
   * This method is handles the response that will be invoked by the
   * notification or request sent by the payment processor.
   * hex string from paymentexpress is passed to this function as hex string.
   */
  function main($ogoneData) {
    CRM_Core_Error::debug_var('$ogoneData' , $ogoneData);
    $config = CRM_Core_Config::singleton();
    $objects = $ids = $input = array();

    $miscData = self::_processMisc($ogoneData['COMPLUS']);


    // get the contribution and contact ids from the GET params
    $ids['contact'] = $miscData['cId'];
    $ids['contribution'] = $miscData['cntId'];

    $input['status'] = $ogoneData['STATUS'];
    $input['invoice'] = $ogoneData['orderID'];
    $input['amount'] = $ogoneData['amount'];
    $input['trxn_id'] = $ogoneData['PAYID'];
    $input['acceptanceCode'] = $ogoneData['ACCEPTANCE'];
    $input['errorCode'] = $ogoneData['NCERROR'];
    $input['SHASIGN'] = $ogoneData['SHASIGN'];
    $input['is_test'] = $miscData['test'];
    $input['component'] = $miscData['module'];

    if ($input['component'] == 'event') {
      $ids['event'] = $miscData['eId'];
      $ids['participant'] = $miscData['pId'];
    }
    else {
      // get the optional ids
      $ids['membership'] = $miscData['mId'];
      $ids['contributionPage'] = $miscData['cntPId'];
      $ids['relatedContact'] = $miscData['rCid'];
    }

    if (!$this->validateData($input, $ids, $objects)) {
      return false;
    }

    list ($mode, $duplicateTransaction) = self::getContext($ids);
    $mode = $mode ? 'test' : 'live';


    /**
     * Fix me as per civicrm versions
     * In below 4.2 version 'CRM_Core_BAO_PaymentProcessor'
     * */
    $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($this->_paymentProcessor['id'], $mode);


    //unset unwanted array elements for compare sha1 key
    unset($ogoneData['SHASIGN']);
    unset($ogoneData['q']);
    unset($ogoneData['processor_name']);

    $validateSha1Key = $this->_sha1($ogoneData, $paymentProcessor['signature']);
    if (strcmp($input['SHASIGN'], $validateSha1Key)) {
      CRM_Core_Error::debug_log_message("Failure: SHA1-OUT signature does not match with Request parameters");
      exit();
    }

    $this->newOrderNotify($input, $ids, $objects, false, false);

    /**
     * Redirect to the correct url.
     * status = 0 invalid
     * status = 1 cancelled
     * status = 2 declined
     */
    if ($ogoneData['STATUS'] == '2' || $ogoneData['STATUS'] == '1' || $ogoneData['STATUS'] == '0') {

      if ($input['component']  == "event") {
        $finalURL = CRM_Utils_System::url('civicrm/event/confirm', "reset=1&cc=fail&participantId={$ids['participant']}", false, null, false);
      }
      elseif ($input['component'] == "contribute") {
        $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_Main_display=1&cancel=1&qfKey={$miscData['qfkey']}", false, null, false);
      }
    }
    else {
      if ($input['component'] == "event") {
        $finalURL = CRM_Utils_System::url('civicrm/event/register', "_qf_ThankYou_display=1&qfKey={$miscData['qfkey']}", false, null, false);
      }
      elseif ($input['component'] == "contribute") {
        $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_ThankYou_display=1&qfKey={$miscData['qfkey']}", false, null, false);
      }
    }

    CRM_Utils_System::redirect($finalURL);
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
   * The function gets called when a new order takes place.
   *
   * @param array  $privateData  contains the CiviCRM related data
   * @param string $component    the CiviCRM component
   * @param array  $borikaData contains the Merchant related data
   *
   * @return void
   *
   */
  function newOrderNotify(&$input, &$ids, &$objects, $recur = false, $first = false) {
    CRM_Core_Error::debug_var('$input' , $input);

    $contribution = & $objects['contribution'];

    if (strtoupper($contribution->invoice_id) != strtoupper($input['invoice'])) {
      CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
      echo "Failure: Invoice values dont match between database and IPN request<p>";
      return false;
    }
    if ($contribution->total_amount != $input['amount']) {
      CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
      echo "Failure: Amount values dont match between database and IPN request<p>";
      return false;
    }

    $transaction = new CRM_Core_Transaction( );

    $participant = & $objects['participant'];
    $membership = & $objects['membership'];

    $status = $input['status'];

    if ($status == 0 || $status == 2 || $status == 8) {
      return $this->failed($objects, $transaction);
    }
    else if ($status == 1) {
      return $this->cancelled($objects, $transaction);
    }
    else if ($status == 41 || $status == 51 || $status == 91 ) {
      return $this->pending($objects, $transaction);
    }
    else if ($status != 9 && $status != 5 && $status != 4) {
      return $this->unhandled($objects, $transaction);
    }

    // check if contribution is already completed, if so we ignore this ipn
    if ($contribution->contribution_status_id == 1) {
      $transaction->commit();
      CRM_Core_Error::debug_log_message("returning since contribution has already been handled");
      echo "Success: Contribution has already been handled<p>";
      return true;
    }
    else {

      if (CRM_Utils_Array::value('event', $ids)) {
        $contribution->trxn_id = $ids['event'] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['participant'];
      }
      elseif (CRM_Utils_Array::value('membership', $ids)) {
        $contribution->trxn_id = $ids['membership'][0] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['related_contact'] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['onbehalf_dupe_alert'];
      }
    }
    $this->completeTransaction($input, $ids, $objects, $transaction);
    return true;
  }

  /**
   * The function returns the component(Event/Contribute..)and whether it is Test or not
   *
   * @param array   $privateData    contains the name-value pairs of transaction related data
   *
   * @return array context of this call (test, component, payment processor id)
   * @static
   */
  static function getContext($privateData) {

    $isTest = null;

    $contributionID = $privateData['contribution'];
    $contribution = & new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionID;

    if (!$contribution->find(true)) {
      CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
      echo "Failure: Could not find contribution record for $contributionID<p>";
      exit();
    }
    if (stristr($contribution->source, 'Online Contribution')) {
      $component = 'contribute';
    }
    elseif (stristr($contribution->source, 'Online Event Registration')) {
      $component = 'event';
    }

    $isTest = $contribution->is_test;

    $duplicateTransaction = 0;
    if ($contribution->contribution_status_id == 1) {
      //contribution already handled. (some processors do two notifications so this could be valid)
      $duplicateTransaction = 1;
    }

    if ($component == 'contribute') {
      if (!$contribution->contribution_page_id) {
        CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
        echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
        exit();
      }
    }
    else {

      $eventID = $privateData['event'];
      if (!$eventID) {
        CRM_Core_Error::debug_log_message("Could not find event ID");
        echo "Failure: Could not find eventID<p>";
        exit();
      }

      // we are in event mode
      // make sure event exists and is valid
      //require_once 'CRM/Event/DAO/Event.php';
      $event = & new CRM_Event_DAO_Event();
      $event->id = $eventID;

      if (!$event->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find event: $eventID");
        echo "Failure: Could not find event: $eventID<p>";
        exit();
      }
    }

    return array(
      $isTest,
      $duplicateTransaction
    );
  }

  /**
   * Conversion in sha1
   */
  function _sha1($params, $sign) {
    $params = array_change_key_case($params, CASE_UPPER);
    ksort($params);
    $string = '';
    foreach ($params as $key => $value) {
      $string .= $key . "=" . $value . $sign;
    }
    return strtoupper(sha1($string));
  }

  static function _processMisc($param) {
    $misc = explode('-', $param);
    $newMisc = array();
    foreach ($misc as $key => $value) {
      $data = explode(':', $value);
      $newMisc[$data[0]] = $data[1];
    }
    return $newMisc;
  }

}

?>
