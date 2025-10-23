<?php

namespace Farbcode\LaravelEvm;

use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Farbcode\LaravelEvm\Clients\ContractClientGeneric;
use Farbcode\LaravelEvm\Clients\RpcHttpClient;
use Farbcode\LaravelEvm\Codec\AbiCodecWeb3p;
use Farbcode\LaravelEvm\Contracts\AbiCodec;
use Farbcode\LaravelEvm\Contracts\ContractClient;
use Farbcode\LaravelEvm\Contracts\TxBuilder;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Crypto\LocalNonceManager;
use Farbcode\LaravelEvm\Crypto\PrivateKeySigner;
use Farbcode\LaravelEvm\Crypto\TxBuilderEip1559;
use Farbcode\LaravelEvm\Support\SimpleFeePolicy;
use Farbcode\LaravelEvm\Commands\EvmCallCommand;
use Farbcode\LaravelEvm\Commands\EvmSendCommand;
use Farbcode\LaravelEvm\Commands\EvmWaitCommand;
use Farbcode\LaravelEvm\Commands\EvmHealthCommand;
use Farbcode\LaravelEvm\Commands\EvmGenerateAddressCommand;

class LaravelEvmServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-evm')
            ->hasConfigFile()
            ->hasCommands([
                EvmCallCommand::class,
                EvmSendCommand::class,
                EvmWaitCommand::class,
                EvmHealthCommand::class,
                EvmGenerateAddressCommand::class,
            ]);
    }

    public function packageRegistered()
    {
        $this->app->singleton(RpcClient::class, fn() => new RpcHttpClient(
            config('evm.rpc_urls'),
            (int) config('evm.chain_id', 137)
        ));

        $this->app->singleton(Signer::class, function () {
            $driver = config('evm.signer.driver', 'private_key');
            if ($driver === 'private_key') {
                return new PrivateKeySigner(config('evm.signer.private_key'));
            }
            throw new \RuntimeException('Signer driver not implemented');
        });

        $this->app->singleton(NonceManager::class, fn() => new LocalNonceManager);
        $this->app->singleton(FeePolicy::class, fn() => new SimpleFeePolicy(config('evm.fees')));
        $this->app->singleton(TxBuilder::class, fn() => new TxBuilderEip1559);
        $this->app->singleton(AbiCodec::class, fn() => new AbiCodecWeb3p);

        $this->app->singleton(ContractClient::class, function (Application $app) {
            return new ContractClientGeneric(
                $app->make(RpcClient::class),
                $app->make(Signer::class),
                $app->make(AbiCodec::class),
                (int) config('evm.chain_id', 137),
                [
                    'estimate_padding' => (float) config('evm.tx.estimate_padding', 1.2),
                    'confirm_timeout'  => (int) config('evm.tx.confirm_timeout', 120),
                    'max_replacements' => (int) config('evm.tx.max_replacements', 2),
                    'poll_interval_ms' => (int) config('evm.tx.poll_interval_ms', 800),
                    'queue' => (string) config('evm.tx.queue', 'evm-send'),
                ]
            );
        });
    }
}
