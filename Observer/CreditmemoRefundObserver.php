<?php
/**
 * Valitor Module for Magento 2.x.
 *
 * Copyright © 2020 Valitor. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Valitor\Observer;

use Valitor\Api\Payments\RefundCapturedReservation;
use Valitor\Exceptions\ResponseHeaderException;
use Valitor\Response\RefundResponse;
use Valitor\Request\OrderLine;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Logger\Monolog;
use SDM\Valitor\Model\SystemConfig;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\SalesRule\Model\RuleFactory;
use \Magento\Sales\Model\ResourceModel\Order\Tax\Item;
use \Magento\Tax\Model\Config as taxConfig;

class CreditmemoRefundObserver implements ObserverInterface
{

    /**
     * @var SystemConfig
     */
    private $systemConfig;
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var Monolog
     */
    private $monolog;

    /**
     * @var Order
     */
    private $order;
    /**
     * @var rule
     */
    protected $rule;
    /**
     * @var taxItem
     */
    protected $taxItem;
    /**
     * @var taxConfig
     */
    private $taxConfig;

    /**
     * @param SystemConfig         $systemConfig
     * @param Monolog              $monolog
     * @param Order                $order
     * @param ScopeConfigInterface $scopeConfig
     * @param RuleFactory          $rule
     * @param Item                 $taxItem
     * @param taxConfig            $taxConfig
     */
    public function __construct(
        SystemConfig $systemConfig,
        Monolog $monolog,
        Order $order,
        ScopeConfigInterface $scopeConfig,
        RuleFactory $rule,
        Item $taxItem,
        taxConfig $taxConfig
    ) {
        $this->systemConfig = $systemConfig;
        $this->monolog      = $monolog;
        $this->order        = $order;
        $this->scopeConfig  = $scopeConfig;
        $this->rule         = $rule;
        $this->taxItem      = $taxItem;
        $this->taxConfig    = $taxConfig;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     * @throws ResponseHeaderException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Api\Data\CreditmemoInterface $memo */
        $memo         = $observer['creditmemo'];
        $creditOnline = $memo->getDoTransaction();
        if ($creditOnline) {
            /** @var \Magento\Sales\Model\Order $order */
            $orderIncrementId = $memo->getOrder()->getIncrementId();
            $orderObject      = $this->order->loadByIncrementId($orderIncrementId);
            $storePriceIncTax = $this->storePriceIncTax($memo->getOrder());
            $storeCode        = $memo->getStore()->getCode();
            /** @var \Magento\Sales\Model\Order\Payment $payment */
            $payment = $memo->getOrder()->getPayment();
            if (in_array($payment->getMethod(), SystemConfig::getTerminalCodes())) {
                $orderlines         = [];
                $couponCode         = $memo->getDiscountDescription();
                $appliedRule        = $memo->getOrder()->getAppliedRuleIds();
                $couponCodeAmount   = $memo->getDiscountAmount();
                $compAmount         = $memo->getOrder()->getShippingDiscountTaxCompensationAmount();
                $discountOnAllItems = $this->allItemsHaveDiscount($memo->getOrder()->getAllVisibleItems());
                foreach ($memo->getAllItems() as $item) {
                    $quantity    = $item->getQty();
                    $taxPercent  = $item->getOrderItem()->getTaxPercent();
                    $taxRate     = (1 + $taxPercent / 100);
                    $productType = $item->getOrderItem()->getProductType();
                    $itemName    = $item->getName();
                    if ($productType == "configurable") {
                        $options = $item->getOrderItem()->getProductOptions();
                        if (isset($options["simple_name"])) {
                            $itemName = $options["simple_name"];
                        }
                    }

                    if ($quantity > 0 && $productType != 'bundle') {
                        $productPrice         = $item->getPrice();
                        $priceInclTax         = $item->getPriceInclTax();
                        $discountAmount       = $item->getDiscountAmount();
                        $originalPrice        = $item->getOrderItem()->getOriginalPrice();
                        $itemDiscount         = 0;
                        $catalogDiscountCheck = false;

                        if (!empty($discountAmount)) {
                            $itemDiscount = ($discountAmount * 100) / ($originalPrice * $quantity);
                        }

                        if ($originalPrice == 0) {
                            $originalPrice = $item->getPriceInclTax();
                        }

                        if ($storePriceIncTax) {
                            $unitPriceWithoutTax = $originalPrice / $taxRate;
                            $unitPrice           = bcdiv($unitPriceWithoutTax, 1, 2);
                            $taxAmount           = ($unitPriceWithoutTax * ($taxPercent / 100)) * $quantity;
                            $taxAmount           = number_format($taxAmount, 2, '.', '');

                            if ($originalPrice > 0 && $originalPrice > $priceInclTax && empty($discountAmount)) {
                                $catalogDiscountCheck = true;
                                $discountAmount       = (($originalPrice - $priceInclTax) / $originalPrice) * 100;
                                $itemDiscount         = number_format($discountAmount, 2, '.', '');
                            }
                        } else {
                            $unitPrice           = $originalPrice;
                            $unitPriceWithoutTax = $originalPrice;

                            if ($originalPrice > 0 && $originalPrice > $productPrice && empty($discountAmount)) {
                                $catalogDiscountCheck = true;
                                $discountAmount       = (($originalPrice - $productPrice) / $originalPrice) * 100;
                                $itemDiscount         = number_format($discountAmount, 2, '.', '');
                            }

                            $dataForPrice = $this->returnDataForPriceExcTax(
                                $item,
                                $unitPrice,
                                $taxPercent,
                                $quantity,
                                $discountOnAllItems
                            );
                            $taxAmount    = number_format($dataForPrice["rawTaxAmount"], 2, '.', '');
                        }

                        if ($priceInclTax) {
                            $orderline = new OrderLine(
                                $itemName,
                                $item->getOrderItem()->getItemId(),
                                $quantity,
                                $unitPrice
                            );
                            $orderline->setGoodsType('item');

                            if ($discountOnAllItems) {
                                $discountedAmount = 0;
                            } else {
                                $discountedAmount = $itemDiscount;
                            }
                            $discountedAmount           = number_format($discountedAmount, 2, '.', '');
                            $orderline->discount        = $discountedAmount;
                            $roundingCompensationAmount = $this->compensationAmountCal(
                                $item,
                                $unitPrice,
                                $unitPriceWithoutTax,
                                $taxAmount,
                                $discountedAmount,
                                $couponCodeAmount,
                                $storePriceIncTax,
                                $catalogDiscountCheck
                            );
                            $orderline->taxAmount       = $taxAmount;
                            $orderline->taxPercent      = $taxPercent;
                            $orderline->productUrl      = $item->getOrderItem()->getProduct()->getProductUrl();
                            $productThumb               = $item->getOrderItem()->getProduct()->getThumbnail();
                            if (!empty($productThumb) && $productThumb !== 'no_selection') {
                                $orderline->imageUrl = $this->getProductImageUrl($memo->getOrder(), $productThumb);
                            }
                            if ($quantity > 1) {
                                $orderline->unitCode = "units";
                            } else {
                                $orderline->unitCode = "unit";
                            }
                            $orderlines[] = $orderline;
                            if ($roundingCompensationAmount > 0 || $roundingCompensationAmount < 0) {
                                $orderline             = new OrderLine(
                                    "Compensation Amount",
                                    "comp-" . $item->getOrderItem()->getItemId(),
                                    1,
                                    $roundingCompensationAmount
                                );
                                $orderline->taxAmount  = 0;
                                $orderline->taxPercent = 0;
                                $orderline->unitCode   = "unit";
                                $orderline->discount   = 0;
                                $orderlines[]          = $orderline;
                            }
                        }
                    }
                }

                if ($discountOnAllItems == true && abs($couponCodeAmount) > 0) {
                    if (empty($couponCode)) {
                        $couponCode = 'Cart Price Rule';
                    }
                    // Handling price reductions
                    $orderline = new OrderLine(
                        $couponCode,
                        'discount',
                        1,
                        $couponCodeAmount
                    );
                    $orderline->setGoodsType('handling');
                    $orderlines[] = $orderline;
                }

                if ($memo->getShippingInclTax() > 0) {
                    //add shipping tax amount in separate column of request
                    $discountPercentage = array();
                    $itemDiscount       = 0;
                    $shippingTax        = $memo->getShippingTaxAmount();
                    $shippingAmount     = $memo->getShippingAmount();
                    $shippingTaxPercent = $this->getOrderShippingTax($memo->getOrder()->getId());

                    $orderline = new OrderLine(
                        'Shipping',
                        'shipping',
                        1,
                        $shippingAmount
                    );
                    if ($discountOnAllItems) {
                        $orderline->discount = 0;
                    } else {
                        $orderline->discount = ($memo->getOrder()->getShippingDiscountAmount() / $memo->getOrder()->getShippingAmount()) * 100;
                    }
                    if ($shippingTaxPercent > 0) {
                        $shippingTax = $shippingAmount * ($shippingTaxPercent / 100);
                        $shippingTax = number_format($shippingTax, 2, '.', '');
                    }
                    $orderline->taxAmount  = $shippingTax;
                    $orderline->taxPercent = $shippingTaxPercent;
                    $orderline->setGoodsType('shipment');
                    $orderlines[] = $orderline;

                    if ($compAmount > 0 && $discountOnAllItems == false) {
                        /*Add tax percentage in compensation amount*/
                        $compAmount = $compAmount + ($compAmount * ($shippingTaxPercent / 100));

                        $orderline    = new OrderLine(
                            "Shipping compensation",
                            "comp-ship",
                            1,
                            $compAmount
                        );
                        $orderlines[] = $orderline;
                    }
                }

                $refund = new RefundCapturedReservation($this->systemConfig->getAuth($storeCode));
                if ($memo->getTransactionId()) {
                    $refund->setTransaction($payment->getLastTransId());
                }
                $refund->setAmount((float)number_format($memo->getGrandTotal(), 2, '.', ''));
                $refund->setOrderLines($orderlines);
                /** @var RefundResponse $response */
                try {
                    $response = $refund->call();
                } catch (ResponseHeaderException $e) {
                    $this->monolog->addCritical('Response header exception: ' . $e->getMessage());
                    throw $e;
                } catch (\Exception $e) {
                    $this->monolog->addCritical('Exception: ' . $e->getMessage());
                }

                $rawresponse = $refund->getRawResponse();
                $body        = $rawresponse->getBody();
                $this->monolog->addInfo('Response body: ' . $body);

                //Update comments if refund fail
                $xml = simplexml_load_string($body);
                if ($xml->Body->Result == 'Error' || $xml->Body->Result == 'Failed') {
                    $orderObject->addStatusHistoryComment('Refund failed: ' . $xml->Body->MerchantErrorMessage)
                                ->setIsCustomerNotified(false);
                    $orderObject->getResource()->save($orderObject);
                }

                if ($xml->Body->Result != 'Success') {
                    throw new \InvalidArgumentException('Could not refund captured reservation');
                }
            }
        }
    }

    /**
     * @param $orderItems
     *
     * @return bool
     */
    private function allItemsHaveDiscount($orderItems)
    {
        $discountOnAllItems = true;
        foreach ($orderItems as $item) {
            $appliedRule = $item->getAppliedRuleIds();
            $productType = $item->getProductType();
            if (!empty($appliedRule)) {
                $appliedRuleArr = explode(",", $appliedRule);
                foreach ($appliedRuleArr as $ruleId) {
                    $couponCodeData  = $this->rule->create()->load($ruleId);
                    $applyToShipping = $couponCodeData->getData('apply_to_shipping');
                    if (!$applyToShipping && $productType != 'virtual' && $productType != 'downloadable') {
                        $discountOnAllItems = false;
                    }
                }
            } else {
                $discountOnAllItems = false;
            }
        }

        return $discountOnAllItems;
    }

    /**
     * @param $item
     * @param $unitPrice
     * @param $taxPercent
     * @param $quantity
     * @param $discountOnAllItems
     *
     * @return mixed
     */
    private function returnDataForPriceExcTax(
        $item,
        $unitPrice,
        $taxPercent,
        $quantity,
        $discountOnAllItems
    ) {
        if ($discountOnAllItems) {
            $data["rawTaxAmount"] = $item->getTaxAmount();
        } else {
            $data["rawTaxAmount"] = ($unitPrice * ($taxPercent / 100)) * $quantity;
        }

        return $data;
    }

    /**
     * @param $orderID
     *
     * @return int
     */
    private function getOrderShippingTax($orderID)
    {
        $shippingTaxPercent = 0;
        $tax_items          = $this->taxItem->getTaxItemsByOrderId($orderID);
        if (!empty($tax_items) && is_array($tax_items)) {
            foreach ($tax_items as $item) {
                if ($item['taxable_item_type'] === 'shipping') {
                    $shippingTaxPercent += $item['tax_percent'];
                }
            }
        }

        return $shippingTaxPercent;
    }

    /**
     * @param $discountPercentage
     *
     * @return float|int|mixed
     */
    private function getItemDiscountByPercentage($discountPercentage)
    {
        $itemDiscount = 0;
        if (count($discountPercentage) == 1) {
            $itemDiscount = array_shift($discountPercentage);
            $itemDiscount = $itemDiscount * 100;
        } elseif (count($discountPercentage) > 1) {
            $discountSum     = array_sum($discountPercentage);
            $discountProduct = array_product($discountPercentage);
            $itemDiscount    = ($discountSum - $discountProduct) * 100;
        }

        return $itemDiscount;
    }

    /**
     * @param $store
     *
     * @return bool
     */
    private function checkSettingsTaxAfterDiscount($store = null)
    {
        return $this->taxConfig->applyTaxAfterDiscount($store);
    }

    /**
     * @param $order
     *
     * @return bool
     */
    private function storePriceIncTax($order = null)
    {
        if ($order !== null) {
            if ($order->getValitorPriceIncludesTax() !== null) {
                return $order->getValitorPriceIncludesTax();
            }
        }
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        if ((int)$this->scopeConfig->getValue('tax/calculation/price_includes_tax', $storeScope) === 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $item
     * @param $unitPrice
     * @param $unitPriceWithoutTax
     * @param $taxAmount
     * @param $discountedAmount
     * @param $couponCodeAmount
     * @param $storePriceIncTax
     * @param $catalogDiscountCheck
     *
     * @return float|int
     */
    private function compensationAmountCal(
        $item,
        $unitPrice,
        $unitPriceWithoutTax,
        $taxAmount,
        $discountedAmount,
        $couponCodeAmount,
        $storePriceIncTax,
        $catalogDiscountCheck
    ) {
        $taxPercent   = $item->getOrderItem()->getTaxPercent();
        $quantity     = $item->getQty();
        $compensation = 0;
        //Discount compensation calculation - Gateway calculation pattern
        $gatewaySubtotal = ($unitPrice * $quantity) + $taxAmount;
        $gatewaySubtotal = $gatewaySubtotal - ($gatewaySubtotal * ($discountedAmount / 100));
        // Magento calculation pattern
        if (abs($couponCodeAmount) > 0 && $storePriceIncTax) {
            $magentoPriceCal = $unitPriceWithoutTax * $quantity;
            $magentoTaxCal   = $magentoPriceCal * ($taxPercent / 100);
            $magentoSubtotal = $magentoPriceCal + $magentoTaxCal;
            $magentoSubtotal = $magentoSubtotal - ($magentoSubtotal * ($discountedAmount / 100));
            $compensation    = $magentoSubtotal - $gatewaySubtotal;
        } elseif ($catalogDiscountCheck || empty($couponCodeAmount)) {
            $cmsSubTotal  = $item->getOrderItem()->getBaseRowTotal() + $item->getBaseTaxAmount();
            $compensation = $cmsSubTotal - $gatewaySubtotal;
        }

        return $compensation;
    }

    /**
     * Get image url by imagename.
     *
     * @param        $order
     * @param string $image
     *
     * @return string
     */
    protected function getProductImageUrl($order, $image)
    {
        $url = $image;
        if ($image) {
            if (is_string($image)) {
                $url = $order->getStore()->getBaseUrl(
                        \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                    ) . 'catalog/product/' . $image;
            }
        }

        return $url;
    }

    /**
     * @param \Magento\Sales\Model\Order\Invoice\Item $item
     */
    protected function logItem($item)
    {
        $this->valitorLogger->addInfoLog(
            'Log Item',
            sprintf(
                implode(' - ', [
                    'getSku: %s',
                    'getQty: %s',
                    'getDescription: %s',
                    'getPrice(): %s',
                    'getDiscountAmount(): %s',
                    'getPrice() - getDiscountAmount(): %s',
                    'getRowTotalInclTax: %s',
                    'getRowTotal: %s'
                ]),
                $item->getSku(),
                $item->getQty(),
                $item->getDescription(),
                $item->getPrice(),
                $item->getDiscountAmount(),
                $item->getPrice() - $item->getDiscountAmount(),
                $item->getRowTotalInclTax(),
                $item->getRowTotal()
            )
        );
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     */
    protected function logPayment($payment, $invoice)
    {
        $logs = [
            'invoice.getTransactionId: %s',
            'invoice->getOrder()->getIncrementId: %s',
            '$invoice->getGrandTotal(): %s',
            'getLastTransId: %s',
            'getAmountAuthorized: %s',
            'getAmountCanceled: %s',
            'getAmountOrdered: %s',
            'getAmountPaid: %s',
            'getAmountRefunded: %s',
        ];

        $this->valitorLogger->addInfoLog(
            'Log Transaction',
            sprintf(
                implode(' - ', $logs),
                $invoice->getTransactionId(),
                $invoice->getOrder()->getIncrementId(),
                $invoice->getGrandTotal(),
                $payment->getLastTransId(),
                $payment->getAmountAuthorized(),
                $payment->getAmountCanceled(),
                $payment->getAmountOrdered(),
                $payment->getAmountPaid(),
                $payment->getAmountRefunded()
            )
        );
    }
}
