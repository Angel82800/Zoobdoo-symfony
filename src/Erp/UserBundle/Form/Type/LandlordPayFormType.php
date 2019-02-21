<?php

namespace Erp\UserBundle\Form\Type;

use Erp\UserBundle\Entity\Charge;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class LandlordPayFormType extends AbstractType
{
    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array $options
     */
    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            /*->add('currency', 'hidden', ['label' => 'Country', 'required' => true, 'attr' => ['class' => 'form-control','value' => 'usd','placeholder' => 'usd'],
            ])
            ->add('bank_country', 'text', ['label' => 'Country', 'required' => true, 'attr' => ['class' => 'form-control','placeholder' => 'US'],
                'constraints' => [
                    new NotBlank(
                        [
                            'message' => 'Please enter country name',
                            'groups' => ['LandlordCharge']
                        ]
                    )
                ]
            ])
            ->add('account_holder_name', 'text', ['label' => 'Account Holder Name', 'required' => true, 'attr' => ['class' => 'form-control','placeholder' => 'Jacob Miller'],
                'constraints' => [
                    new NotBlank(
                        [
                            'message' => 'Please enter holder name',
                            'groups' => ['LandlordCharge']
                        ]
                    )
                ]
            ])
            ->add('account_number', 'text', ['label' => 'Bank Account Number', 'required' => true, 'attr' => ['class' => 'form-control', 'placeholder' => '000123456789'],
                'constraints' => [
                    new NotBlank(
                        [
                            'message' => 'Please enter bank account number',
                            'groups' => ['LandlordCharge']
                        ]
                    )
                ]
            ])
            ->add('account_holder_type', 'choice', array(
                'label' => 'Account Type',
                'attr' => ['class' => 'form-control'],
                'empty_value' => 'Choose an option',
                'choices'  => array(
                    'Individual' => 'individual',
                    'Company' => 'company',
                ),
                // *this line is important*
                'choices_as_values' => true,
            ))*/
            ->add('amount', 'text', ['label' => 'Amount', 'required' => true, 'attr' => ['class' => 'form-control','placeholder' => '10.00'],
                'constraints' => [
                    new NotBlank(
                        [
                            'message' => 'Please enter amount to charge',
                            'groups' => ['LandlordCharge']
                        ]
                    ),
                    new GreaterThan([
                        'value' => '0.50',
                        'groups' => ['LandlordCharge']
                    ]),
                    new Regex([
                            'pattern' => '/^[0-9]{1,10}(,[0-9]{3})*(\.[0-9]+)*$/',
                            'message' => 'Please use only positive numbers',
                            'groups' => ['LandlordCharge']
                        ]
                    )
                ]
            ])
            ->add('description', 'textarea', ['label' => 'Description', 'required' => false, 'attr' => ['class' => 'form-control']])
            ->add('button', 'submit', ['label' => 'Pay to landlord', 'attr' => ['class' => 'btn red-btn btn-space', 'value' => 'Pay to landlord']]);
    }

    /**
     * @inheritdoc
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'validation_groups' => ['LandlordCharge']
        ]);
    }

    public function getName()
    {
        return 'erp_user_landlords_pay_landlord';
    }
}
