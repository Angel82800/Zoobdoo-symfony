<?php

namespace Erp\PropertyBundle\Command;

use Erp\StripeBundle\Entity\Transaction;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Erp\PropertyBundle\Entity\ScheduledRentPayment;
use Erp\PaymentBundle\Entity\StripeCustomer;
use Erp\StripeBundle\Helper\ApiHelper;

class CheckScheduledPaymentCommand extends ContainerAwareCommand {

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * @inheritdoc
     */
    protected function configure() {
        $this
                ->setName('erp:property:check-scheduled-payment')
                ->setDescription('Charge Tenants');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->getEntityManager();
        $recurringPaymentRepository = $this->em->getRepository(ScheduledRentPayment::class);

        $scheduledRentPayments = $recurringPaymentRepository->getScheduledRecurringPayments();
        $scheduledSinglePayments = $recurringPaymentRepository->getScheduledSinglePayments();

        $this->makePayment($scheduledRentPayments);
        $this->makePayment($scheduledSinglePayments);
    }

    /**
     * 
     * @param array $payments
     */
    private function makePayment(array $payments) {
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $apiManager = $container->get('erp_stripe.entity.api_manager');
        $this->getEntityManager();

        $i = 0;
        /** @var ScheduledRentPayment $payment */
        foreach ($payments as $payment) {
            $property = $payment->getProperty();
            $metadataInternalStatus = ($payment->getStatus() == ScheduledRentPayment::STATUS_FAILURE)
                    ? Transaction::INTERNAL_TYPE_LATE_RENT_PAYMENT
                    : Transaction::INTERNAL_TYPE_RENT_PAYMENT
            ;

            $arguments = [
                'params' => [
                    //TODO Refactoring amount in payRentAction form
                    'amount' => ApiHelper::convertAmountToStripeFormat($payment->getAmount()),
                    'currency' => StripeCustomer::DEFAULT_CURRENCY,
                    'customer' => $payment->getCustomer()->getCustomerId(),
                    'metadata' => [
                        'account' => $payment->getAccount()->getAccountId(),
                        'internalType' => $metadataInternalStatus,
                        'propertyId' => $property->getId()
                    ],
                ],
                'options' => [
                    'stripe_account' => $payment->getAccount()->getAccountId()
                ]
            ];
            $response = $apiManager->callStripeApi('\Stripe\Charge', 'create', $arguments);

            if (!$response->isSuccess()) {
                $status = ScheduledRentPayment::STATUS_FAILURE;
                $logger->critical(json_encode($response->getErrorMessage()));
            } else {
                $status = ScheduledRentPayment::STATUS_SUCCESS;
            }

            $payment->setStatus($status);

            if ($payment->isRecurring()) {
                $nextPaymentAt = $payment->getNextPaymentAt();
                $status === ScheduledRentPayment::STATUS_FAILURE ?
                                $nextPaymentAt->modify('+1 day') :
                                $nextPaymentAt->modify('+1 month');
            }

            $this->em->persist($payment);

            if (( ++$i % 20) == 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function getEntityManager() {
        if (!($this->em instanceof \Doctrine\Common\Persistence\ObjectManager)) {
            $container = $this->getContainer();
            $this->em = $container->get('doctrine')->getManagerForClass(ScheduledRentPayment::class);
        }
    }

}
