<?php

namespace Erp\UserBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Erp\CoreBundle\Controller\BaseController;
use Erp\PaymentBundle\Entity\StripeCustomer;
use Erp\PaymentBundle\Entity\StripeSubscription;
use Erp\StripeBundle\Entity\BalanceHistory;
use Erp\StripeBundle\Entity\Transaction;
use Stripe\Subscription;
use Erp\UserBundle\Entity\Charge;
use Erp\UserBundle\Entity\User;
use Erp\UserBundle\Form\Type\ChargeFormType;
use Erp\UserBundle\Form\Type\LandlordFormType;
use Erp\UserBundle\Form\Type\LandlordPayFormType;
use Erp\StripeBundle\Entity\PaymentTypeInterface;
use Erp\StripeBundle\Helper\ApiHelper;
use Erp\StripeBundle\Entity\ApiManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

class LandlordController extends BaseController {

    /**
     * @Security("is_granted('ROLE_MANAGER')")
     */
    public function indexAction(Request $request) {
        //manager charge landlord Step 1 (select) in twig
        /** @var $user \Erp\UserBundle\Entity\User */
        $user = $this->getUser();
        // $items = $this->getDoctrine()->getManagerForClass(User::class)->getRepository(User::class)->findBy(['manager' => $user]);
        $landlords = $user->getLandlords();
        $tenants = $user->getTenants();

        return $this->render('ErpUserBundle:Landlords:index.html.twig', [
                    'user' => $user,
                    'landlords' => $landlords,
                    'tenants' => $tenants,
                    'modalTitle' => 'Charge clients'
        ]);
    }

    /**
     * @Security("is_granted('ROLE_MANAGER')")
     */
    public function landlordListAction(Request $request) {
        //TODO: fetch all landlords
        //manager charge landlord Step 1 (select) in twig
        /** @var $user \Erp\UserBundle\Entity\User */
        $user = $this->getUser();
        $items = $this->em->getRepository(User::class)->findBy(array('manager' => $user));
        $stripeUserManager = $this->get('erp_stripe.stripe.entity.user_manager');

        return $this->render('ErpUserBundle:Landlords:pay_landlord.html.twig', [
                    'user' => $user,
                    'items' => $items,
                    'stripeUserManager' => $stripeUserManager,
                    'modalTitle' => 'Pay to landlords'
        ]);
    }

    /**
     * @Security("is_granted('ROLE_MANAGER')")
     */
    public function payLandlordAction(Request $request) {
        //TODO: Add bank account landlords

        /** @var $user \Erp\UserBundle\Entity\User */
        $user = $this->getUser();
        $landlordId = $request->get('landlordId');
        $landlord = $this->em->getRepository('ErpUserBundle:User')->findOneBy(['id' => $landlordId]);

        if ($landlord instanceof User) {
            //Second step

            /** @var $user \Erp\UserBundle\Entity\User */
            $charge = new Charge();
            $form = $this->createForm(new LandlordPayFormType(), $charge);
            $form->handleRequest($request);

            /** @var $manager \Erp\UserBundle\Entity\User */
            $manager = $landlord->getManager();

            if ($manager->getId() == $user->getId() && $form->isValid()) {
                //Third (Final) step

                $landlordStripeAccountInstance = $landlord->getStripeAccount();
                $managerStripeCustomer = $manager->getStripeCustomer();

                //TODO Add cache layer (APC or Doctrine)
                $stripeUserManager = $this->get('erp_stripe.stripe.entity.user_manager');
                /** @var BankAccount $bankAccount */
                /** get manager bank account details in website */
                $managerBankAccount = $stripeUserManager->getBankAccount($user);
                $managerBankAccountId = $managerBankAccount->id;

                /** get manager stripe customer id in website */
                $stripeCustomerInfo = $stripeUserManager->getCustomerInfo($user);
                $managerCustomerId = $stripeCustomerInfo['id'];

                /** get landlord & manager other info in website */
                /* $manaerEmail = $user->getEmail();
                  $landlordEmail = $landlord->getEmail(); */

                $landlordStripeAccount = $landlord->hasStripeAccount();

                if ($landlordStripeAccount == '1') {
                    $rawLandlordStripeAccount = $stripeUserManager->getStripeAccountInfo($landlord);
                    $landlordStripeAccount = $rawLandlordStripeAccount['id'];

                    /** get previous transaction balance for landlord */
                    $rawStripeId = $landlord->getStripeAccount();
                    $stId = $rawStripeId->getId();
                    $GetTransaction = $this->em->getRepository('ErpStripeBundle:Transaction')->getLastTransactionData($stId);
                    $oldBalance = $GetTransaction['balance'];
                }

                /** if manager didn't connect own bank account in website */
                /* echo "<pre>";
                  print_r($_POST);
                  print_r($stripeCustomerInfo);
                  print_r($managerBankAccount);
                  print_r($landlordStripeAccount); */

                if (!$managerCustomerId or ! $managerBankAccountId) {
                    $erMsg = 'Manager can not transfer payments. Because could not verify own bank account';
                    return $this->render('ErpUserBundle:Landlords:transferFailed.html.twig', [
                                'charge' => $charge,
                                'modalTitle' => $erMsg,
                                'user' => $user,
                                'landlord' => $landlord
                    ]);
                } else {

                    /** if landlord didn't have stripe connect account id in website */
                    if (!$landlordStripeAccount) {
                        $erMsg = 'Landlord can not accept payments. Because could not find stripe account id';
                        return $this->render('ErpUserBundle:Landlords:transferFailed.html.twig', [
                                    'charge' => $charge,
                                    'modalTitle' => $erMsg,
                                    'user' => $user,
                                    'landlord' => $landlord
                        ]);
                    } else {

                        $chkstripe = $landlordStripeAccount;

                        /* echo "step 3 in progress <br/>";
                          print_r($landlordStripeAccount); */

                        /** Charge data set */

                        $charge->setManager($user);
                        $charge->setLandlord($landlord);
                        $this->em->persist($charge);
                        $token = $charge->getId();
                        $this->em->flush();

                        $charge = $this->em->getRepository(Charge::class)->find($token);

                        $stripeApiManager = $this->get('erp_stripe.entity.api_manager');

                        /** get transfer payment details from form fill data */
                        $rawData = $request->get("erp_user_landlords_pay_landlord");
                        $amount = $rawData['amount'];
                        $description = $request->get('description');

                        /** Arguments to create charge as landlord destination */
                        $arguments = [
                            'params' => [
                                'amount' => ApiHelper::convertAmountToStripeFormat($amount),
                                'customer' => $managerCustomerId,
                                'currency' => StripeCustomer::DEFAULT_CURRENCY,
                                'source' => $managerBankAccountId,
                                'capture' => 'true',
                                'metadata' => [
                                    'account' => $landlordStripeAccount,
                                    'internalType' => Transaction::INTERNAL_TYPE_PAY_LANDLORD,
                                    'description' => $description
                                ],
                                'destination' => array(
                                    'amount' => ApiHelper::convertAmountToStripeFormat($amount),
                                    'account' => $landlordStripeAccount,
                                ),
                            ],
                            'options' => [
                                'destination' => $landlordStripeAccount
                            ]
                        ];

                        /** Get stripe response for landlord payments */
                        $chargeResponse = $stripeApiManager->callStripeApi('\Stripe\Charge', 'create', $arguments);

                        if (!$chargeResponse->isSuccess()) {
                            $erMsg = 'Transfer Failed due ' . $chargeResponse->getErrorMessage();
                            return $this->render('ErpUserBundle:Landlords:transferFailed.html.twig', [
                                        'charge' => $charge,
                                        'modalTitle' => $erMsg,
                                        'user' => $user,
                                        'landlord' => $landlord
                            ]);
                        }

                        $charge->setStatus(Charge::STATUS_PAID);

                        $this->em->persist($charge);
                        $this->em->flush();

                        /** Set stripe response for stripe transaction and balance history */
                        $rawcl = $chargeResponse->getContent();
                        $datTimeMail = $rawcl->created;
                        $txnId = $rawcl->transfer;

                        $stAmount = $rawcl->amount;
                        $stBalance = $oldBalance + $stAmount;
                        $stMeta = json_encode($rawcl->metadata);
                        $stObject = $rawcl->object;

                        /** Create balance history object */
                        $balance = new BalanceHistory();

                        /** Create Date Time object */
                        /** Create stripe transaction object and set data for store */
                        $rawDateTime = new \DateTime();
                        $transaction = new Transaction();
                        $transaction
                                ->setType($stObject)
                                ->setAmount($stAmount)
                                ->setBalance($stBalance)
                                ->setBalanceHistory($balance)
                                ->setCurrency('usd')
                                ->setPaymentMethod('bank')
                                ->setPaymentMethodDescription($description)
                                ->setMetadata($stMeta)
                                ->setStatus(\Erp\StripeBundle\Event\ChargeEvent::SUCCEEDED)
                                ->setInternalType(Transaction::INTERNAL_TYPE_PAY_LANDLORD)
                                ->setCreated($rawDateTime)
                                ->setCustomer($managerStripeCustomer)
                                ->setAccount($landlordStripeAccountInstance)
                                ->setStripeId($rawcl->id)
                        ;

                        $this->em->persist($transaction);
                        $this->em->flush();

                        /** Set balance history data and store  */
                        $balance->setBalance($stBalance)
                                ->setAmount($stAmount)
                                ->setTransaction($transaction)
                        ;
                        $this->em->persist($balance);
                        $this->em->flush();

                        $charge->datTimeMail = date('Y-m-d H:i:s', $datTimeMail);
                        $charge->txnId = $txnId;

                        $from = $this->container->getParameter('contact_email');
                        $this->get('erp_user.mailer.processor')->sendTransferEmail($charge, $from);

                        $this->addFlash('alert_success', 'transfer successfully');
                        return $this->render('ErpUserBundle:Landlords:transferSent.html.twig', [
                                    'charge' => $charge,
                                    'modalTitle' => 'Transfer Successfully',
                                    'user' => $user,
                                    'landlord' => $landlord
                        ]);
                    }
                }
            }

            return $this->render('ErpUserBundle:Landlords:pay_landlord_step_2.html.twig', [
                        'user' => $user,
                        'landlord' => $landlord,
                        'form' => $form->createView(),
                        'modalTitle' => 'Pay to landlords'
            ]);
        } else {
            //back to landlords list to select
            $this->addFlash('alert_error', 'Choose any landlord');
            return $this->forward('ErpUserBundle:Landlord:LandlordList');
        }
    }

    /**
     * @Security("is_granted('ROLE_MANAGER')")
     */
    public function chargeAction(Request $request) {
        /** @var $user \Erp\UserBundle\Entity\User */
        $user = $this->getUser();
        $receiverIds = $request->get('receiverId');
        $receivers = $this->em->getRepository('ErpUserBundle:User')->findByArrayOfIds($receiverIds);

        if (count($receivers) > 0) {
            $emailsTo = array('success' => array(), 'error' => array());

            for ($i = 0; $i < count($receivers); $i++) {
                $ok = false;
                $receiver = $receivers[$i];

                /** @var $manager \Erp\UserBundle\Entity\User */
                $manager = $receiver->getRealManager();

                if ($manager->getId() == $user->getId()) {
                    //Second step

                    /** @var $user \Erp\UserBundle\Entity\User */
                    $charge = new Charge();
                    $form = $this->createForm(new ChargeFormType(), $charge);
                    $form->handleRequest($request);

                    if ($form->isValid()) {
                        //Third (Final) step

                        $charge->setManager($user);
                        $charge->setReceiver($receiver);

                        $this->em->persist($charge);
                        $this->em->flush();

                        if ($this->get('erp_user.mailer.processor')->sendChargeEmail($charge)) {
                            $charge->setStatus(Charge::STATUS_SENT);
                            $this->em->flush();

                            $ok = true;
                        }
                    }
                }

                if ($ok) {
                    $emailsTo['success'][] = $receiver->getEmail();
                } else {
                    $emailsTo['error'][] = $receiver->getEmail();
                }
            }

            if (count($emailsTo['success']) > 0) {
                return $this->render('ErpUserBundle:Landlords:chargeSent.html.twig', array(
                            'charge' => $charge,
                            'modalTitle' => 'Report',
                            'user' => $user,
                            'emailsTo' => $emailsTo
                ));
            }
            return $this->render('ErpUserBundle:Landlords:charge.html.twig', array(
                        'charge' => $charge,
                        'user' => $user,
                        'emailsTo' => $emailsTo['error'],
                        'receivers' => $receivers,
                        'form' => $form->createView()
            ));
        } else {
            //back to landlords/tenants list to select
            $this->addFlash('alert_error', 'Choose any landlord or tenant to charge');
            return $this->forward('ErpUserBundle:Landlord:index');
        }
    }

    /**
     * @param string $token
     * @return Response
     * @throws NotFoundHttpException
     */
    public function chooseChargeTypeAction($token) {
        /** @var Charge $charge */
        $charge = $this->em->getRepository(Charge::class)->find($token);

        if ($charge) {
            $template = 'ErpUserBundle:Landlords:choose_charge_type.html.twig';

            $params = array(
                'token' => $token,
                'charge' => $charge,
            );

            return $this->render($template, $params);
        } else {
            throw $this->createNotFoundException('Token ' . $token . ' not found');
        }
    }

    /**
     * @param Request $request
     * @param $type
     * @param $token
     * @return \Symfony\Component\HttpFoundation\Response|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function confirmChargeAction(Request $request, $type, $token) {
        /** @var Charge $charge */
        $charge = $this->em->getRepository(Charge::class)->find($token);
        /** @var PaymentTypeInterface $model */
        $model = $this->get('erp_stripe.registry.model_registry')->getModel($type);
        $form = $this->get('erp_stripe.registry.form_registry')->getForm($type);
        $form->setData($model);
        $form->handleRequest($request);

        $jsErrorMessage = $request->request->get('erp_stripe_credit_card')['js_error_message']; //message come from Stripe JS API

        $template = sprintf('ErpUserBundle:Landlords/Forms:%s.html.twig', $type);
        $params = array(
            'token' => $token,
            'form' => $form->createView(),
            'charge' => $charge
        );

        if ($jsErrorMessage) {
            $this->addFlash('alert_error', $jsErrorMessage);
            $this->setChargeAsPending($charge);
            return $this->render($template, $params);
        }

        if ($form->isValid() && $charge->isPaid()) {
            $this->addFlash('alert_error', 'Already paid.');
        }

        if ($form->isValid()) {
            /** @var User $manager */
            $manager = $charge->getManager();
            $managerStripeAccount = $manager->getStripeAccount();

            if (!$managerStripeAccount) {
                $this->addFlash('alert_error', 'Manager can not accept payments.');
            }

            $stripeApiManager = $this->get('erp_stripe.entity.api_manager');

            $sourceToken = $model->getSourceToken();
            $stripeAccountId = $managerStripeAccount->getAccountId();

            $receiverStripeCustomer = $charge->getReceiver()->getStripeCustomer();
            if (!$receiverStripeCustomer) { //TODO: refactor it into private method for checking StripeCustomer exists
                if (($result = $this->createStripeCustomer($charge, $sourceToken, $stripeAccountId, $stripeApiManager, $template, $params)) instanceof Response) {
                    return $result;
                }
            }

            if ($charge->isRecurring()) {
                //TODO: add possibility for many subscriptions

                if (!$receiverStripeCustomer->getStripeSubscription()) {
                    $arguments = [
                        'id' => StripeSubscription::MONTHLY_PLAN_ID,
                        'options' => [
                            'stripe_account' => $stripeAccountId,
                        ]
                    ];
                    $response = $stripeApiManager->callStripeApi('\Stripe\Plan', 'retrieve', $arguments);

                    if (!$response->isSuccess()) {
                        if (($result = $this->createStripePlan($charge, $stripeApiManager, $stripeAccountId, $template, $params)) instanceof Response) {
                            return $result;
                        }
                    }

                    if (($result = $this->createStripeSubscription($charge, $stripeApiManager, $stripeAccountId, $template, $params)) instanceof Response) {
                        return $result;
                    }
                } else {
                    if (($result = $this->updateStripeSubscription($charge, $stripeApiManager, $template, $params)) instanceof Response) {
                        return $result;
                    }
                }
            } else {
                $response = $this->chargeByStripe($charge, $stripeApiManager, $stripeAccountId);
            }
            if (!$response->isSuccess()) {
                $this->addFlash('alert_error', $response->getErrorMessage());
                return $this->render($template, $params);
            }

            $charge->setStatus(Charge::STATUS_PAID);

            $this->em->persist($charge);
            $this->em->flush();

            $template = 'ErpUserBundle:Landlords:chargeComplete.html.twig';
            $params = array('charge' => $charge);
        }

        return $this->render($template, $params);
    }

    /**
     * @param Charge $charge
     * @param mixed $sourceToken
     * @param string $stripeAccountId
     * @param ApiManager $stripeApiManager
     * @param string $template
     * @param array $params
     * @return mixed
     */
    private function createStripeCustomer(Charge $charge, $sourceToken, $stripeAccountId, ApiManager $stripeApiManager, $template, $params) {
        $payer = $charge->getReceiver();

        $response = $stripeApiManager->callStripeApi('\Stripe\Customer', 'create', array(
            'params' => array(
                'email' => $payer->getEmail(),
                'source' => $sourceToken,
            ),
            'options' => array('stripe_account' => $stripeAccountId)
        ));

        if (!$response->isSuccess()) {
            $this->setChargeAsPending($charge);

            $this->addFlash(
                    'alert_error', $response->getErrorMessage()
            );
            return $this->render($template, $params);
        }

        /** @var \Stripe\Customer $externalStripeCustomer */
        $externalStripeCustomer = $response->getContent();

        $receiverStripeCustomer = new StripeCustomer();
        $receiverStripeCustomer->setCustomerId($externalStripeCustomer->id)->setUser($payer);

        $payer->setStripeCustomer($receiverStripeCustomer);

        $this->em->persist($payer);
        $this->em->flush();

        return true;
    }

    /**
     * @param Charge $charge
     * @param ApiManager $stripeApiManager
     * @param string $stripeAccountId
     * @param string $template
     * @param array $params
     * @return mixed
     */
    private function createStripePlan(Charge $charge, ApiManager $stripeApiManager, $stripeAccountId, $template, $params) {
        $arguments = [
            'params' => [
                'amount' => 1,
                'interval' => 'month',
                "currency" => 'usd',
                'name' => StripeSubscription::MONTHLY_PLAN_ID,
                'id' => StripeSubscription::MONTHLY_PLAN_ID,
            ],
            'options' => [
                'stripe_account' => $stripeAccountId,
            ]
        ];
        $response = $stripeApiManager->callStripeApi('\Stripe\Plan', 'create', $arguments);

        if (!$response->isSuccess()) {
            $this->setChargeAsPending($charge);

            $this->addFlash(
                    'alert_error', $response->getErrorMessage()
            );
            return $this->render($template, $params);
        } else {
            return true;
        }
    }

    /**
     * 
     * @param Charge $charge
     * @param ApiManager $stripeApiManager
     * @param string $stripeAccountId
     * @param string $template
     * @param array $params
     * @return mixed
     */
    private function createStripeSubscription(Charge $charge, ApiManager $stripeApiManager, $stripeAccountId, $template, $params) {
        $payer = $charge->getReceiver()->getStripeCustomer();

        $arguments = [
            'params' => [
                'customer' => $payer->getCustomerId(),
                'items' => [
                    [
                        'plan' => StripeSubscription::MONTHLY_PLAN_ID,
                        'quantity' => ApiHelper::convertAmountToStripeFormat($charge->getAmount()),
                    ],
                ],
            ],
            'options' => [
                'stripe_account' => $stripeAccountId,
            ]
        ];
        $response = $stripeApiManager->callStripeApi('\Stripe\Subscription', 'create', $arguments);

        if (!$response->isSuccess()) {
            $this->setChargeAsPending($charge);

            $this->addFlash(
                    'alert_error', $response->getErrorMessage()
            );
            return $this->render($template, $params);
        }

        /** @var Subscription $subscription */
        $subscription = $response->getContent();

        $stripeSubscription = new StripeSubscription();
        $stripeSubscription->setSubscriptionId($subscription['id'])->setStripeCustomer($payer);

        $payer->setStripeSubscription($stripeSubscription);

        $this->em->persist($payer);
        $this->em->flush();

        return true;
    }

    /**
     * 
     * @param Charge $charge
     * @param ApiManager $stripeApiManager
     * @param string $template
     * @param array $params
     * @return mixed
     */
    private function updateStripeSubscription(Charge $charge, ApiManager $stripeApiManager, $template, $params) {
        $payerSubscription = $charge->getReceiver()->getStripeCustomer()->getStripeSubscription();

        //TODO ERP-191
        $arguments = [
            'id' => $payerSubscription->getSubscriptionId(),
            'params' => [
                'quantity' => $charge->getAmount(),
            ],
            'options' => null,
        ];
        $response = $stripeApiManager->callStripeApi('\Stripe\Subscription', 'update', $arguments);

        if (!$response->isSuccess()) {
            $this->setChargeAsPending($charge);

            $this->addFlash(
                    'alert_error', $response->getErrorMessage()
            );
            return $this->render($template, $params);
        } else {
            return true;
        }
    }

    /**
     * @param Charge $charge
     * @param ApiManager $stripeApiManager
     * @param string $stripeAccountId
     * @return type
     */
    private function chargeByStripe(Charge $charge, ApiManager $stripeApiManager, $stripeAccountId) {
        $arguments = [
            'params' => [
                'amount' => ApiHelper::convertAmountToStripeFormat($charge->getAmount()),
                'customer' => $charge->getReceiver()->getStripeCustomer()->getCustomerId(),
                'currency' => StripeCustomer::DEFAULT_CURRENCY,
                'metadata' => [
                    'account' => $stripeAccountId,
                    'internalType' => Transaction::INTERNAL_TYPE_CHARGE,
                    'description' => $charge->getDescription(),
                    'internalChargeId' => $charge->getId()
                ],
            ],
            'options' => [
                'stripe_account' => $stripeAccountId,
            ]
        ];
        return $stripeApiManager->callStripeApi('\Stripe\Charge', 'create', $arguments);
    }

    /**
     * 
     * @param Charge $charge
     */
    private function setChargeAsPending(Charge $charge) {
        $charge->setStatus(Charge::STATUS_PENDING);
        $this->em->persist($charge);
        $this->em->flush();
    }

}
