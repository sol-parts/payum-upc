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
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Refund;
use Payum\Core\Request\Sync;
use SolParts\PayumUpc\Api;
use SolParts\PayumUpc\Exception\UpcApiException;

final class RefundAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** @param Refund $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $details = ArrayObject::ensureArrayObject($request->getModel());
        if (Api::STATUS_REFUNDED === $details['upc_status']) {
            return;
        }
        if ($details['upc_refund_requested']) {
            throw new LogicException('An UPC refund was already submitted. Reconcile it before retrying.');
        }
        if (isset($details['RefundAmount'])) {
            throw new LogicException('Only full UPC refunds are supported.');
        }
        $this->gateway->execute(new Sync($details));
        if (Api::STATUS_CAPTURED !== $details['upc_status']) {
            throw new LogicException('Only a confirmed UPC purchase can be refunded.');
        }
        // RRN comes from the authenticated status response, never the unsigned Notify field.
        // Preserve this flag even after a timeout: the remote refund may have succeeded.
        $details['upc_refund_requested'] = true;
        try {
            $this->api->refund((array) $details);
        } catch (UpcApiException $e) {
            // UPC rejected the signature or the terminal has no access to the refund API.
            if (\in_array($e->tranCode, ['405', '455'], true)) {
                unset($details['upc_refund_requested']);
            }
            throw $e;
        }
        $details['upc_status'] = Api::STATUS_REFUNDED;
    }

    public function supports($request): bool
    {
        return $request instanceof Refund && $request->getModel() instanceof \ArrayAccess;
    }
}
