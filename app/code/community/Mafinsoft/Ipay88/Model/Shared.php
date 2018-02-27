<?php
/**
 * Mafinsoft
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@mafinsoft.com so we can send you a copy immediately.
 *
 * @category   Mafinsoft
 * @package    Mafinsoft_Ipay88
 * @copyright  Copyright (c) 2009 Mafinsoft Solutions (http://www.mafinsoft.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Mafinsoft_Ipay88_Model_Shared extends Mage_Payment_Model_Method_Abstract
{
	const XML_PATH_MERCHANTCODE	= 'ipay88/settings/ipay88_merchantcode';
	const XML_PATH_MERCHANTKEY	= 'ipay88/settings/ipay88_merchantkey';
	
	/**
	* payment id assigned by iPay88
	*
	* @var string [a-z0-9_]
	**/
	protected $_code = 'ipay88_shared';

	protected $_formBlockType = 'ipay88/form';
	protected $_infoBlockType = 'ipay88/info';

	protected $_isGateway				= true;
	protected $_canAuthorize			= false;
	protected $_canCapture				= true;
	protected $_canCapturePartial		= false;
	protected $_canRefund				= false;
	protected $_canVoid					= false;
	protected $_canUseInternal			= false;
	protected $_canUseCheckout			= true;
	protected $_canUseForMultishipping	= true;

	protected $_paymentMethod			= 'shared';
	protected $_defaultLocale			= 'en';
	protected $_supportedLocales		= array('en');

	protected $_order;

	/**
	 * Get order model
	 *
	 * @return Mage_Sales_Model_Order
	 */
	public function getOrder()
	{
		if (!$this->_order) {
			$paymentInfo = $this->getInfoInstance();
			$this->_order = Mage::getModel('sales/order')
							->loadByIncrementId($paymentInfo->getOrder()->getRealOrderId());
		}
		return $this->_order;
	}

	public function getOrderPlaceRedirectUrl()
	{
		  return Mage::getUrl('ipay88/processing/redirect');
	}

	public function cancel(Varien_Object $payment)
	{
		$payment->setStatus(self::STATUS_DECLINED)
			->setLastTransId($this->getTransactionId());

		return $this;
	}
	
	public function capture(Varien_Object $payment, $amount)
	{
		$payment->setStatus(self::STATUS_APPROVED)
			->setLastTransId($this->getTransactionId());

		return $this;
	}
	
	/**
	 * Return redirect block type
	 *
	 * @return string
	 */
	public function getRedirectBlockType()
	{
		return $this->_redirectBlockType;
	}

	/**
	 * Return payment method type string
	 *
	 * @return string
	 */
	public function getPaymentMethodType()
	{
		return $this->_paymentMethod;
	}

	/**
	 * Return url of payment method
	 *
	 * @return string
	 */
	public function getUrl()
	{
		return 'https://www.mobile88.com/ePayment/entry.asp';
	}
	
	/**
	 * Return locale
	 *
	 * @return string
	 */
	public function getLocale()
	{
		
		$locale = explode('_', Mage::app()->getLocale()->getLocaleCode());
		if (is_array($locale) && !empty($locale) && in_array($locale[0], $this->_supportedLocales))
			return $locale[0];
		else
			return $this->getDefaultLocale();
	}

	/**
	 * prepare params array to send it to iPay88 gateway page via POST method
	 * 
	 * @return array
	 */
	public function getFormFields()
	{
		$order_id	= $this->getOrder()->getRealOrderId();
		$billing	= $this->getOrder()->getBillingAddress();
		//$street		= $billing->getStreet();
		if ($this->getOrder()->getBillingAddress()->getEmail()) {
			$email = $this->getOrder()->getBillingAddress()->getEmail();
		} else {
			$email = $this->getOrder()->getCustomerEmail();
		}
		
		/*
		//$storeCurrencyCode = Mage::getSingleton('directory/currency')->load($this->getQuote()->getCurrencyCode());
		// $storeCurrencyCode = $this->getOrder()->getStoreCurrencyCode();
		$baseCurrencyCode = $this->getOrder()->getBaseCurrencyCode();
		
		// if the base currency is the same as the payment method, no need of conversion
		if ( $this->_paymentMethodCurrency == $baseCurrencyCode )
		{
			$orderTotal = round($this->getOrder()->getBaseGrandTotal(), 2);
			$orderTotal = number_format($orderTotal, 2, '.', ''); // Example: http://my.php.net/number-format			
		}
		else // convert from store currency to currency supported by gateway
		{
			//$orderTotal = Mage::getSingleton('directory/currency')->convert($this->getOrder()->getGrandTotal(), $this->_paymentMethodCurrency);
				
			$orderTotal = round($this->getOrder()->getGrandTotal() , 2 );
			$orderTotal = number_format($orderTotal, 2, '.', ''); // Example: http://my.php.net/number-format
		}
		*/
		
		//$paymentPlan = 
		
		$baseCurrencyCode = $this->getOrder()->getBaseCurrencyCode();
		$orderTotal = round($this->getOrder()->getBaseGrandTotal(), 2);
		$orderTotal = number_format($orderTotal, 2, '.', '');
		
		// MerchantKey + Merchant Code + RefNo + Amount + Currency
		$stringToHash = Mage::getStoreConfig(self::XML_PATH_MERCHANTKEY) . Mage::getStoreConfig(self::XML_PATH_MERCHANTCODE) . $order_id . $orderTotal * 100 . $baseCurrencyCode;
		$signature = $this->iPay88_signature($stringToHash);
		// Mage::app()->getStore()->getName()
		$params = 	array(
						'MerchantCode'			=> Mage::getStoreConfig(self::XML_PATH_MERCHANTCODE),
						'PaymentId'				=> $this->_paymentMethod,
						'Plan'					=> $paymentPlan,
						'RefNo'					=> $order_id,
						'Amount'				=> $orderTotal,
						'Currency'				=> $baseCurrencyCode,
						'ProdDesc'				=> $this->getOrder()->getStore()->getWebsite()->getName(),
						'UserName'				=> $billing->getFirstname() ." ". $billing->getLastname(),
						'UserEmail'				=> $email,
						'UserContact'			=> $billing->getTelephone(),
						'Remark'				=> 'Mafinsoft.com',
						'Lang'					=> 'UTF-8',
						'Signature'				=> $signature
					);

		return $params;
	}
	
	/**
	 * returns SHA1 signature in base64 encoding - method provided in iPay88 API
	 *
	 * @return string
	 */		
	public function iPay88_signature($source) {
		return base64_encode($this->hex2bin(sha1($source)));
	}
	
	/**
	 * converting hexadecimals to binary - method provided in iPay88 API
	 *
	 * @return string
	 */	
	public function hex2bin($hexSource) {	
		$bin = '';
		$strlen = strlen($hexSource);
		for ($i=0;$i<strlen($hexSource);$i=$i+2) {
			$bin .= chr(hexdec(substr($hexSource,$i,2)));
		}
		return $bin;
	}
	
	/**
	 * compare if the signature given by iPay88 equals to the computed one
	 *
	 * @return bool
	 */
	public function signaturesMatch($response) {
	
		// MerchantKey & MerchantCode & PaymentId & RefNo & Amount & Currency & Status
		$orderTotal = number_format($response['Amount'], 2, '.', '');
		
		$beforeHash = Mage::getStoreConfig(self::XML_PATH_MERCHANTKEY) . Mage::getStoreConfig(self::XML_PATH_MERCHANTCODE) . $response['PaymentId'] . $response['RefNo'] . $orderTotal * 100 . $response['Currency'] . $response['Status'];
		$signature = $this->iPay88_signature($beforeHash);
		
		if ( $signature == $response['Signature'] ) // if both signatures match
			return true;
		else
			return false;
	}
	
	/**
	 * returns the result after re-query. Malcolm wonders if using port 80 is good.
	 *
	 * @return string
	 */	
	public function Requery($response) 
	{
		$query = "http://www.mobile88.com/epayment/enquiry.asp?MerchantCode=" . $response['MerchantCode'] . "&RefNo=" . $response['RefNo'] . "&Amount=" . $response['Amount'];
		$url = parse_url($query);
		$host = $url["host"];
		$path = $url["path"] . "?" . $url["query"];
		$timeout = 1;
		$buf = ''; // declaration
		
		try {
		    $fp = fsockopen ($host, 80, $errno, $errstr, $timeout);
			
			if ($fp)
			{
				fputs ($fp, "GET $path HTTP/1.0\nHost: " . $host . "\n\n");
			
				while (!feof($fp)) 
				{
					$buf .= fgets($fp, 128);
				}
				$lines = split("\n", $buf);
				$Result = $lines[count($lines)-1];
				fclose($fp);
			} 
			else 
			{
				# enter error handing code here
			}
		
		} catch (Exception $e) {
		    $Result = 'Caught exception: ' .  $e->getMessage();
		}
		return $Result;
	}	
}
?>