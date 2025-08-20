<?php
declare(strict_types=1);

namespace MagoArab\EasYorder\Controller\Ajax;

use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Api\CouponManagementInterface;
use Psr\Log\LoggerInterface;

class Coupon implements HttpPostActionInterface
{
    private $request;
    private $jsonFactory;
    private $quickOrderService;
    private $helperData;
    private $cartRepository;
    private $checkoutSession;
    private $logger;
    private $couponManagement;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        QuickOrderServiceInterface $quickOrderService,
        HelperData $helperData,
        CartRepositoryInterface $cartRepository,
        LoggerInterface $logger,
        CheckoutSession $checkoutSession = null
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->quickOrderService = $quickOrderService;
        $this->helperData = $helperData;
        $this->cartRepository = $cartRepository;
        // Support both DI-injected and fallback runtime session to avoid constructor signature issues
        $this->checkoutSession = $checkoutSession ?: ObjectManager::getInstance()->get(CheckoutSession::class);
        $this->logger = $logger;
        // Try to resolve core coupon management for native validation
        try {
            $this->couponManagement = ObjectManager::getInstance()->get(CouponManagementInterface::class);
        } catch (\Throwable $t) {
            $this->couponManagement = null;
        }
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            if (!$this->helperData->isEnabled()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Quick order is not enabled.')
                ]);
            }

            $action = $this->request->getParam('action'); // 'apply' or 'remove'
            $couponCode = trim($this->request->getParam('coupon_code', ''));
            // Prefer the current checkout session quote; fallback to explicit quote_id if sent
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                $quoteId = (int)$this->request->getParam('quote_id');
                if ($quoteId) {
                    $quote = $this->cartRepository->get($quoteId);
                }
            }
            if (!$quote || !$quote->getId()) {
                // As a final fallback, try to create/attach a new quote to session so coupon API works
                $quote = $this->checkoutSession->getQuote();
                if (!$quote->getId()) {
                    $quote->save();
                }
            }
            if (!$quote || !$quote->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Unable to locate active quote')
                ]);
            }

            if ($action === 'apply') {
                if (empty($couponCode)) {
                    return $result->setData([
                        'success' => false,
                        'message' => __('Coupon code is required')
                    ]);
                }
                if ($this->couponManagement) {
                    $ok = $this->couponManagement->set($quote->getId(), $couponCode);
                    $quote->setTotalsCollectedFlag(false);
                    $quote->collectTotals();
                    $this->cartRepository->save($quote);
                    if ($ok && $quote->getCouponCode()) {
                        return $result->setData([
                            'success' => true,
                            'message' => __('Coupon code applied successfully'),
                            'coupon_code' => $quote->getCouponCode()
                        ]);
                    }
                    return $result->setData([
                        'success' => false,
                        'message' => __('Invalid coupon code or no discount applied')
                    ]);
                }

                $response = $this->quickOrderService->applyCouponCode($quote, $couponCode);
            } elseif ($action === 'remove') {
                if ($this->couponManagement) {
                    $ok = $this->couponManagement->remove($quote->getId());
                    $quote->setTotalsCollectedFlag(false);
                    $quote->collectTotals();
                    $this->cartRepository->save($quote);
                    return $result->setData([
                        'success' => (bool)$ok,
                        'message' => $ok ? __('Coupon code removed successfully') : __('Unable to remove coupon')
                    ]);
                }
                $response = $this->quickOrderService->removeCouponCode($quote);
            } else {
                return $result->setData([
                    'success' => false,
                    'message' => __('Invalid action')
                ]);
            }

            return $result->setData($response);

        } catch (\Exception $e) {
            $this->logger->error('Coupon action error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __('Unable to process coupon action.')
            ]);
        }
    }
}