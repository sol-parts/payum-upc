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

namespace SolParts\PayumUpc;

use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SolParts\PayumUpc\Exception\UpcApiException;

/** UPC e-Commerce Connect hosted checkout and merchant API. */
final class Api
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_UNKNOWN = 'unknown';

    private readonly string $merchantId;
    private readonly string $terminalId;
    private readonly string $baseUrl;
    private readonly \OpenSSLAsymmetricKey $privateKey;
    private readonly \OpenSSLAsymmetricKey $gatewayKey;

    /** @param array<string, mixed> $options */
    public function __construct(
        array $options,
        private readonly HttpClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        $this->merchantId = self::field($options, 'merchant_id', 15);
        $this->terminalId = self::field($options, 'terminal_id', 8);
        $this->baseUrl = ($options['sandbox'] ?? true) ? 'https://ecg.test.upc.ua' : 'https://secure.upc.ua';
        $privateKey = $options['merchant_private_key'] ?? '';
        $gatewayCertificate = $options['gateway_certificate'] ?? '';

        // Accept PEM contents only: key paths and stream wrappers must not cause file reads.
        if (!\is_string($privateKey) || !\str_starts_with($privateKey, '-----BEGIN ')
            || !\is_string($gatewayCertificate) || !\str_starts_with($gatewayCertificate, '-----BEGIN ')) {
            throw new LogicException('UPC requires merchant_private_key and gateway_certificate as PEM contents.');
        }

        $private = @\openssl_pkey_get_private($privateKey);
        $public = @\openssl_pkey_get_public($gatewayCertificate);
        if (false === $private || false === $public
            || \OPENSSL_KEYTYPE_RSA !== (\openssl_pkey_get_details($private)['type'] ?? null)
            || \OPENSSL_KEYTYPE_RSA !== (\openssl_pkey_get_details($public)['type'] ?? null)) {
            throw new LogicException('UPC requires a valid RSA private key and gateway certificate/public key.');
        }

        $this->privateKey = $private;
        $this->gatewayKey = $public;
    }

    public function getGatewayUrl(): string
    {
        return $this->baseUrl . '/go/pay';
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, string>
     */
    public function prepareCheckout(array $details): array
    {
        $details['PurchaseTime'] ??= \gmdate('ymdHisO');
        $fields = ['Version' => '1'] + $this->paymentFields($details);
        $fields['SD'] = self::field($details, 'SD', 99);
        $fields['locale'] = self::field($details + ['locale' => 'uk'], 'locale', 2);
        if (!\in_array($fields['locale'], ['uk', 'en'], true)) {
            throw new LogicException('UPC locale must be uk or en.');
        }
        // Hold settlement has a different protocol; never silently turn it into a purchase.
        if (!\in_array($details['Delay'] ?? '', ['', '0', 0], true)) {
            throw new LogicException('UPC preauthorization is not supported by this gateway.');
        }
        if (isset($details['PurchaseDesc']) && '' !== $details['PurchaseDesc']) {
            if (!\is_string($details['PurchaseDesc']) || \mb_strlen($details['PurchaseDesc']) > 125) {
                throw new LogicException('Invalid UPC purchase description.');
            }
            $fields['PurchaseDesc'] = $details['PurchaseDesc'];
        }
        foreach (['Ref3' => 150] as $name => $length) {
            if (isset($details[$name]) && '' !== $details[$name]) {
                $fields[$name] = self::field($details, $name, $length);
            }
        }
        $data = $this->purchaseSignatureData($fields);
        if (isset($fields['Ref3'])) {
            $data .= $fields['Ref3'] . ';';
        }
        $fields['Signature'] = $this->sign($data, \OPENSSL_ALGO_SHA512);

        return $fields;
    }

    /**
     * Verify before token lookup or mutation. Only authenticated fields are returned;
     * Rrn, ProxyPan and arbitrary extra POST fields are not covered by UPC's signature.
     *
     * @param array<string, mixed> $post
     *
     * @return array<string, string>
     */
    public function verifyNotification(array $post): array
    {
        // Do not supply defaults for a received message: MID and TID must be present.
        self::field($post, 'MerchantID', 15);
        self::field($post, 'TerminalID', 8);
        // Declined operations may omit the authorization code. Its signed slot is empty.
        if (!\array_key_exists('ApprovalCode', $post)) {
            $post['ApprovalCode'] = '';
        }
        $fields = $this->paymentFields($post);
        foreach (['SD' => 99, 'XID' => 28, 'TranCode' => 3, 'ApprovalCode' => 6] as $name => $length) {
            $fields[$name] = self::field($post, $name, $length, 'ApprovalCode' === $name);
        }
        foreach (['Delay' => 1, 'AltCurrency' => 3, 'AltTotalAmount' => 12] as $name => $length) {
            if (isset($post[$name]) && '' !== $post[$name]) {
                $fields[$name] = self::field($post, $name, $length);
            }
        }
        $signature = self::decodeBase64(self::field($post, 'Signature', 2048));
        if (1 !== \openssl_verify($this->purchaseSignatureData($fields, true), $signature, $this->gatewayKey, \OPENSSL_ALGO_SHA512)) {
            throw new LogicException('Invalid UPC notification signature.');
        }

        return $fields;
    }

    /** @param array<string, mixed> $details
     * @param array<string, string> $notification
     */
    public function notificationMatches(array $details, array $notification): bool
    {
        foreach (['MerchantID', 'TerminalID', 'OrderID', 'PurchaseTime', 'Currency', 'TotalAmount', 'SD'] as $name) {
            if (!isset($details[$name]) || (string) $details[$name] !== $notification[$name]) {
                return false;
            }
        }

        return \in_array($notification['Delay'] ?? '', ['', '0'], true)
            && !isset($notification['AltCurrency']) && !isset($notification['AltTotalAmount']);
    }

    /** @param array<string, string> $notification */
    public function notificationResponse(array $notification, bool $approve, string $forwardUrl = ''): string
    {
        if (\strlen($forwardUrl) > 255 || \preg_match('/[\r\n]/', $forwardUrl)) {
            throw new LogicException('Invalid UPC forward URL.');
        }
        $response = \array_intersect_key($notification, \array_flip([
            'MerchantID', 'TerminalID', 'OrderID', 'Currency', 'TotalAmount', 'XID', 'PurchaseTime', 'Delay',
        ]));
        $response['Response.action'] = $approve ? 'approve' : 'reverse';
        $response['Response.reason'] = $approve ? 'OK' : 'Payment details mismatch';
        $response['Response.forwardUrl'] = $forwardUrl;
        $lines = [];
        foreach ($response as $name => $value) {
            $lines[] = $name . '=' . $value;
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    public function getStatus(array $details): array
    {
        $fields = $this->paymentFields($details);
        $header = \base64_encode('{"alg":"RS256"}');
        $payload = \base64_encode(\json_encode($fields, \JSON_THROW_ON_ERROR));
        $url = $this->baseUrl . '/go/service/01';
        $response = $this->post($url, \json_encode([
            'header' => $header,
            'payload' => $payload,
            'signature' => $this->sign($header . '.' . $payload, \OPENSSL_ALGO_SHA256),
        ], \JSON_THROW_ON_ERROR), 'application/json');
        $content = (string) $response->getBody();

        try {
            $envelope = \json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
            if (!\is_array($envelope)) {
                throw new LogicException('Missing signed envelope');
            }
            $encodedHeader = self::field($envelope, 'header', 4096);
            $encodedPayload = self::field($envelope, 'payload', 1000000);
            $signature = self::decodeBase64(self::field($envelope, 'signature', 2048));
            $responseHeader = \json_decode(self::decodeBase64($encodedHeader), true, 32, \JSON_THROW_ON_ERROR);
            $algorithm = match ($responseHeader['alg'] ?? null) {
                'RS256' => \OPENSSL_ALGO_SHA256,
                'RS512' => \OPENSSL_ALGO_SHA512,
                default => throw new LogicException('Unsupported status signature algorithm'),
            };
            if (1 !== \openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $this->gatewayKey, $algorithm)) {
                throw new LogicException('Invalid status signature');
            }
            $payload = \json_decode(self::decodeBase64($encodedPayload), true, 32, \JSON_THROW_ON_ERROR);
            if (!\is_array($payload) || !isset($payload['results']) || !\is_array($payload['results']) || !\array_is_list($payload['results'])) {
                throw new LogicException('Missing status results');
            }

            return $this->statusSnapshot($payload['results'], $fields);
        } catch (\JsonException|LogicException $e) {
            throw $this->failure($e->getMessage(), $url, (string) $response->getStatusCode(), $content, $e);
        }
    }

    /** @param array<string, mixed> $details */
    public function refund(array $details): void
    {
        $fields = $this->paymentFields($details);
        $fields['ApprovalCode'] = self::field($details, 'ApprovalCode', 6);
        $fields['RRN'] = self::field($details, 'RRN', 12);
        // Repayment verifies an empty SD slot even when the original checkout used SD.
        $data = $this->purchaseSignatureData($fields + ['SD' => '']) . $fields['ApprovalCode'] . ';' . $fields['RRN'] . ';';
        if (isset($details['RefundAmount'])) {
            throw new LogicException('Only full UPC refunds are supported.');
        }
        if (isset($details['Ref3']) && '' !== $details['Ref3']) {
            $fields['Ref3'] = self::field($details, 'Ref3', 150);
            $data .= $fields['Ref3'] . ';';
        }
        $fields['Signature'] = $this->sign($data, \OPENSSL_ALGO_SHA512);
        $url = $this->baseUrl . '/go/repayment';
        $response = $this->post($url, \http_build_query($fields, '', '&', \PHP_QUERY_RFC3986), 'application/x-www-form-urlencoded');
        $httpCode = (string) $response->getStatusCode();
        $content = (string) $response->getBody();
        $result = [];
        if (\str_starts_with(\ltrim($content), '<')) {
            $document = new \DOMDocument();
            if (!@$document->loadHTML($content, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING)) {
                throw $this->failure('Invalid refund response', $url, $httpCode, $content);
            }
            foreach ($document->getElementsByTagName('input') as $input) {
                $name = $input->getAttribute('name');
                if (isset($result[$name])) {
                    throw $this->failure('Duplicate refund response field', $url, $httpCode, $content);
                }
                $result[$name] = $input->getAttribute('value');
            }
        } else {
            // The live endpoint also returns one name=value field per line.
            foreach (\preg_split('/\r\n|\r|\n/', \trim($content)) ?: [] as $line) {
                if ('' === \trim($line)) {
                    continue;
                }
                $field = \explode('=', $line, 2);
                if (2 !== \count($field) || !\preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $field[0])) {
                    throw $this->failure('Invalid refund response', $url, $httpCode, $content);
                }
                if (isset($result[$field[0]])) {
                    throw $this->failure('Duplicate refund response field', $url, $httpCode, $content);
                }
                $result[$field[0]] = $field[1];
            }
        }
        $matchesTerminal = ($result['MerchantID'] ?? null) === $this->merchantId && ($result['TerminalID'] ?? null) === $this->terminalId;
        if (!$matchesTerminal || '000' !== ($result['TranCode'] ?? null) || !empty($result['ERROR'])) {
            $reason = 'Refund rejected or invalid response';
            if (\preg_match('/^[0-9]{3}$/D', $result['TranCode'] ?? '')) {
                $reason .= ' (UPC TranCode=' . $result['TranCode'] . ')';
            }
            throw $this->failure($reason, $url, $httpCode, $content, tranCode: $matchesTerminal && '200' === $httpCode ? ($result['TranCode'] ?? null) : null);
        }
    }

    public static function statusForCode(string $code): string
    {
        return match ($code) {
            '000' => self::STATUS_CAPTURED,
            '408', '410', '601' => self::STATUS_PENDING,
            '411' => self::STATUS_EXPIRED,
            '503', '504' => self::STATUS_CANCELED,
            // A declined card attempt does not close the UPC checkout session.
            // Unknown tells the host to preserve its state and accept a later retry.
            default => self::STATUS_UNKNOWN,
        };
    }

    /** @param list<mixed> $results
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function statusSnapshot(array $results, array $fields): array
    {
        $unknown = ['upc_status' => self::STATUS_UNKNOWN, 'status_results' => $results];
        $count = \count($results);
        $captured = null;
        foreach ($results as $result) {
            if (!\is_array($result) || 'PURCHASE' !== ($result['operType'] ?? null)) {
                return $unknown;
            }
            if (self::field($result, 'amount', 12) !== $fields['TotalAmount'] || self::field($result, 'currency', 3) !== $fields['Currency']) {
                throw new LogicException('Status amount or currency mismatch');
            }
            $code = self::field($result, 'tranCode', 3);
            if ('000' === $code) {
                if ('000' !== ($result['actionCode'] ?? null) || null !== $captured) {
                    return $unknown;
                }
                $captured = $result;
            } elseif ($count > 1 && \in_array($code, ['411', '503', '504'], true)) {
                // A mixed history with expiry/cancellation needs reconciliation;
                // neither array order nor timestamps establish its final outcome.
                return $unknown;
            }
        }

        // UPC retains declined attempts under the same OrderID. A single confirmed
        // purchase among them is authoritative, regardless of the history order.
        $result = $captured ?? (1 === $count ? $results[0] : null);
        if (null === $result) {
            return $unknown;
        }
        $code = self::field($result, 'tranCode', 3);
        $status = self::statusForCode($code);
        $snapshot = ['upc_status' => $status, 'TranCode' => $code, 'status_results' => $results];
        if (self::STATUS_CAPTURED === $status) {
            $snapshot['ApprovalCode'] = self::field($result, 'approvalCode', 6);
            $snapshot['RRN'] = self::field($result, 'rrn', 12);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $details
     * @return array<string, string>
     */
    private function paymentFields(array $details): array
    {
        foreach (['MerchantID' => $this->merchantId, 'TerminalID' => $this->terminalId] as $name => $expected) {
            if (isset($details[$name]) && $expected !== self::field($details, $name, 15)) {
                throw new LogicException('UPC merchant or terminal mismatch.');
            }
            $details[$name] = $expected;
        }
        $fields = [];
        foreach (['MerchantID' => 15, 'TerminalID' => 8, 'PurchaseTime' => 17, 'OrderID' => 20, 'Currency' => 3, 'TotalAmount' => 12] as $name => $length) {
            $fields[$name] = self::field($details, $name, $length);
        }
        if (!\preg_match('/^\d{12}(?:[+-]\d{4})?$/D', $fields['PurchaseTime'])
            || !\preg_match('/^\d{3}$/D', $fields['Currency'])
            || !\preg_match('/^[1-9]\d{0,11}$/D', $fields['TotalAmount'])) {
            throw new LogicException('Invalid UPC purchase time, currency or amount.');
        }

        return $fields;
    }

    /** @param array<string, string> $fields */
    private function purchaseSignatureData(array $fields, bool $notification = false): string
    {
        $parts = [$fields['MerchantID'], $fields['TerminalID'], $fields['PurchaseTime'], $fields['OrderID']];
        if (isset($fields['Delay'])) {
            $parts[3] .= ',' . $fields['Delay'];
        }
        if ($notification) {
            $parts[] = $fields['XID'];
        }
        $parts[] = $fields['Currency'] . (isset($fields['AltCurrency']) ? ',' . $fields['AltCurrency'] : '');
        $parts[] = $fields['TotalAmount'] . (isset($fields['AltTotalAmount']) ? ',' . $fields['AltTotalAmount'] : '');
        $parts[] = $fields['SD'];
        if ($notification) {
            $parts[] = $fields['TranCode'];
            $parts[] = $fields['ApprovalCode'];
        }

        return \implode(';', $parts) . ';';
    }

    private function sign(string $data, int $algorithm): string
    {
        if (!\openssl_sign($data, $signature, $this->privateKey, $algorithm)) {
            throw new LogicException('Unable to sign UPC request.');
        }

        return \base64_encode($signature);
    }

    private function post(string $url, string $body, string $contentType): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streamFactory->createStream($body));
        try {
            $response = $this->client->send($request);
        } catch (\Http\Client\Exception $e) {
            throw $this->failure('UPC transport failure', $url, '0', '', $e);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw $this->failure('UPC HTTP failure', $url, (string) $response->getStatusCode(), (string) $response->getBody());
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    private static function field(array $data, string $name, int $maxLength, bool $allowEmpty = false): string
    {
        $value = $data[$name] ?? null;
        if ((!\is_string($value) && !\is_int($value)) || (!$allowEmpty && '' === (string) $value)
            || \mb_strlen((string) $value) > $maxLength || \preg_match('/[;,\x00-\x1f\x7f]/', (string) $value)) {
            throw new LogicException(\sprintf('Invalid UPC field "%s".', $name));
        }

        return (string) $value;
    }

    private static function decodeBase64(string $value): string
    {
        $decoded = \base64_decode(\strtr($value, '-_', '+/'), true);
        if (false === $decoded || '' === $decoded) {
            throw new LogicException('Invalid UPC base64 data.');
        }

        return $decoded;
    }

    private function failure(string $reason, string $url, string $code, string $content, ?\Throwable $previous = null, ?string $tranCode = null): UpcApiException
    {
        return new UpcApiException(\sprintf('%s for "%s" with code "%s". Content: %s', $reason, $url, $code, \mb_substr($content, 0, 500)), $tranCode, $previous);
    }
}
