<?php

namespace App\SymfonyPayments\PayPal;

class PaypalWebhookEventType
{
    public const CUSTOMER_DISPUTE_CREATED = "CUSTOMER.DISPUTE.CREATED";
    public const CUSTOMER_DISPUTE_RESOLVED = "CUSTOMER.DISPUTE.RESOLVED";
}