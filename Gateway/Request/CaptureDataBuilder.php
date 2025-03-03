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
 * Copyright (c) 2021 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Request;

use Adyen\AdyenException;
use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Gateway\Http\Client\TransactionCapture;
use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Data as DataHelper;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Order\Payment as PaymentModel;
use Adyen\Payment\Model\ResourceModel\Order\Payment;
use Adyen\Payment\Observer\AdyenHppDataAssignObserver;
use Magento\Framework\App\Action\Context;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;

/**
 * Class CustomerDataBuilder
 */
class CaptureDataBuilder implements BuilderInterface
{
    /**
     * @var DataHelper
     */
    private $adyenHelper;

    /**
     * @var ChargedCurrency
     */
    private $chargedCurrency;

    /**
     * @var Payment
     */
    private $orderPaymentResourceModel;

    /**
     * @var AdyenOrderPayment
     */
    private $adyenOrderPaymentHelper;

    /**
     * @var AdyenLogger
     */
    private $adyenLogger;

    /**
     * @var Context
     */
    private $context;

    /**
     * CaptureDataBuilder constructor.
     *
     * @param DataHelper $adyenHelper
     * @param ChargedCurrency $chargedCurrency
     * @param AdyenOrderPayment $adyenOrderPaymentHelper
     * @param AdyenLogger $adyenLogger
     * @param Context $context
     * @param Payment $orderPaymentResourceModel
     */
    public function __construct(
        DataHelper $adyenHelper,
        ChargedCurrency $chargedCurrency,
        AdyenOrderPayment $adyenOrderPaymentHelper,
        AdyenLogger $adyenLogger,
        Context $context,
        Payment $orderPaymentResourceModel
    ) {
        $this->adyenHelper = $adyenHelper;
        $this->chargedCurrency = $chargedCurrency;
        $this->adyenOrderPaymentHelper = $adyenOrderPaymentHelper;
        $this->adyenLogger = $adyenLogger;
        $this->context = $context;
        $this->orderPaymentResourceModel = $orderPaymentResourceModel;
    }

    /**
     * Create capture request
     *
     * @param array $buildSubject
     * @return array
     * @throws AdyenException
     */
    public function build(array $buildSubject)
    {
        /** @var \Magento\Payment\Gateway\Data\PaymentDataObject $paymentDataObject */
        $paymentDataObject = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        /** @var Order $order */
        $order = $payment->getOrder();
        /** @var \Magento\Sales\Model\Order\Invoice $latestInvoice */
        $latestInvoice = $order->getInvoiceCollection()->getLastItem();
        $invoiceAmountCurrency = $this->chargedCurrency->getInvoiceAmountCurrency($latestInvoice);
        $amount = $invoiceAmountCurrency->getAmount();
        $currency = $invoiceAmountCurrency->getCurrencyCode();
        $amount = $this->adyenHelper->formatAmount($amount, $currency);
        $pspReference = $payment->getCcTransId();
        $brandCode = $payment->getAdditionalInformation(AdyenHppDataAssignObserver::BRAND_CODE);

        // If total amount has not been authorized
        if (!$this->adyenOrderPaymentHelper->isTotalAmountAuthorized($order)) {
            $errorMessage = sprintf(
                'Unable to send capture request for order %s. Full amount has not been authorized',
                $order->getIncrementId()
            );
            $this->adyenLogger->error($errorMessage);
            $this->context->getMessageManager()->addErrorMessage(__(
                    'Full order amount has not been authorized')
            );

            throw new AdyenException($errorMessage);
        }

        $adyenOrderPayments = $this->orderPaymentResourceModel->getLinkedAdyenOrderPayments($payment->getId());
        if (!is_null($adyenOrderPayments) && count($adyenOrderPayments) > 1) {
            return $this->buildMultipleCaptureData($payment, $currency, $adyenOrderPayments);
        }

        $modificationAmount = ['currency' => $currency, 'value' => $amount];
        $requestBody = [
            "modificationAmount" => $modificationAmount,
            "reference" => $payment->getOrder()->getIncrementId(),
            "originalReference" => $pspReference
        ];

        if ($this->adyenHelper->isPaymentMethodOpenInvoiceMethod($brandCode)) {
            $openInvoiceFields = $this->getOpenInvoiceData($payment);
            $requestBody["additionalData"] = $openInvoiceFields;
        }
        $request['body'] = $requestBody;
        $request['clientConfig'] = ["storeId" => $payment->getOrder()->getStoreId()];

        return $request;
    }

    /**
     * @param $payment
     * @return mixed
     * @internal param $formFields
     */
    protected function getOpenInvoiceData($payment)
    {
        $formFields = [];
        $count = 0;
        $order = $payment->getOrder();
        $invoices = $order->getInvoiceCollection();

        $currency = $this->chargedCurrency
            ->getOrderAmountCurrency($payment->getOrder(), false)
            ->getCurrencyCode();

        // The latest invoice will contain only the selected items(and quantities) for the (partial) capture
        $latestInvoice = $invoices->getLastItem();

        /* @var \Magento\Sales\Model\Order\Invoice\Item $invoiceItem */
        foreach ($latestInvoice->getItems() as $invoiceItem) {
            if ($invoiceItem->getOrderItem()->getParentItem()) {
                continue;
            }
            ++$count;
            $itemAmountCurrency = $this->chargedCurrency->getInvoiceItemAmountCurrency($invoiceItem);
            $numberOfItems = (int)$invoiceItem->getQty();
            $formFields = $this->adyenHelper->createOpenInvoiceLineItem(
                $formFields,
                $count,
                $invoiceItem->getName(),
                $itemAmountCurrency->getAmount(),
                $currency,
                $itemAmountCurrency->getTaxAmount(),
                $itemAmountCurrency->getAmount() + $itemAmountCurrency->getTaxAmount(),
                $invoiceItem->getOrderItem()->getTaxPercent(),
                $numberOfItems,
                $payment,
                $invoiceItem->getId()
            );
        }

        // Shipping cost
        if ($latestInvoice->getShippingAmount() > 0) {
            ++$count;
            $adyenInvoiceShippingAmount = $this->chargedCurrency->getInvoiceShippingAmountCurrency($latestInvoice);
            $formFields = $this->adyenHelper->createOpenInvoiceLineShipping(
                $formFields,
                $count,
                $order,
                $adyenInvoiceShippingAmount->getAmount(),
                $adyenInvoiceShippingAmount->getTaxAmount(),
                $adyenInvoiceShippingAmount->getCurrencyCode(),
                $payment
            );
        }

        $formFields['openinvoicedata.numberOfLines'] = $count;

        return $formFields;
    }

    /**
     * Return the data of the multiple capture requests required to capture the full order
     *
     * @param $payment
     * @param $currency
     * @param $adyenOrderPayments
     * @return array[]
     */
    private function buildMultipleCaptureData($payment, $currency, $adyenOrderPayments)
    {
        $this->adyenLogger->debug(sprintf(
            'Building capture request for multiple authorisations, on payment %s', $payment->getId()
        ));

        $captureData = [];

        foreach ($adyenOrderPayments as $adyenOrderPayment) {
            // Only add data related to adyen_order_payment that have not been captured yet
            if ($adyenOrderPayment[OrderPaymentInterface::CAPTURE_STATUS] === OrderPaymentInterface::CAPTURE_STATUS_NO_CAPTURE) {
                $amount = $this->adyenHelper->formatAmount(
                    $adyenOrderPayment[OrderPaymentInterface::AMOUNT],
                    $currency
                );
                $modificationAmount = [
                    'currency' => $currency,
                    'value' => $amount
                ];
                $authToCapture = [
                    "modificationAmount" => $modificationAmount,
                    "reference" => $payment->getOrder()->getIncrementId(),
                    "originalReference" => $adyenOrderPayment[OrderPaymentInterface::PSPREFRENCE]
                ];

                if ($this->adyenHelper->isPaymentMethodOpenInvoiceMethod($adyenOrderPayment[OrderPaymentInterface::PAYMENT_METHOD])) {
                    $openInvoiceFields = $this->getOpenInvoiceData($payment);
                    $authToCapture["additionalData"] = $openInvoiceFields;
                }
                $captureData[] = $authToCapture;
            }
        }

        $requestBody = [
            TransactionCapture::MULTIPLE_AUTHORIZATIONS => $captureData
        ];

        $request['body'] = $requestBody;
        $request['clientConfig'] = ["storeId" => $payment->getOrder()->getStoreId()];

        return $request;
    }
}
