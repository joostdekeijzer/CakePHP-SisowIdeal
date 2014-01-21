<?php
/**
 * CakePHP-SisowIdeal Sisow Component
 *
 * Copyright 2013, Stefan van Gastel
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2013, Stefan van Gastel
 * @link          http://stefanvangastel.nl
 * @since         CakePHP-SisowIdeal 1.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SisowComponent extends Component {
	
	protected static $issuers;
	protected static $lastcheck;

	private $response;

	// Merchant data
	public $merchantId;
	public $merchantKey;

	public $testMode = false;

	// Transaction data
	public $payment;		// empty=iDEAL; creditcard=CreditCard; sofort=DIRECTebanking; mistercash=MisterCash; ...
	public $issuerId;		// mandatory; sisow bank code
	public $purchaseId;		// mandatory; max 16 alphanumeric
	public $entranceCode;	// max 40 strict alphanumeric (letters and numbers only)
	public $description;	// mandatory; max 32 alphanumeric
	public $amount;			// mandatory; min 0.45
	public $notifyUrl;
	public $returnUrl;		// mandatory
	public $cancelUrl;
	public $callbackUrl;

	// Status data
	public $status;
	public $timeStamp;
	public $consumerAccount;
	public $consumerName;
	public $consumerCity;
	
	// Invoice data
	public $invoiceNo;
	public $documentId;

	// Result/check data
	public $trxId;
	public $issuerUrl;

	// Error data
	public $errorCode;
	public $errorMessage;

	// Status
	const statusSuccess = "Success";
	const statusCancelled = "Cancelled";
	const statusExpired = "Expired";
	const statusFailure = "Failure";
	const statusOpen = "Open";
	const statusReservation = "Reservation";
	const statusPending = "Pending";

	
	public function __construct() {
		
		//Load the ini file
		$inifile = APP.'Config'.DS.'sisow.ini';

		//Check file existance
		if ( ! file_exists($inifile) ){
			$inifile .= '.php';
			if( ! file_exists($inifile) ){
				throw new InternalErrorException('sisow.ini.php config file in app/Config not found.');
			}
		}

		//Read the info
		$merchantinfo = @parse_ini_file($inifile);
		
		//Check for data
		if( empty($merchantinfo['merchantId']) OR empty($merchantinfo['merchantKey']) ){
			throw new InternalErrorException('sisow.ini.php config file not containing values for merchantId or merchantKey. (Case sensitive!).');
		}

		$this->merchantId  = $merchantinfo['merchantId'];
		$this->merchantKey = $merchantinfo['merchantKey'];

		if( isset($merchantinfo['testMode']) ) {
			$this->testMode = (bool) $merchantinfo['testMode'];
		}
	}

	private function error() {
		$this->errorCode = $this->parse("errorcode");
		$this->errorMessage = urldecode($this->parse("errormessage"));
	}

	private function parse($search, $xml = false) {
		if ($xml === false) {
			$xml = $this->response;
		}
		if (($start = strpos($xml, "<" . $search . ">")) === false) {
			return false;
		}
		$start += strlen($search) + 2;
		if (($end = strpos($xml, "</" . $search . ">", $start)) === false) {
			return false;
		}
		return substr($xml, $start, $end - $start);
	}

	private function send($method, array $keyvalue = NULL, $return = 1) {
		$url = "https://www.sisow.nl/Sisow/iDeal/RestHandler.ashx/" . $method;
		$options = array(
			CURLOPT_POST => 1,
			CURLOPT_HEADER => 0,
			CURLOPT_URL => $url,
			CURLOPT_FRESH_CONNECT => 1,
			CURLOPT_RETURNTRANSFER => $return,
			CURLOPT_FORBID_REUSE => 1,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_POSTFIELDS => $keyvalue == NULL ? "" : http_build_query($keyvalue, '', '&')
		);
		$ch = curl_init();
		curl_setopt_array($ch, $options);
		$this->response = curl_exec($ch);

		if (!$this->response) {
			$this->errorMessage = curl_error($ch);
		}
		curl_close($ch); 
		if (!$this->response) {
			return false;
		}
		return true;
	}

	private function getDirectory() {
		$diff = 24 * 60 *60;
		if (self::$lastcheck)
			$diff = time() - self::$lastcheck;
		if ($diff < 24 *60 *60)
			return 0;
		if (!$this->send("DirectoryRequest"))
			return -1;
		$search = $this->parse("directory");
		if (!$search) {
			$this->error();
			return -2;
		}
		self::$issuers = array();
		$iss = explode("<issuer>", str_replace("</issuer>", "", $search));
		foreach ($iss as $k => $v) {
			$issuerid = $this->parse("issuerid", $v);
			$issuername = $this->parse("issuername", $v);
			if ($issuerid && $issuername) {
				self::$issuers[$issuerid] = $issuername;
			}
		}
		self::$lastcheck = time();
		return 0;
	}

	// DirectoryRequest
	public function DirectoryRequest(&$output, $select = false, $test = false) {
		if ($test === true) {
			// kan ook via de gateway aangevraagd worden, maar is altijd hetzelfde
			if ($select === true) {
				$output = "<select id=\"sisowbank\" name=\"issuerid\">";
				$output .= "<option value=\"99\">Sisow Bank (test)</option>";
				$output .= "</select>";
			}
			else {
				$output = array("99" => "Sisow Bank (test)");
			}
			return 0;
		}
		$output = false;
		$ex = $this->getDirectory();
		if ($ex < 0) {
			return $ex;
		}
		if ($select === true) {
			$output = "<select id=\"sisowbank\" name=\"issuerid\">";
		}
		else {
			$output = array();
		}
		foreach (self::$issuers as $k => $v) {
			if ($select === true) {
				$output .= "<option value=\"" . $k . "\">" . $v . "</option>";
			}
			else {
				$output[$k] = $v;
			}
		}
		if ($select === true) {
			$output .= "</select>";
		}
		return 0;
	}

	// TransactionRequest
	public function TransactionRequest($keyvalue = NULL) {
		$this->trxId = $this->issuerUrl = "";
		if (!$this->merchantId) {
			$this->errorMessage = 'No Merchant ID';
			return -1;
		}
		if (!$this->merchantKey) {
			$this->errorMessage = 'No Merchant Key';
			return -2;
		}
		if (!$this->purchaseId) {
			$this->errorMessage = 'No purchase ID';
			return -3;
		}
		if ($this->amount < 0.45) {
			$this->errorMessage = 'Amount < 0.45';
			return -4;
		}
		if (!$this->description) {
			$this->errorMessage = 'No description';
			return -5;
		}
		if (!$this->returnUrl) {
			$this->errorMessage = 'No return URL';
			return -6;
		}
		if (!$this->issuerId && !$this->payment) {
			$this->errorMessage = 'No issuer ID or no payment method';
			return -7;
		}
		if (!$this->entranceCode)
			$this->entranceCode = $this->purchaseId;
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["payment"] = $this->payment;
		$pars["issuerid"] = $this->issuerId;
		$pars["purchaseid"] = $this->purchaseId;
		$pars["amount"] = round($this->amount * 100);
		$pars["description"] = $this->description;
		$pars["entrancecode"] = $this->entranceCode;
		$pars["returnurl"] = $this->returnUrl;
		$pars["cancelurl"] = $this->cancelUrl;
		$pars["callbackurl"] = $this->callbackUrl;
		$pars["notifyurl"] = $this->notifyUrl;
		$pars["sha1"] = sha1($this->purchaseId . $this->entranceCode . round($this->amount * 100) . $this->merchantId . $this->merchantKey);
		if ($keyvalue) {
			foreach ($keyvalue as $k => $v) {
				if ( !in_array( $k, array('sha1', 'purchaseid', 'entrancecode', 'amount', 'merchantid') ) ) {
					$pars[$k] = $v;
				}
			}
		}
		if (!$this->send("TransactionRequest", $pars)) {
			if (!$this->errorMessage) {
				$this->errorMessage = "No transaction";
			}
			return -8;
		}
		$this->trxId = $this->parse("trxid");
		$this->issuerUrl = urldecode($this->parse("issuerurl"));
		$this->documentId = $this->parse("documentid");
		if (!$this->issuerUrl) {
			$this->error();
			return -9;
		}
		return 0;
	}

	// StatusRequest
	public function StatusRequest($trxid = false) {
		if ($trxid === false)
			$trxid = $this->trxId;
		if (!$this->merchantId)
			return -1;
		if (!$this->merchantKey)
			return -2;
		if (!$trxid)
			return -3;
		$this->trxId = $trxid;
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["trxid"] = $this->trxId;
		$pars["sha1"] = sha1($this->trxId . $this->merchantId . $this->merchantKey);
		if (!$this->send("StatusRequest", $pars))
			return -4;
		$this->status = $this->parse("status");
		if (!$this->status) {
			$this->error();
			return -5;
		}
		$this->timeStamp = $this->parse("timestamp");
		$this->amount = $this->parse("amount") / 100.0;
		$this->consumerAccount = $this->parse("consumeraccount");
		$this->consumerName = $this->parse("consumername");
		$this->consumerCity = $this->parse("consumercity");
		$this->purchaseId = $this->parse("purchaseid");
		$this->description = $this->parse("description");
		$this->entranceCode = $this->parse("entrancecode");
		return 0;
	}

	// FetchMonthlyRequest
	public function FetchMonthlyRequest($amt = false) {
		if (!$amt) $amt = round($this->amount * 100);
		else $amt = round($amt * 100);
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["amount"] = $amt;
		$pars["sha1"] = sha1($amt . $this->merchantId . $this->merchantKey);
		if (!$this->send("FetchMonthlyRequest", $pars))
			return -1;
		$this->monthly = $this->parse("monthly");
		$this->pclass = $this->parse("pclass");
		$this->intrestRate = $this->parse("intrestRate");
		$this->invoiceFee = $this->parse("invoiceFee");
		$this->months = $this->parse("months");
		$this->startFee = $this->parse("startFee");
		return $this->monthly;
	}

	// RefundRequest
	public function RefundRequest($trxid) {
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["trxid"] = $trxid;
		$pars["sha1"] = sha1($trxid . $this->merchantId . $this->merchantKey);
		if (!$this->send("RefundRequest", $pars))
			return -1;
		$id = $this->parse("id");
		if (!$id) {
			$this->error();
			return -2;
		}
		return $id;
	}

	// InvoiceRequest
	public function InvoiceRequest($trxid, $keyvalue = NULL) {
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["trxid"] = $trxid;
		$pars["sha1"] = sha1($trxid . $this->merchantId . $this->merchantKey);
		if ($keyvalue) {
			foreach ($keyvalue as $k => $v) {
				$pars[$k] = $v;
			}
		}
		if (!$this->send("InvoiceRequest", $pars))
			return -1;
		$this->invoiceNo = $this->parse("invoiceno");
		if (!$this->invoiceNo) {
			$this->error();
			return -2;
		}
		$this->documentId = $this->parse("documentid");
		return 0;
	}

	// CreditInvoiceRequest
	public function CreditInvoiceRequest($trxid) {
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["trxid"] = $trxid;
		$pars["sha1"] = sha1($trxid . $this->merchantId . $this->merchantKey);
		if (!$this->send("CreditInvoiceRequest", $pars))
			return -1;
		$this->invoiceNo = $this->parse("invoiceno");
		if (!$this->invoiceNo) {
			$this->error();
			return -2;
		}
		$this->documentId = $this->parse("documentid");
		return 0;
	}

	// CancelReservationRequest
	public function CancelReservationRequest($trxid) {
		$pars = array();
		$pars["merchantid"] = $this->merchantId;
		$pars["trxid"] = $trxid;
		$pars["sha1"] = sha1($trxid . $this->merchantId . $this->merchantKey);
		if (!$this->send("CancelReservationRequest", $pars))
			return -1;
		return 0;
	}

	public function validateSha1( $shaToTest = '', $trxId = '', $entranceCode ='' , $status = '' ) {
		return ( sha1( $trxId . $entranceCode . $status . $this->merchantId . $this->merchantKey ) == $shaToTest );
	}

	public function GetLink($msg = 'hier', $method = '') {
		if (!$method) {
			if (!$msg)
				$link = 'https://www.sisow.nl/Sisow/Opdrachtgever/download.aspx?merchantid=' .
					$this->merchantId . '&doc=' . $this->documentId . '&sha1=' .
					sha1($this->documentId . $this->merchantId . $this->merchantKey);
			else
				$link = '<a href="https://www.sisow.nl/Sisow/Opdrachtgever/download.aspx?merchantid=' .
					$this->merchantId . '&doc=' . $this->documentId . '&sha1=' .
					sha1($this->documentId . $this->merchantId . $this->merchantKey) . '">' . $msg . '</a>';
			return $link;
		}
		if ($method == 'make') {
		}
	}

	public function logSisow($error, $order_id = 0, $dir = '', $act = 'TransactionRequest') {
		$filename = ($dir != '') ? $dir . '/log_sisow.log' : 'log_sisow.log';
		$order_id = (($order_id == 0 || $order_id == '') && $this->purchaseId != '') ? $this->purchaseId : $order_id;

		$f = fopen($filename, 'a+');
		fwrite($f, "\n" . "|***" . date("d-m-Y H:i:s") . "*******" . "\n");
		fwrite($f, "| - " . $act . " - " . "\n");
		fwrite($f, "| Order: " . $order_id . "\n");
		fwrite($f, "| Error: " . $error . "\n");
		fwrite($f, "----------------------------" . "\n");
		fclose($f);
	}
}
