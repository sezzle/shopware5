<?php

namespace SwagPaymentSezzle\Components\Backend;

use Exception;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Order\Order;
use Shopware\Models\Order\Status;
use SwagPaymentSezzle\Components\ExceptionHandlerServiceInterface;
use SwagPaymentSezzle\Components\Services\OrderDataService;
use SwagPaymentSezzle\Components\Services\OrderStatusService;
use SwagPaymentSezzle\Components\Services\Validation\PaymentActionValidator;
use SwagPaymentSezzle\Components\Services\PaymentStatusService;
use SwagPaymentSezzle\SezzleBundle\Resources\ReleaseResource;
use SwagPaymentSezzle\SezzleBundle\Structs\Session\Order\Amount;
use SwagPaymentSezzle\SezzleBundle\Util;

class ReleaseService
{
    /**
     * @var ExceptionHandlerServiceInterface
     */
    private $exceptionHandler;

    /**
     * @var ReleaseResource
     */
    private $releaseResource;

    /**
     * @var PaymentStatusService
     */
    private $paymentStatusService;
    /**
     * @var OrderDataService
     */
    private $orderDataService;
    /**
     * @var ModelManager
     */
    private $modelManager;
    /**
     * @var OrderStatusService
     */
    private $orderStatusService;
    /**
     * @var PaymentActionValidator
     */
    private $paymentActionValidator;

    public function __construct(
        ExceptionHandlerServiceInterface $exceptionHandler,
        ReleaseResource $releaseResource,
        PaymentStatusService $paymentStatusService,
        OrderStatusService $orderStatusService,
        OrderDataService $orderDataService,
        ModelManager $modelManager,
        PaymentActionValidator $paymentActionValidator
    )
    {
        $this->exceptionHandler = $exceptionHandler;
        $this->releaseResource = $releaseResource;
        $this->paymentStatusService = $paymentStatusService;
        $this->orderStatusService = $orderStatusService;
        $this->orderDataService = $orderDataService;
        $this->modelManager = $modelManager;
        $this->paymentActionValidator = $paymentActionValidator;
    }

    /**
     * @param string $orderUUID
     * @param string $amountToRelease
     * @param string $currency
     * @return array
     */
    public function releaseOrder($orderUUID, $amountToRelease, $currency)
    {
        $releasePayload = $this->createRelease($amountToRelease, $currency);

        try {
            if (!$this->paymentActionValidator->isAmountValid($orderUUID, $amountToRelease, 'DoRelease')) {
                throw new Exception("Invalid amount");
            }
            $captureData = $this->releaseResource->create($orderUUID, $releasePayload);
            if (empty($captureData['uuid'])) {
                throw new Exception("Error releasing");
            }
            $this->orderStatusService->updateOrderStatus(
                $orderUUID,
                Status::ORDER_STATE_IN_PROCESS
            );
            /** @var Order|null $orderModel */
            $orderModel = $this->modelManager->getRepository(Order::class)->findOneBy(['temporaryId' => $orderUUID]);

            if (!($orderModel instanceof Order)) {
                throw new Exception('Order not found');
            }

            if ($orderModel->getAttribute()->getSwagSezzleAuthAmount() == $amountToRelease) {
                $this->paymentStatusService->updatePaymentStatus(
                    $orderUUID,
                    Status::PAYMENT_STATE_THE_PROCESS_HAS_BEEN_CANCELLED
                );
            }
            $prevReleasedAmount = $orderModel->getAttribute()->getSwagSezzleReleasedAmount();
            $newReleasedAmount = Util::formatToCurrency($releasePayload->getAmountInCents());
            $attributesToUpdate = [
                'authAmount' => $orderModel->getAttribute()->getSwagSezzleAuthAmount() - $newReleasedAmount,
                'releasedAmount' => $prevReleasedAmount + $newReleasedAmount
            ];
            $this->orderDataService->applyPaymentAttributes($orderModel->getNumber(), $attributesToUpdate);

            $viewParameter = ['success' => true];
        } catch (Exception $e) {
            $error = $this->exceptionHandler->handle($e, 'capture order');

            $viewParameter = [
                'success' => false,
                'message' => $error->getCompleteMessage(),
            ];
        }

        return $viewParameter;
    }

    /**
     * @param float $amount
     * @param string $currency
     * @return  Amount
     */
    private function createRelease($amount, $currency)
    {
        $requestParameters = new Amount();
        $requestParameters->setAmountInCents(Util::formatToCents($amount));
        $requestParameters->setCurrency($currency);

        return $requestParameters;
    }
}
