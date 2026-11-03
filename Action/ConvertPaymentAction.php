<?php

/*
 * This file is part of SOLPARTS
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\PayumUpc\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Payum\Core\Request\GetCurrency;

final class ConvertPaymentAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /** @param Convert $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $this->gateway->execute($currency = new GetCurrency($payment->getCurrencyCode()));
        // A business order may have several payments. UPC requires a unique ID per attempt.
        $details['OrderID'] ??= \bin2hex(\random_bytes(10));
        $details['Currency'] = \sprintf('%03d', $currency->numeric);
        $details['TotalAmount'] = (string) $payment->getTotalAmount();
        $details['PurchaseDesc'] = \mb_substr((string) $payment->getDescription(), 0, 125);
        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert && $request->getSource() instanceof PaymentInterface && 'array' === $request->getTo();
    }
}
