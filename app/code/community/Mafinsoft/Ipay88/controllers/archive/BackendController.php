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
class Mafinsoft_Ipay88_BackendController extends Mage_Core_Controller_Front_Action {

    /**
     * Processing Block Type
     *
     * @var string
     */
    protected $_redirectBlockType = 'ipay88/processing';
    protected $_statusBlockType = 'ipay88/status';

    protected function _expireAjax() {
        if (!$this->getCheckout()->getQuote()->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1', '403 Session Expired');
            exit;
        }
    }

    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Redirection from store to iPay88 after customer select iPay88 payment method (like credit card or Maybank2u) and click on the Pay button
     */
    public function redirectAction() {
        $session = $this->getCheckout();
        $session->setIpay88QuoteId($session->getQuoteId());
        $session->setIpay88RealOrderId($session->getLastRealOrderId());

        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
        $order->addStatusToHistory('on_holded', Mage::helper('ipay88')->__('Customer was redirected to iPay88.'));
        $order->save();

        $this->getResponse()->setBody(
                $this->getLayout()
                        ->createBlock($this->_redirectBlockType)
                        ->setOrder($order)
                        ->toHtml()
        );

        $session->unsQuoteId();
    }
    
    public function testAction() {
        echo 'RECEIVEOK';
        exit;
    }    

    /**
     * iPay88 returns POST variables to this action (Store Response URL or URL 4)
     */
    //
    public function postAction() {

        $status = $this->processCallback(); // process the callback
        echo 'RECEIVEOK';
        exit;
     }

    /**
     * Display failure page if error
     */
    public function failureAction() {
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
    protected function processCallback() {

        $logFile = 'ipay88_backend.log';
        Mage::log(__METHOD__ . " - " . __LINE__, null, $logFile);

        $testMode = Mage::getStoreConfig('ipay88/settings/ipay88_testmode');

        Mage::log("Test mode: $testMode",null,$logFile);
        
        // if there is no POST data, someone is trying to access the page illegally. As a result, display 404 Page not found
        // if (!$this->getRequest()->isPost()) {
        if (!$this->getRequest()->getPost()) {
            Mage::log('Received backend post with out POST data. Nothing will be done', null, $logFile);
            return false;
        }

        $response = $this->getRequest()->getParams(); // retrieve POST array	from iPay88
        Mage::log('RefNo:'.$response['RefNo'] .';Status:'.$response['Status'].';MerchantCode:'.$response['MerchantCode'].';Amount:'.$response['Amount'], null, $logFile);
        
        // check basic parameters returned by iPay88
        if (!isset($response['RefNo']) || !isset($response['Status']) || !isset($response['MerchantCode']) || !isset($response['Amount'])) {
            Mage::log('Incomplete data in backend post. We will do nothing now.', null, $logFile);
            return false;
        }

        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($response['RefNo']); // instantiate an order object for this RefNo (Magento order number)
        $helper = Mage::helper('ipay88');
        
        if (!$order->getId()) {
            Mage::log('Unable to load order with RefNo:'.$response['RefNo'], null, $logFile);
            return false;
        }else{
            Mage::log('Loaded order RefNo:'.$response['RefNo'].' Order id:'.$order->getId(), null, $logFile);
            //Lock order for processing
            if($helper->isOrderLocked($response['RefNo'])){
                Mage::log('Order '.$response['RefNo'].' has been locked before for processing, will not process this request now',null,$logFile);
                return false;
            }else{
                if($helper->lockOrder($response['RefNo'])){
                    Mage::log('Locked order '.$response['RefNo'].' successfully for processing',null,$logFile);
                }else{
                    Mage::log('Unable to lock order '.$response['RefNo'].' but still continue processing',null,$logFile);
                }

            }
        }

        $currentStatus = $order->getStatus();
        $paymentInst = $order->getPayment()->getMethodInstance();
        
        $receivedBackendPost = false;

        if(!$testMode) {
            // if Signature parameter was not returned or data is null, show 404 page
            if (empty($response['Signature'])) {
                $order->addStatusToHistory('on_holded', Mage::helper('ipay88')->__('No signature field was returned by iPay88.<br/>Error Description: ' . $response['ErrDesc'] . '<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status']));
                $order->save();
                Mage::log('No signature field was returned by iPay88, released order lock and stop processing now',null,$logFile);
                $helper->unlockOrder($response['RefNo']);
                return;
            }

            // EXPERIMENTAL
            // if payment status is said to be successful but the signatures mismatch - do not process at all
            // if ( $response['Status'] == '1' && $this->signaturesMatch($response) == false ) {
            else if ($paymentInst->signaturesMatch($response) == false) {
                $order->addStatusToHistory('on_holded', Mage::helper('ipay88')->__('Signature mismatch. Kindly verify before approving order.<br/>Error Description: ' . $response['ErrDesc'] . '<br/>Transaction ID: ' . $response['TransId'] . '<br/>RefNo: ' . $response['RefNo'] . '<br/>Currency: ' . $response['Currency'] . '<br/>Amount: ' . $response['Amount'] . '<br/>Bank Approval Code: ' . $response['AuthCode'] . '<br/>Payment ID: ' . $response['PaymentId'] . '<br/>Status: ' . $response['Status']));
                $order->save();
            }
        }

        Mage::log(__METHOD__ . " - " . __LINE__, null, $logFile);
        
        // EXPERIMENTAL
        // re-query iPay88 to confirm validity callback data
        $RequeryReply = $paymentInst->Requery($response);
        if ($RequeryReply == '00') {
            $order->addStatusToHistory('on_holded', Mage::helper('ipay88')->__('iPay88 confirms transaction is genuine.'));
            $order->save();
        } else {
            $order->addStatusToHistory('on_holded', Mage::helper('ipay88')->__('Confirmation reply from iPay88: ' . $RequeryReply));
            $order->save();
        }
        
        Mage::log(__METHOD__ . " - " . __LINE__, null, $logFile);

        // if transaction successful or failed ============ Front End Display Decision ================================
        if ($response['Status'] == '1') { // payment successful
            if ( $RequeryReply == '00' )
            {
                if ($order->canInvoice()) {
                    $invoice = $order->prepareInvoice();

                    $invoice->register()->capture();
                    Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
                    $invoice->sendEmail();
                }

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
            $order->save();
        } else if ($response['Status'] == '0') { // payment failed or cancelled
            Mage::log("Response status is 0. Payment failed or was cancelled. We will do nothing now", null, $logFile);
        } else { // similar to above except cancelling or order - this part is unlikely to be executed
            Mage::log("Response status is unknown: . ".$response['Status'].". We will do nothing now", null, $logFile);
        }
        Mage::log("Released lock for order ".$response['RefNo'], null, 'ipay88_backend.log');
        $helper->unlockOrder($response['RefNo']);
        return true;
    }

}
