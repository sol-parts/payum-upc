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
use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SolParts\PayumUpc\Api;

final class ApiTest extends TestCase
{
    public function testCheckoutSignatureAndPayloadWhitelist(): void
    {
        $fields = $this->api()->prepareCheckout(Protocol::payment() + [
            'PurchaseDesc' => 'Parts, delivery; order №1', 'Ref3' => 'reference',
            'merchant_private_key' => 'must-not-leak', '_error' => 'must-not-leak', 'Signature' => 'forged',
        ]);
        self::assertSame('Parts, delivery; order №1', $fields['PurchaseDesc']);
        self::assertArrayNotHasKey('merchant_private_key', $fields);
        self::assertArrayNotHasKey('_error', $fields);
        self::assertSame('1', $fields['Version']);
        self::assertSame('uk', $fields['locale']);
        self::assertSame(1, \openssl_verify(
            '1234567;E1234567;261102120000;attempt-1;980;12550;notify-test;reference;',
            \base64_decode($fields['Signature'], true),
            Protocol::publicKey('merchant'),
            \OPENSSL_ALGO_SHA512,
        ));
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidCheckout')]
    public function testRejectsInvalidCheckout(array $override): void
    {
        $this->expectException(LogicException::class);
        $this->api()->prepareCheckout(\array_replace(Protocol::payment(), $override));
    }

    public static function invalidCheckout(): iterable
    {
        yield 'hold' => [['Delay' => 1]];
        yield 'fractional minor units' => [['TotalAmount' => 12.5]];
        yield 'zero amount' => [['TotalAmount' => '0']];
        yield 'long order' => [['OrderID' => \str_repeat('a', 21)]];
        yield 'delimiter injection' => [['OrderID' => 'order;other']];
        yield 'newline injection' => [['SD' => "token\nResponse.action=approve"]];
        yield 'array input' => [['Currency' => ['980']]];
        yield 'different merchant' => [['MerchantID' => '7654321']];
    }

    public function testSignedNotificationDropsUnauthenticatedFieldsAndAcceptsUnknownFields(): void
    {
        $notification = $this->api()->verifyNotification(Protocol::notification(['NewUpcField' => ['arbitrary']]));
        self::assertSame('000', $notification['TranCode']);
        self::assertArrayNotHasKey('Rrn', $notification);
        self::assertArrayNotHasKey('ProxyPan', $notification);
        self::assertArrayNotHasKey('NewUpcField', $notification);
        self::assertTrue($this->api()->notificationMatches(Protocol::payment(), $notification));
    }

    public function testNotificationWithExplicitZeroDelayVerifiesCorrectSignature(): void
    {
        $api = $this->api();
        $notification = $api->verifyNotification(Protocol::notification(['Delay' => '0']));
        self::assertTrue($api->notificationMatches(Protocol::payment(), $notification));
    }

    public function testDeclineWithoutApprovalCodeStillRequiresAValidSignature(): void
    {
        $post = Protocol::notification(['TranCode' => '116', 'ApprovalCode' => '']);
        unset($post['ApprovalCode']);
        $api = $this->api();
        self::assertSame('', $api->verifyNotification($post)['ApprovalCode']);

        $post['TranCode'] = '000';
        $this->expectException(LogicException::class);
        $api->verifyNotification($post);
    }

    public function testValidHoldOrAlternateCurrencyNotificationDoesNotMatchPurchase(): void
    {
        $api = $this->api();
        foreach ([['Delay' => '1'], ['AltCurrency' => '840'], ['AltTotalAmount' => '300']] as $override) {
            self::assertFalse($api->notificationMatches(Protocol::payment(), $api->verifyNotification(Protocol::notification($override))));
        }
    }

    public function testTamperedNotificationIsRejected(): void
    {
        $post = Protocol::notification();
        $post['TotalAmount'] = '1';
        $this->expectException(LogicException::class);
        $this->api()->verifyNotification($post);
    }

    public function testMissingMerchantCannotBeReconstructedFromConfiguration(): void
    {
        $post = Protocol::notification();
        unset($post['MerchantID']);
        $this->expectException(LogicException::class);
        $this->api()->verifyNotification($post);
    }

    public function testStatusRequestSignsExactEnvelopeAndVerifiesResponse(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturnCallback(static function (RequestInterface $request): Response {
            self::assertSame('https://ecg.test.upc.ua/go/service/01', (string) $request->getUri());
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $body = \json_decode((string) $request->getBody(), true, 32, \JSON_THROW_ON_ERROR);
            self::assertSame('{"alg":"RS256"}', \base64_decode($body['header'], true));
            self::assertSame(1, \openssl_verify(
                $body['header'] . '.' . $body['payload'],
                \base64_decode($body['signature'], true),
                Protocol::publicKey('merchant'),
                \OPENSSL_ALGO_SHA256,
            ));
            $expected = Protocol::payment();
            unset($expected['SD']);
            self::assertSame($expected, \json_decode(\base64_decode($body['payload'], true), true));

            return new Response(200, [], Protocol::status());
        });
        $snapshot = $this->api($client)->getStatus(Protocol::payment());
        self::assertSame(Api::STATUS_CAPTURED, $snapshot['upc_status']);
        self::assertSame('601411039294', $snapshot['RRN']);
    }

    #[DataProvider('badStatusResponses')]
    public function testRejectsUnauthenticatedOrMismatchedStatus(string $kind): void
    {
        $body = match ($kind) {
            'html' => '<html>error</html>',
            'algorithm' => Protocol::status(alg: 'none'),
            'amount' => Protocol::status([\array_replace(Protocol::purchaseResult(), ['amount' => 1])]),
            'currency' => Protocol::status([\array_replace(Protocol::purchaseResult(), ['currency' => '840'])]),
            'signature' => \str_replace('12550', '1', \json_encode(['header' => 'eyJhbGciOiJSUzUxMiJ9', 'payload' => 'e30=', 'signature' => 'YWJj'])),
        };
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('send')->willReturn(new Response(200, [], $body));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('/go/service/01');
        $this->api($client)->getStatus(Protocol::payment());
    }

    public static function badStatusResponses(): iterable
    {
        foreach (['html', 'algorithm', 'amount', 'currency', 'signature'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testUnknownOperationsAndMultipleResultsNeverBecomeCaptured(): void
    {
        foreach ([[], [\array_replace(Protocol::purchaseResult(), ['operType' => 'REFUND'])], [Protocol::purchaseResult(), Protocol::purchaseResult()], [\array_replace(Protocol::purchaseResult(), ['actionCode' => '503'])]] as $results) {
            $client = $this->createMock(HttpClientInterface::class);
            $client->method('send')->willReturn(new Response(200, [], Protocol::status($results)));
            self::assertSame(Api::STATUS_UNKNOWN, $this->api($client)->getStatus(Protocol::payment())['upc_status']);
        }
    }

    #[DataProvider('attemptDeclines')]
    public function testAttemptDeclineDoesNotEndThePayment(string $code): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('send')->willReturn(new Response(200, [], Protocol::status([
            Protocol::declinedResult($code),
        ], 'RS256')));
        $snapshot = $this->api($client)->getStatus(Protocol::payment());
        self::assertSame(Api::STATUS_UNKNOWN, $snapshot['upc_status']);
        self::assertSame($code, $snapshot['TranCode']);
    }

    public static function attemptDeclines(): iterable
    {
        foreach (['101', '105', '108', '111', '116', '130', '131', '290', '291', '401', '402', '403', '404', '405',
            '406', '407', '412', '414', '420', '421', '430', '454', '701', '902', '904', '909'] as $code) {
            yield $code => [$code];
        }
    }

    public function testSuccessfulRetryIsRecognizedRegardlessOfHistoryOrder(): void
    {
        $results = [Protocol::declinedResult(), Protocol::purchaseResult()];
        foreach ([$results, \array_reverse($results)] as $history) {
            $client = $this->createMock(HttpClientInterface::class);
            $client->method('send')->willReturn(new Response(200, [], Protocol::status($history)));
            $snapshot = $this->api($client)->getStatus(Protocol::payment());
            self::assertSame(Api::STATUS_CAPTURED, $snapshot['upc_status']);
            self::assertSame('000', $snapshot['TranCode']);
            self::assertSame('123456', $snapshot['ApprovalCode']);
            self::assertSame('601411039294', $snapshot['RRN']);
            self::assertSame($history, $snapshot['status_results']);
        }
    }

    public function testAmbiguousHistoryDoesNotProveCaptureOrEndTheSession(): void
    {
        foreach ([
            [Protocol::declinedResult(), Protocol::declinedResult('116')],
            [Protocol::purchaseResult(), \array_replace(Protocol::purchaseResult(), ['operType' => 'REFUND'])],
            [Protocol::purchaseResult(), \array_replace(Protocol::purchaseResult(), ['actionCode' => '503'])],
            [Protocol::purchaseResult(), Protocol::declinedResult('411')],
            [Protocol::purchaseResult(), Protocol::declinedResult('504')],
        ] as $history) {
            $client = $this->createMock(HttpClientInterface::class);
            $client->method('send')->willReturn(new Response(200, [], Protocol::status($history)));
            self::assertSame(Api::STATUS_UNKNOWN, $this->api($client)->getStatus(Protocol::payment())['upc_status']);
        }
    }

    public function testRetryHistoryMustMatchOriginalAmountAndCurrency(): void
    {
        foreach ([['amount' => 1], ['currency' => '840']] as $mismatch) {
            $client = $this->createMock(HttpClientInterface::class);
            $client->method('send')->willReturn(new Response(200, [], Protocol::status([
                \array_replace(Protocol::declinedResult(), $mismatch), Protocol::purchaseResult(),
            ])));
            try {
                $this->api($client)->getStatus(Protocol::payment());
                self::fail('A successful retry must not hide mismatched operations.');
            } catch (LogicException $e) {
                self::assertStringContainsString('Status amount or currency mismatch', $e->getMessage());
            }
        }
    }

    public function testRefundUsesOriginalAmountAndSignedStatusIdentifiers(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturnCallback(static function (RequestInterface $request): Response {
            self::assertSame('https://ecg.test.upc.ua/go/repayment', (string) $request->getUri());
            \parse_str((string) $request->getBody(), $fields);
            self::assertArrayNotHasKey('RefundAmount', $fields);
            self::assertArrayNotHasKey('SD', $fields);
            self::assertArrayNotHasKey('Ref3', $fields);
            self::assertArrayNotHasKey('upc_status', $fields);
            self::assertSame(1, \openssl_verify(
                '1234567;E1234567;261102120000;attempt-1;980;12550;;123456;601411039294;',
                \base64_decode($fields['Signature'], true),
                Protocol::publicKey('merchant'),
                \OPENSSL_ALGO_SHA512,
            ));

            return new Response(200, [], Protocol::refundResponse());
        });
        $this->api($client)->refund(Protocol::payment() + ['ApprovalCode' => '123456', 'RRN' => '601411039294', 'Ref3' => '']);
    }

    public function testRefundDisabledIsAnApiErrorNotARefundedPayment(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('send')->willReturn(new Response(200, [], Protocol::refundResponse('455')));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UPC TranCode=455) for "https://ecg.test.upc.ua/go/repayment" with code "200"');
        $this->api($client)->refund(Protocol::payment() + ['ApprovalCode' => '123456', 'RRN' => '601411039294']);
    }

    /** @param non-empty-string $response */
    #[DataProvider('invalidRefundTextResponses')]
    public function testRefundTextCannotFalselyConfirmSuccess(string $response, string $error): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(200, [], $response));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($error);
        $this->api($client)->refund(Protocol::payment() + ['ApprovalCode' => '123456', 'RRN' => '601411039294']);
    }

    public static function invalidRefundTextResponses(): iterable
    {
        yield 'observed bank rejection' => [Protocol::refundTextResponse('405') . "ERROR=Repayment error\r\n", 'UPC TranCode=405'];
        yield 'different merchant' => [\str_replace('MerchantID=1234567', 'MerchantID=7654321', Protocol::refundTextResponse()), 'Refund rejected'];
        yield 'different terminal' => [\str_replace('TerminalID=E1234567', 'TerminalID=E7654321', Protocol::refundTextResponse()), 'Refund rejected'];
        yield 'missing merchant' => ["TerminalID=E1234567\nTranCode=000\n", 'Refund rejected'];
        yield 'success with error' => [Protocol::refundTextResponse() . 'ERROR=Repayment error', 'Refund rejected'];
        yield 'duplicate code' => [Protocol::refundTextResponse('405') . 'TranCode=000', 'Duplicate refund response field'];
        yield 'duplicate merchant' => [Protocol::refundTextResponse() . 'MerchantID=1234567', 'Duplicate refund response field'];
        yield 'trailing garbage' => [Protocol::refundTextResponse() . 'unavailable', 'Invalid refund response'];
    }

    public function testHttpFailureIsNotRetried(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('send')->willReturn(new Response(503, [], 'unavailable'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with code "503". Content: unavailable');
        $this->api($client)->getStatus(Protocol::payment());
    }

    private function api(?HttpClientInterface $client = null): Api
    {
        $factory = new Psr17Factory();

        return new Api(Protocol::options(), $client ?? $this->createStub(HttpClientInterface::class), $factory, $factory);
    }
}
