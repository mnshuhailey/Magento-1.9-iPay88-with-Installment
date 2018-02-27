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
 * @license	 http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Mafinsoft_Ipay88_ProcessingController extends Mage_Core_Controller_Front_Action
{
	/**
	 * Processing Block Type
	 *
	 * @var string
	 */
	protected $_redirectBlockType = 'ipay88/processing';
	protected $_statusBlockType	= 'ipay88/status';

	protected function _expireAjax()
	{
		if (!$this->getCheckout()->getQuote()->hasItems()) {
			$this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
			exit;
		}
	}

	/**
	 * Get singleton of Checkout Session Model
	 *
	 * @return Mage_Checkout_Model_Session
	 */
	public function getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}

	/**
	 * Redirection from store to iPay88 after customer select iPay88 payment method (like credit card or Maybank2u) and click on the Pay button
	 */
	public function redirectAction()
	{
		$session = $this->getCheckout();
		$session->setIpay88QuoteId($session->getQuoteId());
		$session->setIpay88RealOrderId($session->getLastRealOrderId());

		$order = Mage::getModel('sales/order');
		$order->loadByIncrementId($session->getLastRealOrderId());
		$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('ipay88')->__('Customer was redirected to iPay88.'));
		$order->save();

		$this->getResponse()->setBody(
			$this->getLayout()
				->createBlock($this->_redirectBlockType)
				->setOrder($order)
				->toHtml()
		);

		$session->unsQuoteId();
	}
	
	/**
	 * iPay88 returns POST variables to this action (Store Response URL or URL 4)
	 */
	//
	public function statusAction()
	{
		$status = $this->processCallback(); // process the callback
		
		//$session = $this->getCheckout();
		//$session->unsIpay88RealOrderId();
		//$session->setQuoteId($session->getIpay88QuoteId(true)); // anything wrong here? changed Ipay88ID to QuoteID
		//$session->getQuote()->setIsActive(false)->save();
		
		if ($status) {
			$this->getResponse()->setBody(
				$this->getLayout()
					->createBlock($this->_statusBlockType) 
					->toHtml()
			);
		}
	}
	
	/**
	 * Display failure page if error
	 */
	public function failureAction()
	{
		if (!$this->getCheckout()->getIpay88ErrorMessage()) {
			$this->norouteAction();
			return;
		}

		$this->getCheckout()->clear();


		$this->loadLayout();
		$this->renderLayout();
	}

	/**
	 * Checking POST variables. - this is processed at URL 4
	 * Creating invoice if payment was successful or cancel order if payment was declined
	 */
	protected function processCallback()
	{
		// if there is no POST data, someone is trying to access the page illegally. As a result, display 404 Page not found
		// if (!$this->getRequest()->isPost()) {
		if (!$this->getRequest()->getPost()) {
			Mage::log("Invalid request because no POST data",null,'ipay88.log');
			$this->norouteAction();
			return;
		}
		
		$response = $this->getRequest()->getParams();	// retrieve POST array	from iPay88	
		// check basic parameters returned by iPay88
		if (!isset($response['RefNo']) || !isset($response['Status']) || !isset($response['MerchantCode']) || !isset($response['Amount']) ) {
			Mage::log("callback request with insufficient parameters",null,'ipay88.log');
			$this->norouteAction();
			return;
		}

		$logFile = 'ipay88_process_' . $response['RefNo'] . '.log';

		Mage::log('RefNo: ' . $response['RefNo'] .'; Status: '. $response['Status'] . '; MerchantCode: '. $response['MerchantCode'] . '; Amount: '. $response['Amount'],null,$logFile);

		$helper = Mage::helper('ipay88');

		$order = Mage::getModel('sales/order');
		$order->loadByIncrementId($response['RefNo']); // instantiate an order object for this RefNo (Magento order number)
		if(!$order->getId()) {
			Mage::log("Unable to load order from Magento with order ref: ".$response['RefNo'],null,$logFile);
			$this->norouteAction();
			return;
		}
		
		// EXPERIMENTAL
		// check order ID - if merchant order id/transaction number mismatch
		if ($this->getCheckout()->getIpay88RealOrderId() != $response['RefNo']) {
			Mage::log('Double check order increment id in Magento and returned RefNo from ipay88: Mismatched. Do nothing now',null,$logFile);
			$this->norouteAction();
			return;
		}	
		
		/*
		// EXPERIMENTAL
		// check if order amount equals the amount given in the callback - possibility of customer editing a cart during checkout process and before call back returns
		$orderTotal = round($order->getOrder()->getBaseGrandTotal() , 2 );
		$orderTotal = number_format($orderTotal, 2, '.', ''); // Example: http://my.php.net/number-format		
		if ($orderTotal != $response['Amount']) {
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('ipay88')->__('The amount '. $response['Amount'] .' given by iPay88 is incorrect. The correct order amount should be ' . $orderTotal));
			$order->save();
			$this->norouteAction();
			return;	
		}
		*/
		
		$paymentInst = $order->getPayment()->getMethodInstance();
		// if Signature parameter was not returned or data is null, show 404 page
		if ( empty($response['Signature']) ) {
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('ipay88')->__('No signature field was returned by iPay88.<br/>Error Description: ' . $response['ErrDesc'] . '<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status']) );
			$order->save();
			$this->norouteAction();
			return;			
		}
		// EXPERIMENTAL
		// if payment status is said to be successful but the signatures mismatch - do not process at all
		// if ( $response['Status'] == '1' && $this->signaturesMatch($response) == false ) {
		else if ( $paymentInst->signaturesMatch($response) == false ) {
		 	$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('ipay88')->__('Signature mismatch. Kindly verify before approving order.<br/>Error Description: ' . $response['ErrDesc'] . '<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status']) );
		 	$order->save();
			//$this->norouteAction();
			//return;
		}
		
		// EXPERIMENTAL
		// re-query iPay88 to confirm validity callback data
		$RequeryReply = $paymentInst->Requery($response);
		if ( $RequeryReply == '00' )
		{
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('ipay88')->__('iPay88 confirms transaction is genuine.'));
			$order->save();		
		}
		else
		{
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('ipay88')->__('Confirmation reply from iPay88: ' . $RequeryReply) );
			$order->save();
		}
		
		// if transaction successful or failed ============ Front End Display Decision ================================
		if ($response['Status'] == '1') // payment successful
		{			
			$this->getCheckout()->setIpay88RedirectUrl(Mage::getUrl('checkout/onepage/success'));
			
			if ( $RequeryReply == '00' )
			{
				$order->sendNewOrderEmail();
				
				// remove shopping cart session
				$session = $this->getCheckout();
				$session->unsIpay88RealOrderId();
				$session->setIpay88Id($session->getIpay88QuoteId(true)); // anything wrong here? changed Ipay88ID to QuoteID
				$session->getQuote()->setIsActive(false)->save();
			}
		}
		else if ($response['Status'] == '0') // payment failed or cancelled
		{
			$order->cancel();
			$this->getCheckout()->setIpay88RedirectUrl(Mage::getUrl('*/*/failure'));
			
			if (isset($response['ErrDesc']))
				$message = $response['ErrDesc'] . '. iPay88 Transaction ID: ' . $response['TransId'] . '. Bank Approval Code: ' . $response['AuthCode'];
			else
				$message = 'No error description was sent by iPay88.';
			
			// error message for customers (displayed on failure page)
			$this->getCheckout()->setIpay88ErrorMessage($message);

			$session = $this->getCheckout();
			$session->getQuote()->setIsActive(true)->save();
		}
		else // similar to above except cancelling or order - this part is unlikely to be executed
		{
			$this->getCheckout()->setIpay88RedirectUrl(Mage::getUrl('*/*/failure'));
			
			if (isset($response['ErrDesc']))
				$message = $response['ErrDesc'] . '. iPay88 Transaction ID: ' . $response['TransId'] . '. Bank Approval Code: ' . $response['AuthCode'];
			else
				$message = 'No error description was sent by iPay88.';
			
			// error message for customers (displayed on failure page)
			$this->getCheckout()->setIpay88ErrorMessage($message); // log the error message from iPay88
			
			$session = $this->getCheckout();
			$session->getQuote()->setIsActive(true)->save();			
		}
		
		//============================================ Back End Processing ============================================
		$paymentInst->setTransactionId($response['TransId']); // set to given iPay88 transaction ID
		if ($response['Status'] == '1')  // should the checking be more stringent by adding the condition $RequeryReply == '00' ?
		{
			if ($order->canInvoice()) {
				Mage::log('This order can be invoiced, start creating invoice',null,$logFile);
				$invoice = $order->prepareInvoice();
				Mage::log('Invoice was prepared',null,$logFile);
				$invoice->register()->capture();
				Mage::log('Invoice was captured',null,$logFile);
				Mage::getModel('core/resource_transaction')
					->addObject($invoice)
					->addObject($invoice->getOrder())
					->save();
				$invoice->sendEmail();
				Mage::log('Invoice email was sent',null,$logFile);
			}
			
			if ( $RequeryReply == '00' )
			{
				// error message for internal use only
				$order_status = Mage::helper('ipay88')->__('Payment is successful.');
			
				// $order->addStatusToHistory($paymentInst->getConfigData('order_status'), $order_status);
				$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_PROCESSING, $order_status);
			}
			else
			{
				// error message for internal use only - likely to be executed if the re-query failed
				$order_status = Mage::helper('ipay88')->__('Caution. Possible fraud or iPay88 gateway was down. iPay88 was not able to reconfirm this payment status during the payment process. Kindly double check with your iPay88 report before approving this order. Second confirmation from iPay88: ' . $RequeryReply);
				// $order_status = Mage::helper('ipay88')->__('Use the following callback data from iPay88 for verification.<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status'] . '<br/>Signature: ' . $response['Signature'] );
			
				// $order->addStatusToHistory($paymentInst->getConfigData('order_status'), $order_status);
				$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, $order_status);
			}
		}
		else if ($response['Status'] == '0') 
		{
			// error message for internal use only
			$order_status = Mage::helper('ipay88')->__('Payment failed or was cancelled.<br/>Error Description: ' . $response['ErrDesc'] . '<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status'] . '<br/>Signature: ' . $response['Signature'] );
			$order->cancel();
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_CANCELED, $order_status);
			// $order->addStatusToHistory($paymentInst->getConfigData('order_status'), $order_status);
			// $status = false;
		}
		else // - this part is unlikely to be executed
		{
			// error message for internal use only
			$order_status = Mage::helper('ipay88')->__('Invalid payment status. <br/>Error Description: ' . $response['ErrDesc'] . '<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status'] . '<br/>Signature: ' . $response['Signature'] );
			
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, $order_status);
		}		
		//=========================================End of Back End Processing ============================================
		
		$order->save();
		Mage::log('Order processing completed. Order was saved successfully.',null,$logFile);

		return true;
	}
}