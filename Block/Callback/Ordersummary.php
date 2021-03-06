<?php
/**
 * Valitor Module for Magento 2.x.
 *
 * Copyright © 2020 Valitor. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Valitor\Block\Callback;

use Magento\Customer\Model\Context;
use Magento\Sales\Model\Order;
use \Magento\Framework\App\Config\ScopeConfigInterface;

class Ordersummary extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $orderConfig;

    /**
     * @var \Magento\Framework\App\Http\Context
     */
    protected $httpContext;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Address\Renderer
     */
    protected $renderer;

    /**
     * @var \Magento\Catalog\Model\ProductRepository
     */
    protected $productRepository;

    /**
     * @var \Magento\Framework\Pricing\Helper\Data $priceHelper
     */
    protected $priceHelper;
    /**
     * @var ScopeConfigInterface
     */
    protected $_appConfigScopeConfigInterface;

    /**
     * Ordersummary constructor.
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Sales\Model\OrderFactory                $orderFactory
     * @param \Magento\Framework\App\Request\Http              $request
     * @param \Magento\Sales\Model\Order\Config                $orderConfig
     * @param \Magento\Framework\App\Http\Context              $httpContext
     * @param \Magento\Sales\Api\OrderRepositoryInterface      $orderRepository
     * @param \Magento\Framework\App\Http\Context              $renderer
     * @param \Magento\Catalog\Model\ProductRepository         $productRepository
     * @param \Magento\Framework\Pricing\Helper\Data
     * @param array                                            $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Sales\Model\Order\Config $orderConfig,
        \Magento\Framework\App\Http\Context $httpContext,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Address\Renderer $renderer,
        \Magento\Catalog\Model\ProductRepository $productRepository,
        \Magento\Framework\Pricing\Helper\Data $priceHelper,
        ScopeConfigInterface $appConfigScopeConfigInterface,
        array $data = []
    ) {

        parent::__construct($context, $data);
        $this->orderFactory                   = $orderFactory;
        $this->request                        = $request;
        $this->orderConfig                    = $orderConfig;
        $this->httpContext                    = $httpContext;
        $this->orderRepository                = $orderRepository;
        $this->renderer                       = $renderer;
        $this->productRepository              = $productRepository;
        $this->priceHelper                    = $priceHelper;
        $this->_appConfigScopeConfigInterface = $appConfigScopeConfigInterface;
    }

    /**
     * Get orderif from param
     *
     * @return id
     */
    public function getOrderId()
    {
        return $this->request->getParam('shop_orderid');
    }

    /**
     * Load order
     *
     * @return $this
     */

    public function getOrder()
    {
        $orderIncrementId = $this->getOrderId();
        if ($orderIncrementId) {
            return $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
        }

        return '';
    }

    /**
     * Format order address
     *
     * @return mixed
     */

    public function getFormattedAddress()
    {
        $order = $this->getOrder();
        if ($order->getShippingAddress()) {
            return $this->renderer->format($order->getShippingAddress(), 'html');
        } else {
            return $this->renderer->format($order->getBillingAddress(), 'html');
        }

    }


    /**
     * Get order payment title
     *
     * @return string
     */
    public function getPaymentMethodTitle()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $order      = $this->getOrder();
        $storeId    = $order->getStore()->getId();
        $payment    = $order->getPayment();
        $method     = $payment->getMethodInstance();
        $title      = $method->getConfigData('title', $storeId);
        $terminalID = $payment->getMethod();
        if ($title == null) {
            $terminalTitle = $this->_appConfigScopeConfigInterface
                ->getValue('payment/' . $terminalID . '/terminalname', $storeScope);
        } else {
            $terminalTitle = $title;
        }

        return $terminalTitle;
    }

    public function getProductImage()
    {

    }

    /**
     * Load product from productId
     *
     * @param int $id Product id
     *
     * @return $this
     */
    public function getProductById($id)
    {
        return $this->productRepository->getById($id);
    }

    /**
     * Get Formated Price
     *
     * @param fload price
     *
     * @return boolean
     */
    public function getFormatedPrice($price = '')
    {
        return $this->priceHelper->currency($price, true, false);
    }

}
