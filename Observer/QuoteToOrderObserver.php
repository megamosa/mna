<?php
/**
 * MagoArab_EasYorder Quote to Order Observer
 * Ensures Order values match Quote values exactly
 */

declare(strict_types=1);

namespace MagoArab\EasYorder\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class QuoteToOrderObserver implements ObserverInterface
{
    private $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * Ensure Order preserves Quote rules and totals during conversion
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getOrder();
            $quote = $observer->getEvent()->getQuote();

            if (!$order || !$quote) {
                return;
            }

            $this->logger->info('=== OBSERVER: Quote to Order conversion started ===', [
                'order_id' => $order->getId(),
                'quote_id' => $quote->getId(),
                'order_before_observer' => [
                    'subtotal' => $order->getSubtotal(),
                    'shipping' => $order->getShippingAmount(),
                    'discount' => $order->getDiscountAmount(),
                    'grand_total' => $order->getGrandTotal(),
                    'applied_rule_ids' => $order->getAppliedRuleIds(),
                    'coupon_code' => $order->getCouponCode(),
                    'discount_description' => $order->getDiscountDescription()
                ],
                'quote_values' => [
                    'subtotal' => $quote->getSubtotal(),
                    'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                    'shipping' => $quote->getShippingAddress()->getShippingAmount(),
                    'discount' => $quote->getShippingAddress()->getDiscountAmount(),
                    'grand_total' => $quote->getGrandTotal(),
                    'items_qty' => $quote->getItemsQty(),
                    'total_qty' => $quote->getItemsCount(),
                    'free_shipping' => $quote->getShippingAddress()->getFreeShipping()
                ],
                'quote_applied_rules' => $quote->getAppliedRuleIds(),
                'quote_coupon' => $quote->getCouponCode(),
                'quote_discount_description' => $quote->getShippingAddress()->getDiscountDescription()
            ]);

            // Preserve applied rule IDs from quote
            if ($quote->getAppliedRuleIds()) {
                $order->setAppliedRuleIds($quote->getAppliedRuleIds());
            }
            
            // Preserve coupon code
            if ($quote->getCouponCode()) {
                $order->setCouponCode($quote->getCouponCode());
                $order->setCouponRuleName($quote->getCouponCode()); // For display in admin
            }
            
            // Preserve discount description for admin display
            $discountDescription = $quote->getShippingAddress()->getDiscountDescription();
            if ($discountDescription) {
                $order->setDiscountDescription($discountDescription);
            }

            // Ensure correct totals are maintained
            $order->setSubtotal($quote->getSubtotal());
            $order->setBaseSubtotal($quote->getBaseSubtotal());
            
            $shippingAmount = $quote->getShippingAddress()->getShippingAmount();
            $order->setShippingAmount($shippingAmount);
            $order->setBaseShippingAmount($shippingAmount);
            
            $discountAmount = $quote->getShippingAddress()->getDiscountAmount();
            if ($discountAmount != 0) {
                $order->setDiscountAmount($discountAmount);
                $order->setBaseDiscountAmount($discountAmount);
            }
            
            $order->setGrandTotal($quote->getGrandTotal());
            $order->setBaseGrandTotal($quote->getBaseGrandTotal());

            // DETAILED RULE TRACKING FOR ADMIN VISIBILITY
            $quoteItems = $quote->getAllVisibleItems();
            $orderItems = $order->getAllVisibleItems();
            
            $itemRuleAnalysis = [];
            foreach ($quoteItems as $index => $quoteItem) {
                $orderItem = isset($orderItems[$index]) ? $orderItems[$index] : null;
                $itemRuleAnalysis[] = [
                    'quote_item_id' => $quoteItem->getId(),
                    'order_item_id' => $orderItem ? $orderItem->getId() : null,
                    'sku' => $quoteItem->getSku(),
                    'quote_price' => $quoteItem->getPrice(),
                    'quote_original_price' => $quoteItem->getProduct()->getPrice(),
                    'order_price' => $orderItem ? $orderItem->getPrice() : null,
                    'quote_row_total' => $quoteItem->getRowTotal(),
                    'order_row_total' => $orderItem ? $orderItem->getRowTotal() : null,
                    'catalog_rule_applied' => $quoteItem->getPrice() != $quoteItem->getProduct()->getPrice(),
                    'quote_applied_rule_ids' => $quoteItem->getAppliedRuleIds()
                ];
            }

            $this->logger->info('=== OBSERVER: Quote to Order conversion completed ===', [
                'order_after_observer' => [
                    'subtotal' => $order->getSubtotal(),
                    'shipping' => $order->getShippingAmount(),
                    'discount' => $order->getDiscountAmount(),
                    'grand_total' => $order->getGrandTotal(),
                    'applied_rule_ids' => $order->getAppliedRuleIds(),
                    'coupon_code' => $order->getCouponCode(),
                    'discount_description' => $order->getDiscountDescription()
                ],
                'RULES_TRANSFER_STATUS' => [
                    'quote_had_rules' => !empty($quote->getAppliedRuleIds()),
                    'order_has_rules' => !empty($order->getAppliedRuleIds()),
                    'quote_had_coupon' => !empty($quote->getCouponCode()),
                    'order_has_coupon' => !empty($order->getCouponCode()),
                    'discount_description_transferred' => !empty($order->getDiscountDescription()),
                    'rules_successfully_transferred' => $quote->getAppliedRuleIds() === $order->getAppliedRuleIds()
                ],
                'rule_analysis' => [
                    'quote_rules' => $quote->getAppliedRuleIds(),
                    'order_rules' => $order->getAppliedRuleIds(),
                    'rules_transferred' => $quote->getAppliedRuleIds() === $order->getAppliedRuleIds(),
                    'item_level_analysis' => $itemRuleAnalysis
                ],
                'values_preserved_correctly' => [
                    'subtotal_match' => $order->getSubtotal() == $quote->getSubtotal(),
                    'shipping_match' => $order->getShippingAmount() == $quote->getShippingAddress()->getShippingAmount(),
                    'discount_match' => $order->getDiscountAmount() == $quote->getShippingAddress()->getDiscountAmount(),
                    'grand_total_match' => $order->getGrandTotal() == $quote->getGrandTotal(),
                    'rules_match' => $order->getAppliedRuleIds() === $quote->getAppliedRuleIds(),
                    'coupon_match' => $order->getCouponCode() === $quote->getCouponCode()
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('QuoteToOrderObserver error: ' . $e->getMessage());
        }
    }
}
