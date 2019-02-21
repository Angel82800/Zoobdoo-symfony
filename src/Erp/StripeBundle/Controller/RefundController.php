<?php

namespace Erp\StripeBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Erp\CoreBundle\Controller\BaseController;
use Erp\StripeBundle\Entity\Transaction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Erp\StripeBundle\Helper\ApiHelper;
use Erp\PaymentBundle\Entity\StripeCustomer;

class RefundController extends BaseController {

    /**
     * @Security("is_granted('ROLE_MANAGER') or is_granted('ROLE_LANDLORD')")
     */
    public function confirmAction(Request $request, $transactionId) {
        return $this->render('ErpStripeBundle:Refund:confirm.html.twig', array(
                    'modalTitle' => 'Refund Transaction #' . $transactionId,
                    'pathApply' => $this->generateUrl('erp_stripe_transaction_apply', array('transactionId' => $transactionId))
        ));
    }

    /**
     * @Security("is_granted('ROLE_MANAGER') or is_granted('ROLE_LANDLORD')")
     */
    public function applyAction(Request $request, $transactionId) {
        /** @var $user \Erp\UserBundle\Entity\User */
        $user = $this->getUser();

        /** @var $user \Erp\StripeBundle\Entity\Transaction */
        $transaction = $this->em->getRepository(Transaction::REPOSITORY)->find($transactionId);

        $classMessage = 'alert-danger';
        
        if ($transaction) {
            $stripeCustomer = $user->getStripeCustomer();
            if (!$stripeCustomer) {
                $message = 'Your Stripe Account is invalid';
                $code = Response::HTTP_NOT_FOUND;
            } else {
                $stripeAccountId = $stripeCustomer->getAccountId();

                $stripeApiManager = $this->get('erp_stripe.entity.api_manager');
                $arguments = array(
                    'params' => array(
                        'amount' => ApiHelper::convertAmountToStripeFormat($transaction->getAmount()),
                        'charge' => $transaction->getStripeId(),
                        'metadata' => array(
                            'account' => $stripeAccountId,
                            'internalType' => Transaction::INTERNAL_TYPE_REFUND
                        )),
                    'options' => array(
                        'stripe_account' => $stripeAccountId,
                    )
                );

                $response = $stripeApiManager->callStripeApi('\Stripe\Refund', 'create', $arguments);
                
                if ($response->isSuccess()) {
                    $classMessage = 'alert-success';
                    $message = 'You have successfully refunded the transaction';
                    $code = Response::HTTP_OK;
                    
                    $transaction->setRefunded(true);
                    $this->em->persist($transaction);
                    $this->em->flush();
                } else {
                    $message = 'Something went wrong during Stripe connection.
                            Reason: ' . $response->getErrorReasonCode() . '. Message: ' . $response->getErrorMessage() . '.
                            Please, contact us for further details.'
                    ;
                    $code = ($response->getErrorResponseCode() == 0) ? Response::HTTP_INTERNAL_SERVER_ERROR : $response->getErrorResponseCode();
                }
            }
        } else {
            $error = $this->createNotFoundException();

            $message = $error->getMessage();
            $code = $error->getCode();
        }

        $modalFooter = '<table width="100%" cellspacing="0" cellpadding="0">
                        <tr>
                            <td align="left">&nbsp;</td>
                            <td align="right">
                                <button type="button" class="btn btn-default red-btn" data-dismiss="modal">Close</button>
                            </td>
                        </tr>
                    </table>'
        ;

        return $this->render('ErpStripeBundle:Refund:apply.html.twig', array(
                    'modalTitle' => 'Refund Transaction #' . $transactionId,
                    'message' => $message,
                    'class' => $classMessage,
                    'modalFooter' => $modalFooter
        ), new Response($message, $code));
    }

}
