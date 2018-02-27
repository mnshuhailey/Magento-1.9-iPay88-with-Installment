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
 * @copyright  Copyright (c) 2009 Mafinsoft Solutions (http://www.mafinsoft.com
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Mafinsoft_Ipay88_Block_Processing extends Mage_Core_Block_Abstract
{
	/**
	 * prepare the HTML form and submit to iPay88 server
	 */
	protected function _toHtml()
	{
		$payment = $this->getOrder()->getPayment()->getMethodInstance();

		$form = new Varien_Data_Form();
		$form->setAction($payment->getUrl())
			->setId('ipay88_checkout')
			->setName('ipay88_checkout')
			->setMethod('POST')
			->setUseContainer(true);
		foreach ($payment->getFormFields() as $field=>$value) {
			$form->addField($field, 'hidden', array('name'=>$field, 'value'=>$value));
		}
		$html = '<html><body>';
		$html.= $this->__('Redirecting to secure payment page powered by iPay88...');
		$html.= $form->toHtml();
		$html.= '<script type="text/javascript">document.getElementById("ipay88_checkout").submit();</script>';
		$html.= '</body></html>';
		return $html;
	}
}
?>