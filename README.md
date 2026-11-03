# UPC e-Commerce Connect for Payum

Standalone Payum gateway for the UPC hosted payment page, including acquiring
through Raiffeisen Bank. PHP 8.2+, Payum Core 1.7, OpenSSL and a PSR-17 implementation.

## Status

Besides the offline test suite, the gateway was checked against the UPC sandbox:

- an approved purchase, confirmed by a signed Notify and a signed status response;
- an issuer decline (`105`) followed by a successful retry on UPC's payment page
  under the same OrderID;
- a new checkout opened from the store after a decline, and payment in an earlier
  checkout that was still open after a second one had been started; in both cases
  only the paid attempt became captured;
- a payment completed after the customer closed the bank page, confirmed by
  periodic Sync without a browser return;
- replayed success and stale decline notifications for a captured payment, which
  left it unchanged;
- a payment completed after the business order had been canceled, recorded once;
- an unavailable gateway (HTTP 520), which neither reposted the checkout nor
  created another attempt;
- refund signing: a correctly signed request returned `455` (refund API disabled
  for the terminal), a request with a corrupted signature returned `405`.

Not yet verified: a successful full refund on a terminal with refund API access,
and UPC's response to a checkout signed with an unregistered merchant certificate.

## Supported requests

| Payum request | Behavior |
|---|---|
| `Convert` | Converts a payment to numeric ISO 4217 currency, integer minor units and a unique 20-character UPC order ID. |
| `Capture` | Signs and posts the browser to `/go/pay`. Reloading the same capture link checks the existing attempt. A fresh capture link can request a separate attempt after Sync. |
| `Notify` | Verifies RSA-SHA512, resolves the payment through signed `SD`, checks the original transaction fields and returns the UPC acknowledgement. |
| `Sync` | Sends an RSA-SHA256 signed JSON request to `/go/service/01`, verifies the response and updates the payment snapshot. |
| `GetHumanStatus` | Maps the stored snapshot to Payum new/pending/captured/failed/expired/canceled/refunded/unknown. Attempt declines preserve the host state through unknown. |
| `Refund` | Synchronizes the purchase first, then requests a full refund through `/go/repayment`. UPC must enable this API for the terminal. |
| `Cancel` | No bank operation for single-stage checkout. Leaves the payment available to Notify/Sync even if the host cancels the business order. Confirmed purchases require `Refund`. |

Preauthorization, settlement or reversal of holds, partial refunds, recurring
payments and card tokenization are not implemented. `Delay=1` and `RefundAmount`
are rejected explicitly. Status history may contain several declined `PURCHASE`
attempts and one confirmed purchase with matching amount and currency. Unknown
operation types, multiple successful purchases, and mixed cancellation/expiry
histories map to `unknown`; the gateway never treats a successful refund/hold as a purchase.
Automatic reconciliation of operations performed outside this gateway awaits the
complete UPC status-history contract.

## Configuration

Register `SolParts\PayumUpc\UpcGatewayFactory` with the host's Payum builder:

```php
use Payum\Core\GatewayFactoryInterface;
use SolParts\PayumUpc\UpcGatewayFactory;

$builder->addGatewayFactory('upc', static fn (array $config, GatewayFactoryInterface $core)
    => new UpcGatewayFactory($config, $core));
$builder->addGateway('upc_test', [
    'factory' => 'upc',
    'sandbox' => true,
    'merchant_id' => $merchantId,
    'terminal_id' => $terminalId,
    'merchant_private_key' => $merchantPrivateKeyPem,
    'gateway_certificate' => $upcGatewayCertificatePem,
]);
```

Supply PEM **contents**, not paths. `merchant_private_key` belongs to the merchant;
`gateway_certificate` is UPC's public verification certificate for the selected
environment, not the merchant certificate sent for registration. Test and production
certificates differ. Keys and certificates are never copied into payment details.

`sandbox` defaults to `true` (`https://ecg.test.upc.ua`); `false` selects
`https://secure.upc.ua`. Credentials, tokens and payment storage must be isolated
between environments. Configure the HTTP client to verify TLS, reject cross-origin
redirects and avoid automatic retries of refund POSTs. Optional
`payum.http_message_factory` and `payum.http_stream_factory` accept PSR-17 factories;
otherwise the gateway uses discovery.

## Notify and browser return

1. Configure UPC's terminal `NOTIFY_URL` to the host's fixed callback for this
   gateway. It must dispatch `Notify(null)` through that gateway and return Payum's
   `HttpResponse` reply verbatim, without HTML. PayumBundle's gateway-specific
   unsafe notify route can be used; UPC signatures provide message authentication.
2. Enable Payum token storage, payment storage and the generic token factory.
   Checkout creates a notify token and sends its hash in signed `SD`. Keep these
   tokens while callbacks can still arrive. `SD` is reserved by this gateway.
3. The capture token's `afterUrl` becomes `Response.forwardUrl`. It must be an
   absolute HTTP(S) URL of at most 255 bytes. Configure terminal success/failure
   fallback URLs in UPC as well. Browser-return parameters are never trusted as
   proof of payment.
4. Execute `Sync` at the return endpoint and periodically for pending payments,
   including payments whose browser never returns. A valid `TranCode=000` Notify
   receives `approve` but remains **pending** until a signed status response confirms
   completion: UPC still has to receive the acknowledgement and finalize the purchase.
5. A valid matching Notify is acknowledged even when the host canceled the order
   or created another payment attempt. Local business state cannot invalidate bank
   evidence. The host must reconcile late payments, including locally failed,
   canceled or expired attempts, and record confirmed funds without reopening the
   canceled order. Refunds remain a separate operation.

Invalid signatures receive HTTP 400 before token lookup. Authenticated messages
for a different amount, currency, order, purchase time or token receive `reverse`
without modifying the payment. Repeated notifications cannot undo a confirmed
capture or refund. Unauthenticated Notify fields, including `Rrn`, are discarded;
refund identifiers come from signed status responses.

The host must serialize operations on the same payment and persist details even
when Payum throws a reply or an exception (the standard storage extension does this).
The package does not introduce a callback queue, scheduler or locking backend.

## Payment details and retries

`Convert` preserves a supplied `OrderID`; otherwise it generates a random 20-character
identifier for each payment attempt. A custom ID must be unique per attempt and
at most 20 characters; do not reuse a business order number across payment attempts.
`TotalAmount` is an integer number of minor units, `Currency` a three-digit ISO code.
Optional `locale` is `uk` (default) or `en`; `PurchaseDesc` is limited to 125 characters.
`Ref3` requires UPC activation. Purchase time is generated in UTC with an explicit offset.

The original signed fields are persisted before redirect. An initialized payment
is never posted again. Repeated `Capture` synchronizes its status. Reloading
the same capture link cannot start another checkout. A status error propagates
without requesting another attempt; an empty history does not close the old one.

Issuer declines, insufficient funds, invalid card details and authentication errors
do not prove that the checkout session is closed. They map to `unknown`, keeping the
host payment pending so the customer can retry on UPC's payment page under the same
OrderID and SD. The original response code remains available in details. Only an
explicit terminal result ends the payment: `411` means expired, `503`/`504` canceled.
An unknown code never becomes failed merely because it is not `000`.

Keep the original payment and Notify token if the host creates a separate attempt:
authenticated late notifications must still be processed. Local cancellation or
replacement never sends an automatic reversal. If separate attempts both complete,
record both payments so the merchant can resolve the overpayment.

To retry from the store, issue a fresh capture link with its own after token.
For an initialized payment, a capture token without an after URL only synchronizes
the existing attempt.
After Sync, the gateway can reply with `SolParts\PayumUpc\Reply\NewPaymentRequired`.
It carries the capture token and defaults to an HTTP 303 redirect to its after URL.
Handle this reply after Payum has persisted the old model. Reconcile existing
attempts, then create a separate payment with fresh details and capture/notify
tokens. Serialize that action and reject a repeated submission
referring to an older attempt. A new checkout must not overwrite the old payment's
OrderID or SD. Do not open another payment if reconciliation fails or discovers
that the order has already been paid.

A completed full refund is idempotent locally. The gateway sets
`upc_refund_requested` before sending the request. If the terminal returns
HTTP 200 with its matching merchant and terminal IDs and code `405` (invalid
signature) or `455` (refund API disabled), the gateway reports the rejection
and clears the flag. After correcting the configuration, the merchant can
request the refund again. A timeout, malformed response or unclassified error
keeps the flag set. Reconcile those cases in UPC before any further submission.
A successful refund sets `upc_status=refunded`. A later Sync preserves that state.
The gateway does not infer a completed refund from mixed operation history alone.

If a successful Notify is followed only by a decline and an expired link
(for example, `105` and `411`), the payment remains pending. If subsequent
signed status checks do not resolve it, verify the purchase in UPC before
asking the customer to pay again.

## Verification

```sh
composer install
vendor/bin/phpunit
```

Tests generate separate merchant and gateway RSA keys in memory. They cover exact
signature bytes, tampered callbacks, token routing, persisted state, signed status
validation, duplicate callbacks and ambiguous refund responses. No real merchant
credentials or network requests are used.

### Connecting a merchant terminal

1. Have UPC register the merchant's public certificate for the terminal. Configure
   the merchant and terminal IDs, matching merchant private key, and UPC verification
   certificate for that environment. Select single-stage card payments.
2. Set the terminal's `NOTIFY_URL` to the store's public callback. With PayumBundle
   the route is `/payment/notify/unsafe/{gateway}` on the store's HTTPS domain.
   Use the gateway name configured for that terminal. The endpoint must accept UPC
   requests without a storefront login. Check the terminal's browser return URLs too.
3. Run a small agreed purchase on the merchant terminal. Match the order, amount
   and currency to the signed success Notify and subsequent signed `PURCHASE / 000`
   status. Verify that the store shows the order as paid and records one accounting
   entry for that amount.
4. If the merchant will use API refunds, verify a full refund of that purchase and
   its accounting entry on a terminal with refund access before enabling automatic
   refunds. The development sandbox returned `455` for this operation.

## Protocol sources

- [Hosted authorization](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644402)
- [Signature construction](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644443)
- [Signature examples](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49645993)
- [Notify](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644411)
- [Response codes](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644420)
- [Signed JSON status](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644423)
- [Refunds](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644430)
- [Preauthorization](https://docsecom.atlassian.net/wiki/spaces/DOCUK/pages/49644454)

The current signature examples explicitly use SHA512 for checkout/Notify; the
JSON status request uses RS256 and the documented response uses RS512. Older UPC
SDK examples default to SHA1. This gateway does not silently downgrade verification;
confirm the terminal's algorithm during sandbox acceptance.
