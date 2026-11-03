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

namespace SolParts\PayumUpc\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Extension\GenericTokenFactoryExtension;
use Payum\Core\Extension\StorageExtension;
use Payum\Core\GatewayInterface;
use Payum\Core\HttpClientInterface;
use Payum\Core\Model\Identity;
use Payum\Core\Model\Payment;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpPostRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Notify;
use Payum\Core\Request\Refund;
use Payum\Core\Request\Sync;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SolParts\PayumUpc\Api;
use SolParts\PayumUpc\Reply\NewPaymentRequired;
use SolParts\PayumUpc\UpcGatewayFactory;

final class GatewayTest extends TestCase
{
    private Payment $payment;
    private Token $captureToken;
    private Token $notifyToken;
    private GetHttpRequest $http;

    protected function setUp(): void
    {
        $this->payment = new Payment();
        $this->payment->setNumber('business-order-1');
        $this->payment->setTotalAmount(12550);
        $this->payment->setCurrencyCode('UAH');
        $this->payment->setDescription('Parts, delivery; order №1');
        $identity = new Identity(1, Payment::class);
        $this->captureToken = new Token();
        $this->captureToken->setDetails($identity);
        $this->captureToken->setGatewayName('upc-test');
        $this->captureToken->setAfterUrl('https://example.com/payment/done');
        $this->notifyToken = new Token();
        $this->notifyToken->setDetails($identity);
        $this->notifyToken->setGatewayName('upc-test');
        $this->notifyToken->setHash('notify-test');
        $this->http = new GetHttpRequest();
        $this->http->method = 'POST';
    }

    public function testCaptureConvertsPaymentPersistsRoutingAndDoesNotRepostOnReturn(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(200, [], Protocol::status()));
        $gateway = $this->gateway($client);
        try {
            $gateway->execute(new Capture($this->captureToken));
            self::fail('Expected a hosted checkout form');
        } catch (HttpPostRedirect $reply) {
            self::assertSame('https://ecg.test.upc.ua/go/pay', $reply->getUrl());
            self::assertSame('notify-test', $reply->getFields()['SD']);
            self::assertSame('980', $reply->getFields()['Currency']);
            self::assertSame('12550', $reply->getFields()['TotalAmount']);
            self::assertSame(20, \strlen($reply->getFields()['OrderID']));
            self::assertArrayNotHasKey('upc_gateway_name', $reply->getFields());
        }
        $details = $this->payment->getDetails();
        self::assertSame('upc-test', $details['upc_gateway_name']);
        self::assertSame('https://example.com/payment/done', $details['upc_forward_url']);
        self::assertArrayNotHasKey('Signature', $details);
        self::assertSame(Api::STATUS_PENDING, $details['upc_status']);

        $gateway->execute(new Capture($this->captureToken));
        self::assertSame($details['OrderID'], $this->payment->getDetails()['OrderID']);
        self::assertSame($details['PurchaseTime'], $this->payment->getDetails()['PurchaseTime']);
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
    }

    public function testSeparatePaymentsForSameBusinessOrderGetDifferentUpcIds(): void
    {
        $gateway = $this->gateway();
        $gateway->execute($first = new Convert($this->payment, 'array'));
        $gateway->execute($second = new Convert(clone $this->payment, 'array'));
        self::assertNotSame($first->getResult()['OrderID'], $second->getResult()['OrderID']);
    }

    public function testRepeatCaptureWithUnavailableStatusKeepsOriginalPaymentUnpaid(): void
    {
        $this->initialized();
        $before = $this->payment->getDetails();
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(520, [], 'error code: 520'));
        $gateway = $this->gateway($client);

        try {
            $gateway->execute(new Capture($this->captureToken));
            self::fail('An unavailable status endpoint must not open another checkout.');
        } catch (HttpPostRedirect) {
            self::fail('An ambiguous payment must not be posted again.');
        } catch (LogicException $e) {
            self::assertStringContainsString('with code "520"', $e->getMessage());
        }

        self::assertSame($before, $this->payment->getDetails());
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertSame('pending', $status->getValue());
    }

    #[DataProvider('checkoutWithoutPurchase')]
    public function testCheckoutWithoutPurchaseKeepsTheOriginalAttemptAvailableForLatePayment(array $results): void
    {
        $this->initialized();
        $before = $this->payment->getDetails();
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(3))->method('send')->willReturnOnConsecutiveCalls(
            new Response(200, [], Protocol::status($results)),
            new Response(200, [], Protocol::status($results)),
            new Response(200, [], Protocol::status()),
        );
        $gateway = $this->gateway($client);

        $gateway->execute(new Capture($this->captureToken)); // Reload of the original link.
        $retryToken = clone $this->captureToken;
        $retryToken->setAfterUrl('https://example.com/payment/done/second-token');
        try {
            $gateway->execute(new Capture($retryToken));
            self::fail('The host must be asked to create a separate attempt.');
        } catch (NewPaymentRequired $reply) {
            self::assertSame($retryToken, $reply->captureToken);
        }
        self::assertSame(Api::STATUS_UNKNOWN, $this->payment->getDetails()['upc_status']);
        self::assertSame($before['OrderID'], $this->payment->getDetails()['OrderID']);
        self::assertSame($before['PurchaseTime'], $this->payment->getDetails()['PurchaseTime']);
        $this->http->request = Protocol::notification();
        self::assertStringContainsString('Response.action=approve', $this->notify($gateway)->getContent());
        self::assertSame(Api::STATUS_PENDING, $this->payment->getDetails()['upc_status']);

        $gateway->execute(new Sync($this->payment));
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
    }

    public static function checkoutWithoutPurchase(): iterable
    {
        yield 'empty history' => [[]];
        yield 'transaction not found' => [[['tranCode' => '408']]];
        yield 'declined purchase' => [[Protocol::declinedResult('105')]];
    }

    public function testInitializedCaptureWithoutAfterUrlOnlySynchronizes(): void
    {
        $this->initialized();
        $token = new Token();
        $token->setGatewayName($this->captureToken->getGatewayName());
        $token->setDetails($this->captureToken->getDetails());
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('send')->willReturn(new Response(200, [], Protocol::status([Protocol::declinedResult()])));
        $gateway = $this->gateway($client);
        $gateway->execute(new Capture($token));
        $token->setAfterUrl('');
        $gateway->execute(new Capture($token));
        self::assertSame('105', $this->payment->getDetails()['TranCode']);
    }

    #[DataProvider('ambiguousHistory')]
    public function testAmbiguousHistoryDoesNotOpenAnotherCheckout(array $results, string $expectedStatus = Api::STATUS_UNKNOWN): void
    {
        $this->initialized();
        $before = $this->payment->getDetails();
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(200, [], Protocol::status($results)));
        $this->captureToken->setAfterUrl('https://example.com/payment/done/second-token');
        $this->gateway($client)->execute(new Capture($this->captureToken));
        self::assertSame($before['OrderID'], $this->payment->getDetails()['OrderID']);
        self::assertSame($expectedStatus, $this->payment->getDetails()['upc_status']);
    }

    public static function ambiguousHistory(): iterable
    {
        yield 'two approved purchases' => [[Protocol::purchaseResult(), Protocol::purchaseResult()]];
        yield '408 on a purchase' => [[Protocol::declinedResult('408')], Api::STATUS_PENDING];
        yield 'unfinished purchase' => [[Protocol::declinedResult('601')], Api::STATUS_PENDING];
        $incomplete = Protocol::declinedResult();
        unset($incomplete['tranCode']);
        yield 'expiry followed by an incomplete purchase' => [[Protocol::declinedResult('411'), $incomplete]];
    }

    public function testOrderCancellationLeavesTheBankAttemptReconcilable(): void
    {
        $this->initialized();
        $before = $this->payment->getDetails();
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(200, [], Protocol::status()));
        $gateway = $this->gateway($client);
        $gateway->execute(new Cancel($this->payment));
        self::assertSame($before, $this->payment->getDetails());

        $this->http->request = Protocol::notification();
        self::assertStringContainsString('Response.action=approve', $this->notify($gateway)->getContent());
        $gateway->execute(new Sync($this->payment));
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
    }

    #[DataProvider('latePaymentStates')]
    public function testLocalTerminalStatusDoesNotDiscardAnAuthenticatedPayment(string $state): void
    {
        $this->initialized($state);
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(200, [], Protocol::status()));
        $gateway = $this->gateway($client);
        $this->http->request = Protocol::notification();

        self::assertStringContainsString('Response.action=approve', $this->notify($gateway)->getContent());
        self::assertSame(Api::STATUS_PENDING, $this->payment->getDetails()['upc_status']);
        $gateway->execute(new Sync($this->payment));
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
    }

    public static function latePaymentStates(): iterable
    {
        yield 'locally canceled' => [Api::STATUS_CANCELED];
        yield 'previously failed' => [Api::STATUS_FAILED];
    }

    public function testStaticNotifyResolvesTokenAndAcknowledgesBeforeFinalSync(): void
    {
        $this->initialized();
        $this->http->request = Protocol::notification(['Response.forwardUrl' => 'https://attacker.example']);
        $reply = $this->notify($this->gateway());
        self::assertSame(200, $reply->getStatusCode());
        self::assertStringContainsString("Response.action=approve\n", $reply->getContent());
        self::assertStringContainsString("Response.forwardUrl=https://example.com/payment/done\n", $reply->getContent());
        $details = $this->payment->getDetails();
        self::assertSame(Api::STATUS_PENDING, $details['upc_status']);
        self::assertSame('26110212-123456', $details['XID']);
        self::assertArrayNotHasKey('Rrn', $details);
        self::assertArrayNotHasKey('RRN', $details);
    }

    #[DataProvider('retryableAttempts')]
    public function testDeclinedAttemptCanBePaidAgainInTheSameSession(string $code): void
    {
        $this->initialized();
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            new Response(200, [], Protocol::status([Protocol::declinedResult($code)])),
            new Response(200, [], Protocol::status([Protocol::declinedResult($code), Protocol::purchaseResult()])),
        );
        $gateway = $this->gateway($client);
        $this->http->request = Protocol::notification(['TranCode' => $code, 'ApprovalCode' => '']);
        unset($this->http->request['ApprovalCode']);
        self::assertSame(200, $this->notify($gateway)->getStatusCode());
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertTrue($status->isUnknown(), 'An attempt decline must leave the host payment pending.');

        $gateway->execute(new Sync($this->payment));
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertTrue($status->isUnknown());

        $this->http->request = Protocol::notification();
        self::assertStringContainsString('Response.action=approve', $this->notify($gateway)->getContent());
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertTrue($status->isPending());
        $gateway->execute(new Sync($this->payment));
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertTrue($status->isCaptured());
        self::assertSame(Protocol::payment()['OrderID'], $this->payment->getDetails()['OrderID']);
        self::assertSame(Protocol::payment()['SD'], $this->payment->getDetails()['SD']);
        self::assertSame('601411039294', $this->payment->getDetails()['RRN']);

        $this->http->request = Protocol::notification(['TranCode' => $code, 'ApprovalCode' => '']);
        $this->notify($gateway);
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertTrue($status->isCaptured(), 'A delayed decline must not undo the successful retry.');
    }

    public static function retryableAttempts(): iterable
    {
        yield 'issuer decline' => ['105'];
        yield 'insufficient funds' => ['116'];
        yield 'incorrect card details' => ['101'];
        yield 'authentication failure' => ['404'];
    }

    #[DataProvider('unconfirmedApprovalHistory')]
    public function testStaleSyncCannotOpenAnotherCheckoutAfterSuccessNotify(array $history): void
    {
        $this->initialized();
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            new Response(200, [], Protocol::status($history)),
            new Response(200, [], Protocol::status([Protocol::declinedResult(), Protocol::purchaseResult()])),
        );
        $gateway = $this->gateway($client);
        $this->http->request = Protocol::notification();
        $this->notify($gateway);

        $this->captureToken->setAfterUrl('https://example.com/payment/done/second-token');
        $gateway->execute(new Capture($this->captureToken));
        $details = $this->payment->getDetails();
        self::assertSame('000', $details['TranCode']);
        self::assertSame(Api::STATUS_PENDING, $details['upc_status']);
        self::assertSame($history, $details['status_results']);

        $gateway->execute(new Sync($this->payment));
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
    }

    public static function unconfirmedApprovalHistory(): iterable
    {
        yield 'previous decline' => [[Protocol::declinedResult()]];
        yield 'decline and expired link' => [[Protocol::declinedResult(), Protocol::declinedResult('411')]];
    }

    public function testDelayedDeclineIsAcknowledgedWithoutReplacingPendingApproval(): void
    {
        $this->initialized();
        $gateway = $this->gateway();
        $this->http->request = Protocol::notification();
        $this->notify($gateway);
        $approved = $this->payment->getDetails();

        $this->http->request = Protocol::notification(['TranCode' => '105', 'ApprovalCode' => '', 'XID' => 'previous-attempt']);
        $reply = $this->notify($gateway);
        self::assertStringContainsString('Response.action=approve', $reply->getContent());
        self::assertStringContainsString('XID=previous-attempt', $reply->getContent());
        self::assertSame($approved, $this->payment->getDetails());
    }

    public function testExpiredPaymentLinkIsTerminal(): void
    {
        $this->initialized();
        $gateway = $this->gateway();
        $this->http->request = Protocol::notification(['TranCode' => '411', 'ApprovalCode' => '']);
        self::assertSame(200, $this->notify($gateway)->getStatusCode());
        $gateway->execute($status = new GetHumanStatus($this->payment));
        self::assertTrue($status->isExpired());
    }

    public function testForgedNotifyCannotLookUpTokenOrChangePayment(): void
    {
        $this->initialized();
        $before = $this->payment->getDetails();
        $this->http->request = Protocol::notification();
        $this->http->request['TotalAmount'] = '1';
        $tokenStorage = $this->createMock(StorageInterface::class);
        $tokenStorage->expects(self::never())->method('find');
        $reply = $this->notify($this->gateway(tokenStorage: $tokenStorage));
        self::assertSame(400, $reply->getStatusCode());
        self::assertSame($before, $this->payment->getDetails());
    }

    /** @param array<string, string> $override */
    #[DataProvider('wrongPayment')]
    public function testSignedNotificationForDifferentPaymentIsReversedWithoutMutation(array $override): void
    {
        $this->initialized();
        $before = $this->payment->getDetails();
        $this->http->request = Protocol::notification($override);
        $reply = $this->notify($this->gateway());
        self::assertStringContainsString("Response.action=reverse\n", $reply->getContent());
        self::assertSame($before, $this->payment->getDetails());
    }

    public static function wrongPayment(): iterable
    {
        yield 'amount' => [['TotalAmount' => '1']];
        yield 'currency' => [['Currency' => '840']];
        yield 'order' => [['OrderID' => 'other-attempt']];
        yield 'time' => [['PurchaseTime' => '261102120001']];
        yield 'hold' => [['Delay' => '1']];
    }

    public function testCrossGatewayTokenIsRejected(): void
    {
        $this->initialized();
        $this->notifyToken->setGatewayName('other-gateway');
        $this->http->request = Protocol::notification();
        self::assertStringContainsString('Response.action=reverse', $this->notify($this->gateway())->getContent());
    }

    public function testDuplicateAndStaleNotificationsDoNotUndoConfirmedCaptureOrRefund(): void
    {
        $gateway = $this->gateway();
        foreach ([Api::STATUS_CAPTURED, Api::STATUS_REFUNDED] as $state) {
            $this->initialized($state);
            $before = $this->payment->getDetails();
            foreach (['000', '116'] as $code) {
                $this->http->request = Protocol::notification(['TranCode' => $code]);
                self::assertStringContainsString('Response.action=approve', $this->notify($gateway)->getContent());
                self::assertSame($before, $this->payment->getDetails());
            }
        }
    }

    #[DataProvider('refundResponses')]
    public function testRefundGetsTrustedRrnAndDoesNotRepeatCompletedRefund(string $response): void
    {
        $this->initialized(Api::STATUS_CAPTURED);
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('send')->willReturnCallback(static function (RequestInterface $request) use ($response): Response {
            if ('/go/service/01' === $request->getUri()->getPath()) {
                return new Response(200, [], Protocol::status());
            }
            \parse_str((string) $request->getBody(), $fields);
            self::assertSame('601411039294', $fields['RRN']);

            return new Response(200, [], $response);
        });
        $gateway = $this->gateway($client);
        $gateway->execute(new Refund($this->payment));
        $gateway->execute(new Refund($this->payment));
        $gateway->execute(new Sync($this->payment));
        self::assertSame(Api::STATUS_REFUNDED, $this->payment->getDetails()['upc_status']);
    }

    public static function refundResponses(): iterable
    {
        yield 'documented HTML' => [Protocol::refundResponse()];
        yield 'text with CRLF' => [Protocol::refundTextResponse()];
        yield 'text with LF' => [\str_replace("\r\n", "\n", Protocol::refundTextResponse())];
    }

    #[DataProvider('definitiveRefundRejections')]
    public function testExplicitRetryAfterDefinitiveRefundRejection(string $code): void
    {
        $this->initialized(Api::STATUS_CAPTURED);
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(4))->method('send')->willReturnOnConsecutiveCalls(
            new Response(200, [], Protocol::status()),
            new Response(200, [], Protocol::refundTextResponse($code) . "ERROR=Repayment error\n"),
            new Response(200, [], Protocol::status()),
            new Response(200, [], Protocol::refundTextResponse()),
        );
        $gateway = $this->gateway($client);
        try {
            $gateway->execute(new Refund($this->payment));
            self::fail('Expected a rejected refund');
        } catch (LogicException $e) {
            self::assertStringContainsString('UPC TranCode=' . $code, $e->getMessage());
            self::assertStringContainsString('with code "200"', $e->getMessage());
        }
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
        $gateway->execute(new Refund($this->payment));
        self::assertSame(Api::STATUS_REFUNDED, $this->payment->getDetails()['upc_status']);
    }

    public static function definitiveRefundRejections(): iterable
    {
        yield 'refund API disabled' => ['455'];
        yield 'invalid signature' => ['405'];
    }

    #[DataProvider('ambiguousRefunds')]
    public function testAmbiguousRefundDoesNotLoseCaptureAndCannotBeSubmittedAgain(Response $response): void
    {
        $this->initialized(Api::STATUS_CAPTURED);
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            new Response(200, [], Protocol::status()),
            $response,
        );
        $gateway = $this->gateway($client);
        try {
            $gateway->execute(new Refund($this->payment));
            self::fail('Expected API failure');
        } catch (LogicException $e) {
            self::assertStringContainsString('/go/repayment', $e->getMessage());
        }
        self::assertSame(Api::STATUS_CAPTURED, $this->payment->getDetails()['upc_status']);
        self::assertTrue($this->payment->getDetails()['upc_refund_requested']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Reconcile it before retrying');
        $gateway->execute(new Refund($this->payment));
    }

    public static function ambiguousRefunds(): iterable
    {
        yield 'timeout' => [new Response(502, [], 'gateway timeout')];
        yield 'other terminal' => [new Response(200, [], \str_replace('E1234567', 'E7654321', Protocol::refundTextResponse('455')))];
        yield 'unclassified error' => [new Response(200, [], Protocol::refundTextResponse('909'))];
    }

    public function testInvalidSyncDoesNotOverwritePersistedSnapshot(): void
    {
        $this->initialized(Api::STATUS_CAPTURED);
        $before = $this->payment->getDetails();
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('send')->willReturn(new Response(200, [], '{}'));
        try {
            $this->gateway($client)->execute(new Sync($this->payment));
            self::fail('Expected invalid envelope failure');
        } catch (LogicException) {
            self::assertSame($before, $this->payment->getDetails());
        }
    }

    #[DataProvider('states')]
    public function testCanonicalPayumStatuses(?string $state, string $expected): void
    {
        $this->gateway()->execute($status = new GetHumanStatus(new ArrayObject(['upc_status' => $state])));
        self::assertSame($expected, $status->getValue());
    }

    public static function states(): iterable
    {
        yield [null, 'new'];
        foreach (['pending', 'captured', 'failed', 'expired', 'canceled', 'refunded', 'unknown'] as $state) {
            yield [$state, $state];
        }
        yield ['', 'unknown'];
    }

    private function initialized(string $state = Api::STATUS_PENDING): void
    {
        $this->payment->setDetails(Protocol::payment() + [
            'upc_status' => $state, 'upc_initialized' => true,
            'upc_gateway_name' => 'upc-test', 'upc_forward_url' => 'https://example.com/payment/done',
        ]);
    }

    private function notify(GatewayInterface $gateway): HttpResponse
    {
        try {
            $gateway->execute(new Notify(null));
            self::fail('Expected the UPC acknowledgement');
        } catch (HttpResponse $reply) {
            return $reply;
        }
    }

    private function gateway(?HttpClientInterface $client = null, ?StorageInterface $tokenStorage = null): GatewayInterface
    {
        $tokenFactory = $this->createStub(GenericTokenFactoryInterface::class);
        $tokenFactory->method('createNotifyToken')->willReturn($this->notifyToken);
        if (null === $tokenStorage) {
            $tokenStorage = $this->createStub(StorageInterface::class);
            $tokenStorage->method('find')->willReturnCallback(fn ($hash) => 'notify-test' === $hash ? $this->notifyToken : null);
        }
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('support')->willReturnCallback(static fn ($model): bool => $model instanceof Payment);
        $storage->method('find')->willReturnCallback(fn ($identity) => $identity instanceof Identity && 1 === $identity->getId() ? $this->payment : null);
        $http = $this->createStub(ActionInterface::class);
        $http->method('supports')->willReturnCallback(static fn ($request): bool => $request instanceof GetHttpRequest);
        $http->method('execute')->willReturnCallback(function (GetHttpRequest $request): void {
            $request->request = $this->http->request;
            $request->method = $this->http->method;
        });

        return (new UpcGatewayFactory())->create(Protocol::options() + [
            'payum.http_client' => $client ?? $this->createStub(HttpClientInterface::class),
            'payum.http_message_factory' => new Psr17Factory(), 'payum.http_stream_factory' => new Psr17Factory(),
            'httplug.client' => $this->createStub(\Http\Client\HttpClient::class),
            'httplug.message_factory' => new Psr17Factory(), 'httplug.stream_factory' => new Psr17Factory(),
            'payum.security.token_storage' => $tokenStorage,
            'payum.extension.storage' => new StorageExtension($storage),
            'payum.extension.token_factory' => new GenericTokenFactoryExtension($tokenFactory),
            'payum.action.get_http_request' => $http,
        ]);
    }
}
