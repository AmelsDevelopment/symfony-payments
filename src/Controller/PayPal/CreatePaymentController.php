<?php

namespace App\Controller\PayPal;

use App\Entity\PayPalOrder;
use App\Entity\ServerVariables;
use App\SymfonyPayments\Model\PayPalModel;
use App\SymfonyPayments\PayPal\Order\PayPalItem;
use App\SymfonyPayments\PayPal\PayPalClient;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CreatePaymentController extends AbstractController
{
    private $required_fields = [
        PayPalClient::FIELD_AMOUNT,
        PayPalClient::FIELD_CURRENCY,
        PayPalClient::FIELD_RETURN_URL,
        PayPalClient::FIELD_CANCEL_URL
    ];

    private $entityManager;
    private $payPalClient;

    public function __construct(EntityManagerInterface $entityManager, PayPalClient $payPalClient)
    {
        $this->entityManager = $entityManager;
        $this->payPalClient = $payPalClient;
    }

    /**
     * @Route("/api/paypal/payment", methods={"POST", "OPTIONS"})
     * @param Request $request
     * @return JsonResponse|void
     * @throws Exception
     */
    public function createPayPalPayment(Request $request): JsonResponse
    {
        if (0 !== strpos($request->headers->get("Content-Type"), "application/json")) {
            return;
        }

        $data = json_decode($request->getContent(), true);
        $this->validate($data);

        //Build Order
        $orderBuilder = $this->payPalClient->getOrderBuilder()
            ->setAmount($data[PayPalClient::FIELD_AMOUNT])
            ->setCurrency($data[PayPalClient::FIELD_CURRENCY])
            ->setCancelUrl($data[PayPalClient::FIELD_CANCEL_URL])
            ->setReturnUrl($data[PayPalClient::FIELD_RETURN_URL]);

        if (array_key_exists("items", $data)) {
            foreach ($data['items'] as $item) {
                $ppItem = new PayPalItem();
                $ppItem->setName($item['name']);
                $ppItem->setDesc($item['description']);
                $ppItem->setQuantity($item['quantity']);
                $ppItem->setUnitAmount($item['unit_amount']);
                $orderBuilder->addItem($ppItem);
            }
        }

        //Execute Order against /v2/checkout/orders
        $data = $this->executeTransaction($this->payPalClient, $orderBuilder->build());
        return new JsonResponse($data);
    }

    /**
     * @Route("/api/paypal/payment", methods={"PUT", "GET"})
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function completePayPalPayment(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $payerId = $data[PayPalClient::FIELD_PAYER_ID];
        $orderId = $data[PayPalClient::FIELD_ORDER_ID];
        $refundCallback = null;

        if (key_exists("refund_callback", $data)) {
            $refundCallback = $data['refund_callback'];
        }

        $transaction = $this->payPalClient->getPaymentBuilder()
            ->setPayerId($payerId)
            ->setOrderId($orderId)
            ->build();

        $data = $this->executeTransaction($this->payPalClient, $transaction);

        $model = new PayPalModel($data);

        if ($refundCallback != null) {
            $ppOrder = new PayPalOrder();
            $ppOrder->setStatus($model->getStatus());
            $ppOrder->setOrderId($model->getOrderId());
            $ppOrder->setTransactionId($model->getTransactionId());
            $ppOrder->setRefundCallback($refundCallback);

            $this->entityManager->persist($ppOrder);
            $this->entityManager->flush();
        }

        return new JsonResponse($model->getResponseData());
    }

    /**
     * @param PayPalClient $paypalClient
     * @param false $newToken
     * @return mixed
     * @throws Exception
     */
    private function getAccessToken(PayPalClient $paypalClient, $newToken = false)
    {
        $serverVariableRepository = $this->getDoctrine()->getRepository(ServerVariables::class);

        $serverPayPalVariable = $serverVariableRepository->findOneBy([
            "property" => "PAYPAL_ACCESS_TOKEN"
        ]);

        $accessToken = null;

        if ($serverPayPalVariable == null || $newToken) {
            $accessToken = $paypalClient->authenticate($_ENV["PAYPAL_CLIENT_ID"], $_ENV["PAYPAL_CLIENT_SECRET"]);
            $serverPayPalVariable = $serverPayPalVariable == null ? new ServerVariables() : $serverPayPalVariable;
            $serverPayPalVariable->setProperty("PAYPAL_ACCESS_TOKEN");
            $serverPayPalVariable->setValue($accessToken);
            $this->getDoctrine()->getManager()->persist($serverPayPalVariable);
            $this->getDoctrine()->getManager()->flush();
        } else {
            $accessToken = $serverPayPalVariable->getValue();
        }

        return $accessToken;
    }

    /**
     * @param PayPalClient $client
     * @param $transaction
     * @return mixed
     * @throws Exception
     */
    private function executeTransaction(PayPalClient $client, $transaction)
    {
        $client->setSandboxMode($_ENV["PAYPAL_SANDBOX"]);
        $accessToken = $this->getAccessToken($client);

        try {
            $data = $client->execute($accessToken, $transaction);
        } catch (Exception $e) {
            $accessToken = $this->getAccessToken($client, true);
            $data = $client->execute($accessToken, $transaction);
        }

        return $data;
    }

    /**
     * @param $data
     * @throws Exception
     */
    private function validate($data)
    {
        foreach ($this->required_fields as $field) {
            if (!array_key_exists($field, $data)) {
                throw new Exception("Required Key Not Found");
            }
        }
    }
}