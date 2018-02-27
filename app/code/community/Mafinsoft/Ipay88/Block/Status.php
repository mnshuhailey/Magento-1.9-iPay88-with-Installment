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
class Mafinsoft_Ipay88_Block_Status extends Mage_Core_Block_Template
{
	/**
	 * after processing the status from iPay88, redirect and render the HTML of the success or cancel page.
	 */
	protected function _toHtml()
	{
		$url = Mage::getSingleton('checkout/session')->getIpay88RedirectUrl();
		// if session expired, just get the base url, to avoid problems
		if (strlen($url) < 1) $url = $this->getUrl();
		$html = '<html><body>';
		$html.= '<script type="text/javascript">parent.location.href="' . $url . '";</script>';
		$html.= '</body></html>';

		return $html;
	}
}
?>