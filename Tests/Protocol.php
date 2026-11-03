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

/** Independent wire fixtures; all key material is generated in memory. */
final class Protocol
{
    /** @var array<string, string> */
    private static array $keys = [];

    public static function privateKey(string $owner): string
    {
        if (!isset(self::$keys[$owner])) {
            $key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            \openssl_pkey_export($key, $pem);
            self::$keys[$owner] = $pem;
        }

        return self::$keys[$owner];
    }

    public static function publicKey(string $owner): string
    {
        return \openssl_pkey_get_details(\openssl_pkey_get_private(self::privateKey($owner)))['key'];
    }

    /** @return array<string, mixed> */
    public static function options(): array
    {
        return [
            'merchant_id' => '1234567', 'terminal_id' => 'E1234567',
            'merchant_private_key' => self::privateKey('merchant'),
            'gateway_certificate' => self::publicKey('gateway'),
        ];
    }

    /** @return array<string, string> */
    public static function payment(): array
    {
        return [
            'MerchantID' => '1234567', 'TerminalID' => 'E1234567',
            'PurchaseTime' => '261102120000', 'OrderID' => 'attempt-1',
            'Currency' => '980', 'TotalAmount' => '12550', 'SD' => 'notify-test',
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function notification(array $overrides = []): array
    {
        $fields = \array_replace(self::payment(), [
            'XID' => '26110212-123456', 'TranCode' => '000', 'ApprovalCode' => '123456',
            'Rrn' => 'untrusted', 'ProxyPan' => 'untrusted',
        ], $overrides);
        $order = $fields['OrderID'] . (isset($fields['Delay']) ? ',' . $fields['Delay'] : '');
        $currency = $fields['Currency'] . (isset($fields['AltCurrency']) ? ',' . $fields['AltCurrency'] : '');
        $amount = $fields['TotalAmount'] . (isset($fields['AltTotalAmount']) ? ',' . $fields['AltTotalAmount'] : '');
        $data = "{$fields['MerchantID']};{$fields['TerminalID']};{$fields['PurchaseTime']};$order;{$fields['XID']};$currency;$amount;{$fields['SD']};{$fields['TranCode']};{$fields['ApprovalCode']};";
        \openssl_sign($data, $signature, self::privateKey('gateway'), \OPENSSL_ALGO_SHA512);
        $fields['Signature'] = \base64_encode($signature);

        return $fields;
    }

    /** @return array<string, mixed> */
    public static function purchaseResult(): array
    {
        return [
            'tranCode' => '000', 'approvalCode' => '123456', 'rrn' => '601411039294',
            'amount' => 12550, 'currency' => '980', 'operType' => 'PURCHASE', 'actionCode' => '000',
        ];
    }

    /** @return array<string, mixed> */
    public static function declinedResult(string $code = '105'): array
    {
        $result = \array_replace(self::purchaseResult(), ['tranCode' => $code, 'actionCode' => $code, 'rrn' => '601411039293']);
        unset($result['approvalCode']);

        return $result;
    }

    /** @param list<array<string, mixed>>|null $results */
    public static function status(?array $results = null, string $alg = 'RS512'): string
    {
        $encode = static fn (string $value): string => \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
        $header = $encode(\json_encode(['alg' => $alg], \JSON_THROW_ON_ERROR));
        $payload = $encode(\json_encode(['results' => $results ?? [self::purchaseResult()]], \JSON_THROW_ON_ERROR));
        \openssl_sign($header . '.' . $payload, $signature, self::privateKey('gateway'), 'RS256' === $alg ? \OPENSSL_ALGO_SHA256 : \OPENSSL_ALGO_SHA512);

        return \json_encode(['header' => $header, 'payload' => $payload, 'signature' => $encode($signature)], \JSON_THROW_ON_ERROR);
    }

    public static function refundResponse(string $code = '000'): string
    {
        return '<html><body><form><input name="MerchantID" value="1234567"><input name="TerminalID" value="E1234567"><input name="TranCode" value="' . $code . '"></form></body></html>';
    }

    public static function refundTextResponse(string $code = '000'): string
    {
        return "TranCode=$code\r\nTerminalID=E1234567\r\nMerchantID=1234567\r\n";
    }
}
