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
use Payum\Core\Request\GetStatusInterface;
use SolParts\PayumUpc\Api;

final class StatusAction implements ActionInterface
{
    /** @param GetStatusInterface $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $details = ArrayObject::ensureArrayObject($request->getModel());
        match ($details['upc_status']) {
            null => $request->markNew(),
            Api::STATUS_PENDING => $request->markPending(),
            Api::STATUS_CAPTURED => $request->markCaptured(),
            Api::STATUS_FAILED => $request->markFailed(),
            Api::STATUS_EXPIRED => $request->markExpired(),
            Api::STATUS_CANCELED => $request->markCanceled(),
            Api::STATUS_REFUNDED => $request->markRefunded(),
            default => $request->markUnknown(),
        };
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface && $request->getModel() instanceof \ArrayAccess;
    }
}
