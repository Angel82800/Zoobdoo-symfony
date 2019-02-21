<?php

namespace Erp\PaymentBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Erp\PaymentBundle\Entity\PaySimpleRecurringPayment;

/**
 * PaySimpleRecurringPaymentRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class PaySimpleRecurringPaymentRepository extends EntityRepository
{
    /**
     * Get users recurring to check
     *
     * @return array
     */
    public function getUserRecurringForCheck()
    {
        $currentDate = (new \DateTime())->setTime(0, 0);
        $qb = $this->_em->createQueryBuilder();
        $qb->select('ps_r')
            ->from($this->_entityName, 'ps_r')
            ->where('ps_r.lastCheckedDate IS NULL AND ps_r.startDate = :currentDate')
            ->orWhere('ps_r.lastCheckedDate != :currentDate AND ps_r.nextDate = :currentDate')
            ->andWhere('ps_r.status = :status')
            ->setParameter('currentDate', $currentDate)
            ->setParameter('status', PaySimpleRecurringPayment::STATUS_ACTIVE);
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}