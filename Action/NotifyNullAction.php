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
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetToken;
use Payum\Core\Request\Notify;
use SolParts\PayumUpc\Api;

/** Routes the terminal's fixed NOTIFY_URL using the authenticated SD field. */
final class NotifyNullAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $this->gateway->execute($http = new GetHttpRequest());
        if ('POST' !== $http->method) {
            throw new HttpResponse('', 405, ['Allow' => 'POST']);
        }
        try {
            $notification = $this->api->verifyNotification($http->request);
            $this->gateway->execute($token = new GetToken($notification['SD']));
        } catch (LogicException) {
            throw new HttpResponse('Invalid notification', 400);
        }
        $this->gateway->execute(new Notify($token->getToken()));
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && null === $request->getModel();
    }
}
