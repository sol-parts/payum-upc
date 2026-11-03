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
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Sync;
use SolParts\PayumUpc\Api;

final class SyncAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** @param Sync $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $details = ArrayObject::ensureArrayObject($request->getModel());
        if (!$details['upc_initialized'] || Api::STATUS_REFUNDED === $details['upc_status']) {
            return;
        }
        $snapshot = $this->api->getStatus((array) $details);
        // Status can still show an earlier card decline after UPC has sent an approval.
        if ('000' === $details['TranCode'] && Api::STATUS_CAPTURED !== $details['upc_status']
            && \in_array($snapshot['upc_status'], [Api::STATUS_PENDING, Api::STATUS_UNKNOWN], true)) {
            $snapshot['TranCode'] = '000';
            $snapshot['upc_status'] = Api::STATUS_PENDING;
        }
        $details->replace($snapshot);
    }

    public function supports($request): bool
    {
        return $request instanceof Sync && $request->getModel() instanceof \ArrayAccess;
    }
}
