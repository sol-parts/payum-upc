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
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use SolParts\PayumUpc\Api;

final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** @param Notify $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $this->gateway->execute($http = new GetHttpRequest());
        if ('POST' !== $http->method) {
            throw new HttpResponse('', 405, ['Allow' => 'POST']);
        }
        try {
            $notification = $this->api->verifyNotification($http->request);
        } catch (LogicException) {
            throw new HttpResponse('Invalid notification', 400);
        }
        $details = ArrayObject::ensureArrayObject($request->getModel());
        $token = $request->getToken();
        $matches = $this->api->notificationMatches((array) $details, $notification)
            && null !== $token && $token->getHash() === $details['SD']
            && $token->getGatewayName() === $details['upc_gateway_name'];
        // Local order cancellation must not discard a genuine bank payment.
        if ($matches && '000' !== $details['TranCode']
            && !\in_array($details['upc_status'], [Api::STATUS_CAPTURED, Api::STATUS_REFUNDED], true)) {
            $details['XID'] = $notification['XID'];
            $details['TranCode'] = $notification['TranCode'];
            $details['ApprovalCode'] = $notification['ApprovalCode'];
            // approve is permission to complete the purchase, not confirmation that
            // UPC received our acknowledgement. Sync establishes the final outcome.
            $details['upc_status'] = '000' === $notification['TranCode']
                ? Api::STATUS_PENDING : Api::statusForCode($notification['TranCode']);
        }
        throw new HttpResponse($this->api->notificationResponse(
            $notification,
            $matches,
            $matches ? (string) $details['upc_forward_url'] : '',
        ), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof \ArrayAccess;
    }
}
