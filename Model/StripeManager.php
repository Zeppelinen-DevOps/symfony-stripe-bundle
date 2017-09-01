<?php
/**
 * Copyright (C) 2017 [JS]Folio
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


namespace Uc\PaymentBundle\Model;

use Exception;
use HttpException;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Plan;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\StripeObject;
use Stripe\Subscription;
use Symfony\Component\HttpKernel\Kernel;


/**
 * @author Steve [JS]Folio
 *
 */
class StripeManager extends Stripe
{

    /**
     * @var Kernel
     */
    protected $kernel;

    /**
     * @var array
     */
    protected $config;

    /**
     * StripeManager constructor.
     * @param Kernel $kernel
     */
    function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;

        $this->config = $kernel->getContainer()->getParameter('uc_payment.stripe');

        self::setApiKey($this->config['app_secret']);

        return $this;
    }

    /**
     * Response stripe account public key
     *
     * @see https://stripe.com/docs/checkout/php
     *
     * @return mixed
     */
    public function publicKey()
    {
        return $this->config['public_key'];
    }

    /**
     * Create a new Charge from a payment token, to an optional connected stripe account, with an optional application fee.
     *
     * @throws HttpException:
     *     - If the payment token is invalid (payment failed)
     *
     * @see https://stripe.com/docs/charges
     *
     * @param int $chargeAmount : The charge amount in cents
     * @param string $chargeCurrency : The charge currency to use
     * @param string $paymentToken : The payment token returned by the payment form (Stripe.js)
     * @param string $stripeAccountId : The connected stripe account ID
     * @param int $applicationFee : The fee taken by the platform, in cents
     * @param string $description : An optional charge description
     * @param array $metadata : An optional array of metadatas
     *
     * @return Charge
     */
    public function createCharge(
        $chargeAmount, //The charge amount in cents
        $chargeCurrency, //The charge currency to use
        $paymentToken, //The payment token returned by the payment form (Stripe.js)
        $applicationFee = 0, //The fee taken by the platform, in cents
        $chargeDescription = '',
        $chargeMetadata = []
    )
    {
        $chargeOptions = [
            'amount' => $chargeAmount,
            'currency' => $chargeCurrency,
            'source' => $paymentToken,
            'description' => $chargeDescription,
            'metadata' => $chargeMetadata
        ];

        if ($applicationFee && intval($applicationFee) > 0)
        {
            $chargeOptions['application_fee'] = intval($applicationFee);
        }

        return Charge::create($chargeOptions);
    }


    /**
     * @param $email
     * @return null
     */
    public function getCustomerByEmail($email)
    {
        $lastCustomer = null;
        $customer = null;
        while (true) {
            $customers = Customer::all([
                'limit' => 100,
                'starting_after' => $lastCustomer
            ]);

            foreach ($customers->autoPagingIterator() as $stripeCustomer) {
                if ($stripeCustomer->email === $email) {
                    $customer = $stripeCustomer;
                    break 2;
                }
            }

            if (!$customers->has_more) {
                break;
            }

            $lastCustomer = end($customers->data);
        }

        return $customer;
    }

    /**
     * @param $email
     * @return null|Customer
     */
    public function getCustomerByEmailOrNew($email)
    {
        $customer = $this->getCustomerByEmail($email);

        if (!$customer) {
            $customer = Customer::create([
                'email' => $email
            ]);
        }

        return $customer;
    }


    /**
     * @param string $stripeSubscriptionId
     * @param null|string $source
     * @return Exception|Subscription
     */
    public function chargeSubscription($stripeSubscriptionId, $source = null)
    {
        try {
            $subscription = Subscription::retrieve($stripeSubscriptionId);

            if ($subscription->status === 'trialing') {
                $subscription->trial_end = 'now';
            }

            if ($source) {
                $subscription->source = $source;
            }

            return $subscription->save();

        } catch (Exception $exception) {
            return $exception;
        }
    }


    /**
     * @param $stripeCustomerId
     * @param $email
     * @return Exception|null|Customer
     */
    public function getCustomerOrNew($stripeCustomerId, $email)
    {
        try {
            $customer = null;
            if ($stripeCustomerId) {
                $customer = Customer::retrieve($stripeCustomerId);
            }

            if (!$customer) {
                $customer = Customer::create([
                    'email' => $email
                ]);
            }

            return $customer;
        } catch (Exception $exception) {
            return $exception;
        }
    }

    /**
     * @param Customer $customer
     * @param array $params
     * @return Exception|Customer
     */
    public function updateCustomer($customer, $params)
    {
        try {
            foreach ($params as $field => $value) {
                $customer->$field = $value;
            }

            $customer->save();

            return $customer;
        } catch (Exception $exception) {
            return $exception;
        }
    }

    /**
     * @param $data
     * @return StripeObject
     */
    public function constructSubscriptionFormArray($data): StripeObject
    {
        return Subscription::constructFrom($data, []);
    }

    /**
     * @param Customer $customer
     * @param Plan $plan
     * @param array $params
     * @return string|Subscription
     */
    public function createSubscription($customer, $plan, array $params = [])
    {
        $data = [
            'customer' => $customer->id,
            'items' => [
                [
                    'plan' => $plan->id
                ]
            ],
        ];
        $data = array_merge($data, $params);

        /*if ($trialEndTime) {
            $params['trial_end'] = $trialEndTime;
        }*/

        try {
            return Subscription::create($data);
        } catch (Exception $exception) {
            return $exception;
        }
    }

    public function cancelSubscription($stripeSubscriptionId)
    {
        try {
            $subscription = Subscription::retrieve($stripeSubscriptionId);

            return $subscription->cancel();
        } catch (Exception $exception) {
            return $exception;
        }
    }

    /**
     * @param $planId
     * @return string|Plan
     */
    public function getPlanById($planId)
    {
        try {
            return Plan::retrieve($planId);
        } catch (Exception $exception) {
            return $exception;
        }
    }

    /**
     * Create a new Refund on an existing Charge (by its ID).
     *
     * @throws HttpException:
     *     - If the charge id is invalid (the charge does not exists...)
     *     - If the charge has already been refunded
     *
     * @see https://stripe.com/docs/connect/direct-charges#issuing-refunds
     *
     * @param string $chargeId : The charge ID
     * @param int $refundAmount : The charge amount in cents
     * @param array $metadata : optional additional informations about the refund
     * @param string $reason : The reason of the refund, either "requested_by_customer", "duplicate" or "fraudulent"
     * @param bool $refundApplicationFee : Wether the application_fee should be refunded aswell.
     * @param bool $reverseTransfer : Wether the transfer should be reversed
     * @param string $stripeAccountId : The optional connected stripe account ID on which charge has been made.
     *
     * @return Refund
     */
    public function refundCharge(
        $chargeId, //The charge ID
        $refundAmount = null, //The charge amount in cents
        $metadata = [], //optional additional informations about the refund
        $reason = 'requested_by_customer', //The reason of the refund, either "requested_by_customer", "duplicate" or "fraudulent"
        $reverseTransfer = false //Wether the transfer should be reversed
    )
    {
        $refundOptions = [
            'charge' => $chargeId,
            'metadata' => $metadata,
            'reason' => $reason,
            'reverse_transfer' => (bool)$reverseTransfer
        ];
        if ($refundAmount)
        {
            $refundOptions['amount'] = intval($refundAmount);
        }

        return Refund::create($refundOptions);
    }
}