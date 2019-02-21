<?php

namespace Erp\PropertyBundle\Controller;

use Erp\PaymentBundle\Entity\StripeCustomer;
use Erp\PaymentBundle\Entity\StripeAccount;
use Erp\PropertyBundle\Entity\ScheduledRentPayment;
use Erp\PropertyBundle\Entity\PropertySecurityDeposit;
use Erp\PropertyBundle\Form\Type\StopAutoWithdrawalFormType;
use Erp\UserBundle\Entity\User;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Erp\StripeBundle\Helper\ApiHelper;
use Erp\PropertyBundle\Form\Type\ScheduledRentPaymentType;

class ScheduledRentPaymentController extends Controller {

    public function payRentAction(Request $request) {
        /** @var User $user */
        $user = $this->getUser();

        $property = $user->getTenantProperty();

        $twigTemplate = 'ErpPaymentBundle:Stripe\Widgets:rental-payment.html.twig';
        if ($property) {
            $manager = $property->getUser();
            $securityDeposit = $property->getSecurityDeposit();

            $managerStripeAccount = ($manager) ? $manager->getStripeAccount() : null;
            $tenantStripeCustomer = $user->getStripeCustomer();

            $entity = new ScheduledRentPayment();
            $entity->setProperty($property);
            $entity->setUser($user);
            $entity->setStartPaymentAt(new \Datetime());

            $form = $this->createForm(ScheduledRentPaymentType::class, $entity);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {

                if (!$managerStripeAccount || !$tenantStripeCustomer) {
                    $this->addFlash(
                            'alert_error', 'Please, add your payment info or contact your manager.'
                    );

                    return $this->redirectToRoute('erp_user_profile_dashboard');
                }

                if ($form->isValid()) {

                    $startPaymentAt = $entity->getStartPaymentAt();

                    if ($entity->getCategory() == 'deposit_payment') {

                        if (!$securityDeposit->getSendToMainAccount()) {

                            $repository = $this->getDoctrine()->getRepository(StripeAccount::class);

                            $securityDepositAccount = $property->getDepositAccount();
                            if (!$managerStripeAccount) {
                                $managerStripeAccount = $securityDepositAccount;
                            }
                        }
                        $arguments = [
                            'params' => [
                                //TODO Refactoring amount in payRentAction form
                                'amount' => ApiHelper::convertAmountToStripeFormat($entity->getAmount()),
                                'currency' => StripeCustomer::DEFAULT_CURRENCY,
                                'customer' => $tenantStripeCustomer->getCustomerId(),
                                'metadata' => [
                                    'account' => $managerStripeAccount->getAccountId(),
                                    'internalType' => 'rent_payment'
                                ],
                            ],
                            'options' => [
                                'stripe_account' => $managerStripeAccount->getAccountId()
                            ]
                        ];
                        $apiManager = $this->get('erp_stripe.entity.api_manager');

                        $response = $apiManager->callStripeApi('\Stripe\Charge', 'create', $arguments);

                        if (!$response->isSuccess()) {
                            $status = PropertySecurityDeposit::STATUS_DEPOSIT_UNPAID;
                            $logger->critical(json_encode($response->getErrorMessage()));
                        } else {
                            $status = PropertySecurityDeposit::STATUS_DEPOSIT_PAID;
                        }

                        $securityDeposit->setStatus($status);
                        $em = $this->getDoctrine()->getManagerForClass(PropertySecurityDeposit::class);
                        $em->persist($securityDeposit);
                        $em->flush();
                    } else {
                        $entity
                                ->setNextPaymentAt($startPaymentAt)
                                ->setAccount($managerStripeAccount)
                                ->setCustomer($tenantStripeCustomer)
                                ->setType('single');
                    }

                    $em = $this->getDoctrine()->getManagerForClass(ScheduledRentPayment::class);
                    $em->persist($entity);
                    $em->flush();


                    $this->addFlash(
                            'alert_ok', 'Success'
                    );

                    return $this->redirectToRoute('erp_user_profile_dashboard');
                }

                $this->addFlash(
                        'alert_error', $form->getErrors(true)[0]->getMessage()
                );

                return $this->redirectToRoute('erp_user_profile_dashboard');
            }

            return $this->render($twigTemplate, array(
                        'form' => $form->createView(),
                        'user' => $user,
                        'manager' => $manager,
            ));
        } else {
            return $this->render($twigTemplate, array(
                        'exception' => $this->createNotFoundException('There is a misalignment within the database: no property is available.')
            ));
        }
    }

    public function stopAutoWithdrawalAction(User $user, Request $request) {
        $stripeCustomer = $user->getStripeCustomer();

        if (!$stripeCustomer) {
            throw $this->createNotFoundException();
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->hasTenant($user)) {
            throw $this->createAccessDeniedException();
        }

        $entity = new ScheduledRentPayment();
        $form = $this->createForm(new StopAutoWithdrawalFormType(), $entity, ['validation_groups' => 'StopAuthWithdrawal']);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $endAt = $entity->getEndAt();
            $scheduledRentPayments = $stripeCustomer->getScheduledRentPayments();
            /** @var ScheduledRentPayment $scheduledRentPayment */
            foreach ($scheduledRentPayments as $scheduledRentPayment) {
                $scheduledRentPayment->setEndAt($endAt);
            }

            $em = $this->getDoctrine()->getManagerForClass(StripeCustomer::class);
            $em->persist($stripeCustomer);
            $em->flush();

            $this->addFlash(
                    'alert_ok', 'Success'
            );
        }

        return $this->redirect($request->headers->get('referer'));
    }

}
