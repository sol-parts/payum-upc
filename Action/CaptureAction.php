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
use Payum\Core\Reply\HttpPostRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Sync;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use SolParts\PayumUpc\Api;
use SolParts\PayumUpc\Reply\NewPaymentRequired;

final class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** @param Capture $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $details = ArrayObject::ensureArrayObject($request->getModel());
        $token = $request->getToken();
        if ($details['upc_initialized']) {
            $this->gateway->execute(new Sync($details));
            // Payum issues a distinct after token for each new capture link. Reloading the
            // same link (including a browser navigation replay) is not a second intent to pay.
            if (null !== $token && ($afterUrl = $token->getAfterUrl()) && $afterUrl !== $details['upc_forward_url'] && $this->canRetry($details)) {
                throw new NewPaymentRequired($token);
            }

            return;
        }
        if (null === $token || null === $this->tokenFactory) {
            throw new LogicException('UPC Capture requires a Payum capture token and token factory.');
        }
        $forwardUrl = $token->getAfterUrl();
        if (\strlen($forwardUrl) > 255 || !\filter_var($forwardUrl, \FILTER_VALIDATE_URL)
            || !\in_array(\parse_url($forwardUrl, \PHP_URL_SCHEME), ['https', 'http'], true)
            || \preg_match('/[\r\n]/', $forwardUrl)) {
            throw new LogicException('UPC requires an HTTP(S) after URL of at most 255 bytes.');
        }
        $notifyToken = $this->tokenFactory->createNotifyToken($token->getGatewayName(), $token->getDetails());
        $fields = $this->api->prepareCheckout((array) $details + ['SD' => $notifyToken->getHash()]);
        // SD belongs to routing, never to caller-provided checkout metadata.
        if ($fields['SD'] !== $notifyToken->getHash()) {
            throw new LogicException('UPC SD is reserved for the Payum notify token.');
        }
        $snapshot = $fields;
        unset($snapshot['Signature']);
        $details->replace($snapshot);
        $details['upc_gateway_name'] = $token->getGatewayName();
        $details['upc_forward_url'] = $forwardUrl;
        $details['upc_initialized'] = true;
        $details['upc_status'] = Api::STATUS_PENDING;

        throw new HttpPostRedirect($this->api->getGatewayUrl(), $fields);
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof \ArrayAccess;
    }

    private function canRetry(ArrayObject $details): bool
    {
        if (\in_array($details['upc_status'], [Api::STATUS_CAPTURED, Api::STATUS_REFUNDED, Api::STATUS_PENDING], true)
            || '000' === $details['TranCode']) {
            return false;
        }
        $results = $details['status_results'];
        // Before card entry UPC can return a single "transaction not found" row without an operation.
        if (1 === \count($results) && !isset($results[0]['operType']) && '408' === ($results[0]['tranCode'] ?? null)) {
            return true;
        }

        // Declined purchases can be retried; mixed history containing a possible charge must be reconciled first.
        foreach ($results as $result) {
            $code = $result['tranCode'] ?? null;
            if ('PURCHASE' !== ($result['operType'] ?? null)
                || (!\is_string($code) && !\is_int($code)) || !\preg_match('/^[0-9]{3}$/D', (string) $code)
                || \in_array(Api::statusForCode((string) $code), [Api::STATUS_CAPTURED, Api::STATUS_PENDING], true)) {
                return false;
            }
        }

        return true;
    }
}
