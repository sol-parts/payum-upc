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

use Http\Discovery\Psr17FactoryDiscovery;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use SolParts\PayumUpc\Action\CancelAction;
use SolParts\PayumUpc\Action\CaptureAction;
use SolParts\PayumUpc\Action\ConvertPaymentAction;
use SolParts\PayumUpc\Action\NotifyAction;
use SolParts\PayumUpc\Action\NotifyNullAction;
use SolParts\PayumUpc\Action\RefundAction;
use SolParts\PayumUpc\Action\StatusAction;
use SolParts\PayumUpc\Action\SyncAction;

final class UpcGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'upc',
            'payum.factory_title' => 'UPC e-Commerce Connect',
            'payum.action.capture' => new CaptureAction(),
            'payum.action.cancel' => new CancelAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.notify_null' => new NotifyNullAction(),
            'payum.action.notify' => new NotifyAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.sync' => new SyncAction(),
        ]);
        if (!$config['payum.api']) {
            $config['payum.default_options'] = ['sandbox' => true];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = ['merchant_id', 'terminal_id', 'merchant_private_key', 'gateway_certificate'];
            $config['payum.api'] = static function (ArrayObject $config): Api {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Api(
                    (array) $config,
                    $config['payum.http_client'],
                    $config['payum.http_message_factory'] ?? Psr17FactoryDiscovery::findRequestFactory(),
                    $config['payum.http_stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory(),
                );
            };
        }
    }
}
