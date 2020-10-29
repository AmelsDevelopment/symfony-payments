<?php

namespace App\Controller\PayPal;

use App\Repository\PayPalOrderRepository;
use App\SymfonyPayments\PayPal\PaypalWebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    private $payPalOrderRepository;
    private $httpClient;
    private $entityManager;

    public function __construct(PayPalOrderRepository $payPalOrderRepository, EntityManagerInterface $entityManager)
    {
        $this->payPalOrderRepository = $payPalOrderRepository;
        $this->httpClient = new Client();
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/webhook/paypal", methods={"POST"})
     * @param Request $request
     * @return Response
     */
    public function payPalWebhook(Request $request): Response
    {
        $data = json_decode($request->getContent());

        $eventType = $data->event_type;

        if ($eventType == PaypalWebhookEventType::CUSTOMER_DISPUTE_CREATED) {
            $transactionId = $data->resource->disputed_transactions[0]->seller_transaction_id;

            $order = $this->payPalOrderRepository->findOneBy([
                "transactionId" => $transactionId
            ]);

            if ($order != null) {
                $this->httpClient->post($order->getRefundCallback(), [
                    "body" => json_encode([
                        "action" => PaypalWebhookEventType::CUSTOMER_DISPUTE_CREATED,
                        "status" => "CREATED",
                        "transactionId" => $transactionId
                    ])
                ]);

                $order->setStatus("DISPUTE");

                $this->entityManager->persist($order);
                $this->entityManager->flush();
            }
        } else if ($eventType == PaypalWebhookEventType::CUSTOMER_DISPUTE_RESOLVED) {
            $transactionId = $data->resource->disputed_transactions[0]->seller_transaction_id;
            $outcome = $data->resource->dispute_outcome->outcome_code;

            $order = $this->payPalOrderRepository->findOneBy([
                "transactionId" => $transactionId
            ]);

            if ($order != null) {
                $this->httpClient->post($order->getRefundCallback(), [
                    "body" => json_encode([
                        "action" => PaypalWebhookEventType::CUSTOMER_DISPUTE_RESOLVED,
                        "status" => $outcome,
                        "transactionId" => $transactionId
                    ])
                ]);
            }
        }

        return new Response();
    }
}