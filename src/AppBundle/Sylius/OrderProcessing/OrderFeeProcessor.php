<?php

namespace AppBundle\Sylius\OrderProcessing;

use AppBundle\Entity\Delivery;
use AppBundle\Service\DeliveryManager;
use AppBundle\Sylius\Order\AdjustmentInterface;
use AppBundle\Sylius\Order\OrderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

final class OrderFeeProcessor implements OrderProcessorInterface
{
    private $adjustmentFactory;
    private $translator;
    private $deliveryManager;
    private $logger;

    public function __construct(
        AdjustmentFactoryInterface $adjustmentFactory,
        TranslatorInterface $translator,
        DeliveryManager $deliveryManager,
        LoggerInterface $logger)
    {
        $this->adjustmentFactory = $adjustmentFactory;
        $this->translator = $translator;
        $this->deliveryManager = $deliveryManager;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function process(BaseOrderInterface $order): void
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        $restaurant = $order->getRestaurant();

        if (null === $restaurant) {
            return;
        }

        $order->removeAdjustments(AdjustmentInterface::DELIVERY_ADJUSTMENT);
        $order->removeAdjustments(AdjustmentInterface::FEE_ADJUSTMENT);

        $contract = $restaurant->getContract();

        $feeRate = $contract->getFeeRate();
        $customerAmount = $contract->getCustomerAmount();

        $businessAmount = $contract->getFlatDeliveryPrice();
        if ($contract->isVariableDeliveryPriceEnabled()) {
            $businessAmount = $this->deliveryManager->getPrice(
                $this->getDelivery($order),
                $contract->getVariableDeliveryPrice()
            );
            if (null === $businessAmount) {
                $this->logger->error('OrderFeeProcessor | could not calculate price, falling back to flat price');
                $businessAmount = $contract->getFlatDeliveryPrice();
            }
        }

        $deliveryPromotionAdjustments = $order->getAdjustments(AdjustmentInterface::DELIVERY_PROMOTION_ADJUSTMENT);
        foreach ($deliveryPromotionAdjustments as $deliveryPromotionAdjustment) {
            $businessAmount += $deliveryPromotionAdjustment->getAmount();
        }

        $feeAdjustment = $this->adjustmentFactory->createWithData(
            AdjustmentInterface::FEE_ADJUSTMENT,
            $this->translator->trans('order.adjustment_type.platform_fees'),
            (int) (($order->getItemsTotal() * $feeRate) + $businessAmount),
            $neutral = true
        );
        $order->addAdjustment($feeAdjustment);

        $deliveryAdjustment = $this->adjustmentFactory->createWithData(
            AdjustmentInterface::DELIVERY_ADJUSTMENT,
            $this->translator->trans('order.adjustment_type.delivery'),
            $customerAmount,
            $neutral = false
        );
        $order->addAdjustment($deliveryAdjustment);
    }

    private function getDelivery(OrderInterface $order)
    {
        $delivery = $order->getDelivery();

        if (null === $order->getDelivery()) {

            return $this->deliveryManager->createFromOrder($order);
        }

        return $delivery;
    }
}
