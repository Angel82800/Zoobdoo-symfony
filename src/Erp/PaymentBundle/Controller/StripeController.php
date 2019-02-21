<?php

namespace Erp\PaymentBundle\Controller;

use Erp\CoreBundle\Controller\BaseController;
use Erp\PaymentBundle\Entity\StripeAccount;
use Erp\PaymentBundle\Entity\StripeDepositAccount;
use Erp\PaymentBundle\Entity\StripeCustomer;
use Erp\PropertyBundle\Entity\Property;
use Erp\PaymentBundle\Form\Type\StripeCreditCardType;
use Erp\PaymentBundle\Plaid\Exception\ServiceException;
use Erp\PaymentBundle\Stripe\Model\CreditCard;
use Erp\UserBundle\Entity\User;
use Erp\StripeBundle\Form\Type\AccountVerificationType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Erp\PropertyBundle\Entity\PropertySecurityDeposit;
use Stripe\Account;
use Stripe\Customer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StripeController extends BaseController {

    /**
     * 
     * @param Request $request
     * @return type
     */
    public function saveCreditCardAction(Request $request) {
        $model = new CreditCard();
        $form = $this->createForm(new StripeCreditCardType(), $model);
        $form->handleRequest($request);
        /** @var $user User */
        $user = $this->getUser();

        $template = 'ErpPaymentBundle:Stripe/Forms:cc.html.twig';
        $templateParams = [
            'user' => $user,
            'form' => $form->createView(),
            'errors' => null,
            'customer' => $user->getStripeCustomer(),
        ];

        if ($form->isValid()) {
            $manager = $user->getTenantProperty()->getUser();
            $managerStripeAccountId = $manager->getStripeAccount()->getAccountId();

            $stripeToken = $model->getToken();
            $options = ['stripe_account' => $managerStripeAccountId];

            $stripeCustomer = $user->getStripeCustomer();
            $customerManager = $this->get('erp.payment.stripe.manager.customer_manager');

            if (!$stripeCustomer) {
                $params = array(
                    'email' => $user->getEmail(),
                    'source' => $stripeToken,
                );
                $response = $customerManager->create($params, $options);

                if (!$response->isSuccess()) {
                    $templateParams['errors'] = $response->getErrorMessage();
                    return $this->render($template, $templateParams);
                }
                /** @var Customer $customer */
                $customer = $response->getContent();

                $stripeCustomer = new StripeCustomer();
                $stripeCustomer
                        ->setCustomerId($customer['id'])
                        ->setUser($user)
                ;

                $this->em->persist($stripeCustomer);
                $this->em->flush();
            } else {
                $response = $customerManager->retrieve($stripeCustomer->getCustomerId(), $options);

                if (!$response->isSuccess()) {
                    $templateParams['errors'] = $response->getErrorMessage();
                    return $this->render($template, $templateParams);
                }

                /** @var Customer $customer */
                $customer = $response->getContent();
                $response = $customerManager->update($customer, array('source' => $stripeToken), $options);

                if (!$response->isSuccess()) {
                    $templateParams['errors'] = $response->getErrorMessage();
                    return $this->render($template, $templateParams);
                }
            }

            return $this->redirectToRoute('erp_user_profile_dashboard');
        }

        return $this->render($template, $templateParams);
    }

    /**
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyBankAccountAction(Request $request) {
        $publicToken = $request->get('publicToken');
        $accountId = $request->get('accountId');

        try {
            $stripeBankAccountToken = $this->createBankAccountToken($publicToken, $accountId);
        } catch (ServiceException $ex) {
            $this->addFlash('alert_error', $ex->getMessage());

            return $this->redirect($this->generateUrl('erp_user_dashboard_dashboard'));
        }

        /** @var $user User */
        $user = $this->getUser();
        
        // identify the eventual Stripe Account to send to the Stripe API
        $options = null;
        if ($user->hasRole(User::ROLE_TENANT)) {
            if ($user->getTenantProperty()) {
                $options = array(
                    'stripe_account' => $user->getTenantProperty()->getUser()->getStripeAccount()->getAccountId()
                );
            } else {
                return new JsonResponse('This tenant user has no properties.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }
        
        // build the suitable variables to subsequently run the APIs
        $url = $this->generateUrl('erp_user_profile_dashboard');
        $apiManager = $this->get('erp_stripe.entity.api_manager');
        $stripeCustomer = $user->getStripeCustomer();
        
        // manage Stripe Customer
        if (!$stripeCustomer) { // create a new Stripe Customer if doesn't exist
            $arguments = array(
                'params' => array(
                    'email' => $user->getEmail(),
                    'source' => $stripeBankAccountToken,
                ),
                'options' => $options,
            );
            $response = $apiManager->callStripeApi('\Stripe\Customer', 'create', $arguments);
            if (!$response->isSuccess()) {
                return new JsonResponse($response->getErrorMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->flushStripeCustomer($response, $user);
        } else { // update the existing Strope Customer
            $arguments = array(
                'id' => $stripeCustomer->getCustomerId(),
                'params' => array('source' => $stripeBankAccountToken),
                'options' => $options,
            );
            $response = $apiManager->callStripeApi('\Stripe\Customer', 'update', $arguments);
            if (!$response->isSuccess()) {
                return $this->returnRedirectResponseOnError($response);
            }
        }
        
        // manage Stripe Account, if the user is a manaager
        if ($user->hasRole(User::ROLE_MANAGER)) {
            $url = $this->generateUrl('erp_property_unit_buy');

            $stripeAccount = $user->getStripeAccount();
            if (!$stripeAccount->getAccountId()) { // create a new Stripe Account
                $params = array_merge($stripeAccount->toStripe(), array(
                    'country' => StripeAccount::DEFAULT_ACCOUNT_COUNTRY,
                    'type' => StripeAccount::DEFAULT_ACCOUNT_TYPE,
                    'external_account' => $stripeBankAccountToken,
                ));
                $arguments = array(
                    'params' => $params,
                    'options' => null,
                );
                $response = $apiManager->callStripeApi('\Stripe\Account', 'create', $arguments);
                if (!$response->isSuccess()) {
                    return $this->returnRedirectResponseOnError($response);
                }
                $this->flushStripeCustomer($response, $user, true);
            } else { // update the Stripe Account as well as the Stripe Customer
                $arguments = array(
                    'id' => $stripeAccount->getAccountId(),
                    'params' => array('external_account' => $stripeBankAccountToken)
                );
                $response = $apiManager->callStripeApi('\Stripe\Account', 'update', $arguments);
                if (!$response->isSuccess()) {
                    return $this->returnRedirectResponseOnError($response);
                }
                $arguments = array(
                    'id' => $stripeCustomer->getCustomerId(),
                    'params' => array('source' => $stripeBankAccountToken),
                    'options' => $options,
                );
                $response = $apiManager->callStripeApi('\Stripe\Customer', 'update', $arguments);
                if (!$response->isSuccess()) {
                    return $this->returnRedirectResponseOnError($response);
                }
            }
        }

        $this->addFlash('alert_ok', 'Bank account has been verified successfully');

        return $this->redirect($url);
    }

    /**
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyBankAccountDepositAction(Request $request) {
        $publicToken = $request->get('publicToken');
        $accountId = $request->get('accountId');
        $propertyId = $request->get('propertyId');
        
        $user = $this->getUser();
        $property = $this->em->getRepository('ErpPropertyBundle:Property')->getPropertyByUser($user, $propertyId);
        
        $propertySecurityDeposit = $property->getSecurityDeposit() ? $property->getSecurityDeposit() : new PropertySecurityDeposit();
        $property->setSecurityDeposit($propertySecurityDeposit);
        $this->em->persist($property);

        try {
            $stripeBankAccountToken = $this->createBankAccountToken($publicToken, $accountId);
        } catch (ServiceException $ex) {
            return new JsonResponse($ex->getMessage(), $ex->getCode());
        }

        $apiManager = $this->get('erp_stripe.entity.api_manager');
        /** @var $user User */
        $stripeCustomer = $user->getStripeCustomer();

        // build the suitable variables to subsequently run the APIs
        $options = null;
        
        // manage the Stripe Customer
        if (!$stripeCustomer) { // create a new Stripe Customer if it doesn't exist
            $arguments = array(
                'params' => array(
                    'email' => $user->getEmail(),
                    'source' => $stripeBankAccountToken,
                ),
                'options' => $options,
            );
            $response = $apiManager->callStripeApi('\Stripe\Customer', 'create', $arguments);
            if (!$response->isSuccess()) {
                return new JsonResponse($response->getErrorMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->flushStripePropertyCustomer($response, $user, $property);
        } else { // update the existing Stripe Customer
            $arguments = array(
                'id' => $stripeCustomer->getCustomerId(),
                'params' => array('source' => $stripeBankAccountToken),
                'options' => $options
            );
            $response = $apiManager->callStripeApi('\Stripe\Customer', 'update', $arguments);
            if (!$response->isSuccess()) {
                return new JsonResponse($response->getErrorMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }
        $customer = $response->getContent();
        $account = $apiManager->callStripeApi(
                        '\Stripe\Account', array('id' => $property->getUser()->getStripeAccount()->getAccountId(), 'options' => null)
                )
                ->getContent()
        ;
        
        // manage the Stripe Account for the deposit
        if ($user->hasRole(User::ROLE_MANAGER)) { // handle the Stripe features for managers
            $stripeAccount = $property->getDepositAccount();
            if (!$stripeAccount) {
                $stripeAccount = new StripeDepositAccount();
                $stripeAccount->setTosAcceptanceDate(new \DateTime())->setTosAcceptanceIp($request->getClientIp());
                // $stripeAccount->setUser($user);
                $property->setDepositAccount($stripeAccount);
                $this->em->persist($property);
            }
            
            if (!$stripeAccount->getAccountId()) { // create a new Stripe Account for Deposit
                $params = array_merge($stripeAccount->toStripe(), array(
                    'country' => StripeDepositAccount::DEFAULT_ACCOUNT_COUNTRY,
                    'type' => StripeDepositAccount::DEFAULT_ACCOUNT_TYPE,
                    'external_account' => $stripeBankAccountToken,
                ));
                $arguments = array(
                    'params' => $params,
                    'options' => null,
                );
                $response = $apiManager->callStripeApi('\Stripe\Account', 'create', $arguments);
                if (!$response->isSuccess()) {
                    return new JsonResponse($response->getErrorMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
                }
                $this->flushStripePropertyCustomer($response, $user, $property, true);
            } else { // update existing Stripe Account
                $arguments = array(
                    'id' => $stripeAccount->getAccountId(),
                    'params' => array('external_account' => $stripeBankAccountToken),
                );
                $response = $apiManager->callStripeApi('\Stripe\Account', 'update', $arguments);
                if (!$response->isSuccess()) {
                    return new JsonResponse($response->getErrorMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
            $account = $response->getContent();
        }
        
        // now verify the bank account
        if (!$stripeAccount->getBankAccountId()) {
            $bankAccount = $account->external_accounts->create(array('external_account' => $stripeBankAccountToken));
            
            $stripeAccount
                    ->setBankAccountId($bankAccount['id'])
                    ->setBankName($bankAccount['bank_name'])
                    ->setAccountHolderName($bankAccount['account_holder_name'])
                    ->setRoutingNumber($bankAccount['routing_number'])
            ;
            
            $this->em->persist($stripeAccount);
            $this->em->flush();
        } else {
            $arguments = array(
                'id' => $stripeAccount->getBankAccountId(),
                'customer' => $customer
            );
            $bankAccount = $account->external_accounts->retrieve($stripeAccount->getBankAccountId());
        }

        return new JsonResponse(array(
            'response' => $response,
            'bankAccount' => $bankAccount,
        ));
    }

    /**
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyAccountAction(Request $request) {
        //TODO Need to verify account if I change BA?
        /** @var User $user */
        $user = $this->getUser();
        $stripeAccount = $user->getStripeAccount();

        $form = $this->createForm(new AccountVerificationType(), $stripeAccount, ['validation_groups' => 'US']);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $stripeAccount->setTosAcceptanceDate(new \DateTime())
                    ->setTosAcceptanceIp($request->getClientIp());

            $apiManager = $this->get('erp_stripe.entity.api_manager');
            $arguments = [
                'id' => $stripeAccount->getAccountId(),
                'params' => $stripeAccount->toStripe(),
                'options' => null,
            ];
            $response = $apiManager->callStripeApi('\Stripe\Account', 'update', $arguments);

            if (!$response->isSuccess()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $response->getErrorMessage(),
                ]);
            }

            /** @var Account $content */
            $content = $response->getContent();
            if ($fieldsNeeded = $content->verification->fields_needed) {
                //TODO Handle Stripe required verification fields
                return new JsonResponse(array(
                    'success' => false,
                    'fields_needed' => $fieldsNeeded,
                ));
            }

            $this->em->persist($stripeAccount);
            $this->em->flush();

            if ($user->hasRole(User::ROLE_MANAGER)) {
                $url = $this->generateUrl('erp_property_unit_buy');
            } else {
                $url = $this->generateUrl('erp_user_profile_dashboard');
            }

            return new JsonResponse(array(
                'redirect' => $url,
            ));
        }
        //TODO Prepare backend errors for frontend
        return $this->render('ErpStripeBundle:Widget:verification_ba.html.twig', [
                    'form' => $form->createView(),
                    'modalTitle' => 'Continue verification',
        ]);
    }

    /**
     * 
     * @param type $publicToken
     * @param type $accountId
     * @return type
     * @throws ServiceException
     */
    private function createBankAccountToken($publicToken, $accountId) {
        /** @var Erp\PaymentBundle\Plaid\Service\Item $itemPlaidService */
        $itemPlaidService = $this->get('erp.payment.plaid.service.item');
        
        /** @var Erp\PaymentBundle\Plaid\Service\Processor $processorPlaidService */
        $processorPlaidService = $this->get('erp.payment.plaid.service.processor');

        $response = $itemPlaidService->exchangePublicToken($publicToken);
        $result = json_decode($response['body'], true);

        if (($response['code'] < 200) || ($response['code'] >= 300)) {
            throw new ServiceException($result['display_message']);
        }

        $response = $processorPlaidService->createBankAccountToken($result['access_token'], $accountId);
        $result = json_decode($response['body'], true);

        if (($response['code'] < 200) || ($response['code'] >= 300)) {
            throw new ServiceException($result['display_message']);
        }

        return $result['stripe_bank_account_token'];
    }
    
    /**
     * 
     * @param mixed $response
     * @param User $user
     * @param boolean $forManagers (default false)
     */
    private function flushStripeCustomer($response, User $user, $forManagers = false) {
        if ($forManagers) {
            /** @var Account $account */
            $account = $response->getContent();

            $object = $user->getStripeAccount();
            $object->setAccountId($account['id']);
        } else {
            /** @var Customer $customer */
            $customer = $response->getContent();

            $object = new StripeCustomer();
            $object
                    ->setCustomerId($customer['id'])
                    ->setUser($user)
            ;
        }
        
        $this->em->persist($object);
        $this->em->flush();
    }
    
    /**
     * 
     * @param mixed $response
     * @param User $user
     * @param Property $property
     * @param boolean $forManagers (default false)
     */
    private function flushStripePropertyCustomer($response, User $user, Property $property, $forManagers = false) {
        if ($forManagers) {
            /** @var Account $account */
            $account = $response->getContent();
            
            $object = $property->getDepositAccount();
            $object->setAccountId($account['id']);
        } else {
            /** @var Customer $customer */
            $customer = $response->getContent();

            $object = new StripeCustomer();
            $object
                    ->setCustomerId($customer['id'])
                    ->setUser($user)
            ;
        }

        $this->em->persist($object);
        $this->em->flush();
    }
    
    /**
     * 
     * @param mixed $response
     * @return RedirectResponse
     */
    private function returnRedirectResponseOnError($response) {
        $this->addFlash('alert_error', $response->getErrorMessage());
        return $this->redirect($this->generateUrl('erp_user_dashboard_dashboard'));
    }

}
