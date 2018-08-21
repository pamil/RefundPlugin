<?php

declare(strict_types=1);

namespace Tests\Sylius\RefundPlugin\Behat\Context\Application;

use Behat\Behat\Context\Context;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\Exception\CommandDispatchException;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Test\Services\EmailCheckerInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Entity\RefundInterface;
use Webmozart\Assert\Assert;

final class RefundingContext implements Context
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var RepositoryInterface */
    private $refundRepository;

    /** @var CommandBus */
    private $commandBus;

    /** @var EmailCheckerInterface */
    private $emailChecker;

    /** @var OrderInterface|null */
    private $order;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        RepositoryInterface $refundRepository,
        CommandBus $commandBus,
        EmailCheckerInterface $emailChecker
    ) {
        $this->orderRepository = $orderRepository;
        $this->refundRepository = $refundRepository;
        $this->commandBus = $commandBus;
        $this->emailChecker = $emailChecker;
    }

    /**
     * @When I want to refund some units of order :orderNumber
     */
    public function wantToRefundSomeUnitsOfOrder(string $orderNumber): void
    {
        $this->order = $this->orderRepository->findOneByNumber($orderNumber);
    }

    /**
     * @When /^I decide to refund (\d)st "([^"]+)" product with ("[^"]+" payment)$/
     * @When /^I decide to refund (\d)st "([^"]+)" product with ("[^"]+" payment) and "([^"]+)" comment$/
     */
    public function decideToRefundProduct(
        int $unitNumber,
        string $productName,
        PaymentMethodInterface $paymentMethod,
        string $comment = ''
    ): void {
        $unit = $this->getOrderUnit($unitNumber, $productName);

        $this->commandBus->dispatch(new RefundUnits(
            $this->order->getNumber(),
            [$unit->getId()],
            [],
            $paymentMethod->getId(),
            $comment
        ));
    }

    /**
     * @When /^I decide to refund order shipment with ("[^"]+" payment)$/
     */
    public function decideToRefundOrderShipment(PaymentMethodInterface $paymentMethod): void
    {
        $shippingAdjustment = $this->order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT)->first();

        $this->commandBus->dispatch(new RefundUnits($this->order->getNumber(), [], [$shippingAdjustment->getId()], $paymentMethod->getId()));
    }

    /**
     * @When /^I decide to refund order shipment and (\d)st "([^"]+)" product with ("[^"]+" payment)$/
     */
    public function decideToRefundProductAndShipment(
        int $unitNumber,
        string $productName,
        PaymentMethodInterface $paymentMethod
    ): void {
        $shippingAdjustment = $this->order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT)->first();
        $unit = $this->getOrderUnit($unitNumber, $productName);

        $this->commandBus->dispatch(
            new RefundUnits(
                $this->order->getNumber(),
                [$unit->getId()],
                [$shippingAdjustment->getId()],
                $paymentMethod->getId()
            )
        );
    }

    /**
     * @Then /^this order refunded total should be ("[^"]+")$/
     */
    public function refundedTotalShouldBe(int $refundedTotal): void
    {
        $orderRefunds = $this->refundRepository->findBy(['orderNumber' => $this->order->getNumber()]);

        $orderRefundedTotal = array_sum(array_map(function(RefundInterface $refund): int {
            return $refund->getAmount();
        }, $orderRefunds));

        Assert::same($orderRefundedTotal, $refundedTotal);
    }

    /**
     * @Then /^I should not be able to refund (\d)st unit with product "([^"]+)"$/
     */
    public function shouldNotBeAbleToRefundUnitWithProduct(int $unitNumber, string $productName): void
    {
        $unit = $this->getOrderUnit($unitNumber, $productName);

        try {
            $this->commandBus->dispatch(new RefundUnits($this->order->getNumber(), [$unit->getId()], [], 1));
        } catch (CommandDispatchException $exception) {
            return;
        }

        throw new \Exception('RefundUnits command should fail');
    }

    /**
     * @Then I should not be able to refund order shipment
     */
    public function shouldNotBeAbleToRefundOrderShipment(): void
    {
        $shippingAdjustment = $this->order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT)->first();

        try {
            $this->commandBus->dispatch(new RefundUnits($this->order->getNumber(), [], [$shippingAdjustment->getId()], 1));
        } catch (CommandDispatchException $exception) {
            return;
        }

        throw new \Exception('RefundUnits command should fail');
    }

    /**
     * @Then /^I should(?:| still) be able to refund (\d)(?:|st|nd|rd) unit with product "([^"]+)" with ("[^"]+" payment)$/
     */
    public function shouldBeAbleToRefundUnitWithProduct(
        int $unitNumber,
        string $productName,
        PaymentMethodInterface $paymentMethod
    ): void {
        $unit = $this->getOrderUnit($unitNumber, $productName);

        try {
            $this->commandBus->dispatch(new RefundUnits(
                $this->order->getNumber(),
                [$unit->getId()],
                [],
                $paymentMethod->getId())
            );
        } catch (CommandDispatchException $exception) {
            throw new \Exception('RefundUnits command should not fail');
        }
    }

    /**
     * @Then email to :email with credit memo should be sent
     */
    public function emailToWithCreditMemoShouldBeSent(string $email): void
    {
        $this->emailChecker->hasMessageTo('Some of the units from your order have been refunded.', $email);
    }

    /**
     * @Then I should be notified that selected order units have been successfully refunded
     */
    public function notificationSteps(): void
    {
        // intentionally left blank - not relevant in application scope
    }

    private function getOrderUnit(int $unitNumber, string $productName): OrderItemUnitInterface
    {
        $unitsWithProduct = $this->order->getItemUnits()->filter(function(OrderItemUnitInterface $unit) use ($productName): bool {
            return $unit->getOrderItem()->getProduct()->getName() === $productName;
        });

        return $unitsWithProduct->get($unitNumber-1);
    }
}
