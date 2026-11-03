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
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Cancel;

/** Single-stage hosted checkout has no cancellation operation; confirmed purchases need Refund. */
final class CancelAction implements ActionInterface
{
    /** @param Cancel $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        // The host may cancel the business order. Keep the payment pending for
        // Notify/Sync: changing local state cannot stop the customer's bank page.
    }

    public function supports($request): bool
    {
        return $request instanceof Cancel && $request->getModel() instanceof \ArrayAccess;
    }
}
