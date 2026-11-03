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

namespace SolParts\PayumUpc\Reply;

use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Security\TokenInterface;

/** Capture cannot reopen this checkout. The host must preserve it and create a separate payment. */
final class NewPaymentRequired extends HttpRedirect
{
    public function __construct(public readonly TokenInterface $captureToken)
    {
        // A standard Payum host without a retry handler returns to the existing payment.
        parent::__construct($captureToken->getAfterUrl(), 303);
    }
}
