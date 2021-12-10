<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Response;

use Adyen\Payment\Gateway\Http\Client\TransactionCapture;
use Adyen\Payment\Helper\Invoice;
use Adyen\Payment\Logger\AdyenLogger;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order;

class PaymentCaptureDetailsHandler implements HandlerInterface
{
    /** @var AdyenLogger $adyenLogger */
    private $adyenLogger;

    /** @var Invoice $invoiceHelper */
    private $invoiceHelper;

    /**
     * PaymentCaptureDetailsHandler constructor.
     * @param AdyenLogger $adyenLogger
     * @param Invoice $invoiceHelper
     */
    public function __construct(AdyenLogger $adyenLogger, Invoice $invoiceHelper)
    {
        $this->adyenLogger = $adyenLogger;
        $this->invoiceHelper = $invoiceHelper;
    }

    /**
     * @param array $handlingSubject
     * @param array $response
     */
    public function handle(array $handlingSubject, array $response)
    {
        $payment = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($handlingSubject);

        /** @var Order\Payment $payment */
        $payment = $payment->getPayment();

        if (array_key_exists(TransactionCapture::MULTIPLE_AUTHORIZATIONS, $response)) {
            $this->handleMultipleCaptureRequests($payment, $response);
        } else {
            // set pspReference as lastTransId only!
            $payment->setLastTransId($response['pspReference']);
            $this->invoiceHelper->createAdyenInvoice(
                $payment,
                $response['pspReference'],
                $response[TransactionCapture::ORIGINAL_REFERENCE],
                $response[TransactionCapture::CAPTURE_AMOUNT]
            );

            // The capture request will return a capture-received message, but it doesn't mean the capture has been final
            // so the invoice is set to Pending
            if ($response["response"] === TransactionCapture::CAPTURE_RECEIVED) {
                //$this->setInvoiceToPending($payment);
            }
        }
    }

    /**
     * @param Order\Payment $payment
     * @param $responseContainer
     * @throws AlreadyExistsException
     */
    public function handleMultipleCaptureRequests(Order\Payment $payment, $responseContainer)
    {
        $lastTransId = null;
        $this->adyenLogger->info(sprintf(
            'Handling multiple capture response in details handler for payment %s',
            $payment->getId()
        ));

        $captureNotReceived = [];

        foreach ($responseContainer[TransactionCapture::MULTIPLE_AUTHORIZATIONS] as $response) {
            if ($response["response"] !== TransactionCapture::CAPTURE_RECEIVED) {
                $captureNotReceived[] = $response['pspReference'];
            } else {
                $lastTransId = $response['pspReference'];
                $this->invoiceHelper->createAdyenInvoice(
                    $payment,
                    $response['pspReference'],
                    $response[TransactionCapture::ORIGINAL_REFERENCE],
                    $response[TransactionCapture::CAPTURE_AMOUNT]
                );
            }
        }

        if (isset($lastTransId)) {
            // Set transId to the last capture request
            $payment->setLastTransId($lastTransId);
        }


        if (empty($captureNotReceived)) {
            $this->setInvoiceToPending($payment);
        } else {
            $this->adyenLogger->error(sprintf(
                'Response for transactions [%s] did not contain [capture-received]',
                implode(', ', $captureNotReceived)
            ));
        }
    }

    /**
     * Set payment to pending
     *
     * @param $payment
     * @return mixed
     */
    private function setInvoiceToPending($payment)
    {
        $payment->setIsTransactionPending(true);
        // Do not close parent authorisation since order can still be cancelled/refunded
        $payment->setShouldCloseParentTransaction(false);

        return $payment;
    }
}
