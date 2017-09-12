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
use PayPal\Api\Amount;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payee;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use Symfony\Component\HttpKernel\Kernel;


/**
 * @author Steve [JS]Folio
 *
 */
class PayPalManager
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
     * PayPalManager constructor.
     * @param Kernel $kernel
     */
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
        $this->config = $kernel->getContainer()->getParameter('uc_payment.paypal');
    }

    /**
     * Response PayPal account client ID
     *
     * @return mixed
     */
    public function getClientId()
    {
        return $this->config['client_id'];
    }

    /**
     * Response PayPal account secret key
     *
     * @return mixed
     */
    public function getSecretKey()
    {
        return $this->config['secret'];
    }

    /**
     * Response PayPal account secret key
     *
     * @return mixed
     */
    public function getMode()
    {
        return $this->config['mode'];
    }


    /**
     * @param string $destinationEmail
     * @param array $products
     * @param array $params
     * @return Exception|null|string
     */
    public function getPaymentUrl($destinationEmail, $products, $params/*, $totalPrice, $currency*/)
    {
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');

        $items = new ItemList();
        foreach ($products as $product) {
            $item = new Item();

            $item->setName($product['name'])
                ->setCurrency($params['currency'])
                ->setQuantity($product['quantity'])
                ->setSku($product['sku'])
                ->setPrice($product['price']);

            $items->addItem($item);
        }

        $apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->getClientId(),
                $this->getSecretKey()
            )
        );

        $apiContext->setConfig([
            'mode' => $this->getMode()
        ]);

        $amount = new Amount();
        $amount->setCurrency($params['currency'])
            ->setTotal($params['totalPrice']);


        $payee = new Payee([
            'email' => $destinationEmail
        ]);

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($items)
            //->setDescription("Order #".$order->getId())
            ->setPayee($payee)
            ->setInvoiceNumber($params['id']);

        $redirectUrls = new RedirectUrls();

        $redirectUrls->setReturnUrl($params['return_url'])
            ->setCancelUrl($params['cancel_url']);


        $payment = new Payment();
        $payment->setIntent('sale')
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions([$transaction]);

        try {
            $payment->create($apiContext);
            return $payment->getApprovalLink();
        } catch (Exception $exception) {
            return $exception;
        }
    }


    public function processPayment($paymentId, $payerId)
    {
        $apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->getClientId(),
                $this->getSecretKey()
            )
        );

        $apiContext->setConfig([
            'mode' => $this->getMode()
        ]);

        try {
            $payment = Payment::get($paymentId, $apiContext);
            $transaction = $payment->getTransactions()[0];

            $execution = new PaymentExecution();
            $execution->setPayerId($payerId);

            return [$payment->execute($execution, $apiContext), $transaction->getInvoiceNumber()];

        } catch (Exception $exception) {
            return [$exception, false];
        }

        $paymentId = $request->query->get('paymentId');
        $session = $this->get('session');
        $orderManager = $this->get('app.manager.shop.order');
        $cartManager = $this->get('app.manager.shop.cart');
        try {
            $payment = Payment::get($paymentId, $apiContext);
            $transaction = $payment->getTransactions()[0];

            $orderID = $transaction->getInvoiceNumber();
            $order = $orderManager->findOrderBy([
                'id' => $orderID
            ]);

            if ($order) {
                $user = $order->getUser();
                $shop = $order->getShop();

                if ($user && $shop) {
                    /*$cart = $cartManager->findCartBy([
                        'user' => $user,
                        'shop' => $shop
                    ]);*/

                    $items = $transaction->getItemList();
                    $shipping = $items->getShippingAddress();

                    //$address = [];
                    //$country = Intl::getRegionBundle()->getCountryName(strtoupper($shipping->getCountryCode())) ?? $shipping->getCountryCode();
                    //$order->setZipCode($shipping->getPostalCode());
                    //$address['zipCode'] = $shipping->getPostalCode();
                    //$address['country'] = $country;
                    //$order->setCountry($country);
                    //$order->setCity($shipping->getCity());
                    //$address['city'] = $shipping->getCity();
                    //$order->setRegion($shipping->getState());
                    //$address['region'] = $shipping->getState();
                    //$order->setStreet($shipping->getLine1());
                    //$address['street'] = $shipping->getLine1();
                    //$order->setAddress(implode(', ', $address));

                    //$orderManager->updateOrder($order);

                    $execution = new PaymentExecution();
                    $execution->setPayerId($request->query->get('PayerID'));

                    try {
                        // Execute the payment
                        // (See bootstrap.php for more on `ApiContext`)


                        //$orderManager->updateOrder($order);
                        //$cartManager->deleteCart($cart);
                        $result = $payment->execute($execution, $apiContext);

                        return $this->redirectToRoute('shop_checkout_status', ['id' => $order->getId()]);
                    } catch (Exception $exception) {
                        if ($order->getId()) {
                            $orderManager->deleteOrder($order);
                        }
                        $session->getFlashBag()->add('error', $exception->getMessage());
                    }
                } else {
                    $session->getFlashBag()->add('error', 'User not provided');
                }
            } else {
                $session->getFlashBag()->add('error', 'Cannot find order');
            }
            return $this->redirectToRoute('shop_homepage');

        } catch (Exception $exception) {
            $session->getFlashBag()->add('error', $exception->getMessage());
        }
        return $this->redirectToRoute('shop_checkout');
    }
}
