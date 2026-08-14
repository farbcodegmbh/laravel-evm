<?php

namespace Farbcode\LaravelEvm;

use Farbcode\LaravelEvm\Clients\ContractClientGeneric;
use Farbcode\LaravelEvm\Clients\RpcHttpClient;
use Farbcode\LaravelEvm\Codec\AbiCodecWeb3p;
use Farbcode\LaravelEvm\Commands\EvmGenerateAddressCommand;
use Farbcode\LaravelEvm\Contracts\AbiCodec;
use Farbcode\LaravelEvm\Contracts\ContractClient;
use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Contracts\TransactionStore;
use Farbcode\LaravelEvm\Crypto\LocalNonceManager;
use Farbcode\LaravelEvm\Crypto\PrivateKeySigner;
use Farbcode\LaravelEvm\Support\EloquentTransactionStore;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;
use Farbcode\LaravelEvm\Support\NullTransactionStore;
use Farbcode\LaravelEvm\Support\SimpleFeePolicy;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
            ->hasMigration('create_evm_transactions_table')
            ->hasCommands([
                EvmGenerateAddressCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // No environment guard here. register() runs on every request and every
        // artisan command, so throwing would take the whole application down
        // over a feature it may never use. GMP is checked in Support\Requirements
        // where the big integer math actually happens.
        $this->app->singleton(RpcClient::class, fn () => new RpcHttpClient(
            config('evm.rpc_urls'),
            (int) config('evm.chain_id', 137),
            [
                'timeout' => (int) config('evm.rpc.timeout', 10),
                'connect_timeout' => (int) config('evm.rpc.connect_timeout', 3),
                'tries' => (int) config('evm.rpc.tries', 2),
            ]
        ));

        $this->app->singleton(Signer::class, function () {
            $driver = config('evm.signer.driver', 'private_key');
            if ($driver === 'private_key') {
                return new PrivateKeySigner(config('evm.signer.private_key'));
            }
            throw new \RuntimeException('Signer driver not implemented');
        });

        $this->app->singleton(TransactionStore::class, fn () => config('evm.tracking.enabled', false)
            ? new EloquentTransactionStore
            : new NullTransactionStore);

        $this->app->singleton(NonceManager::class, fn () => new LocalNonceManager);
        $this->app->singleton(FeePolicy::class, fn () => new SimpleFeePolicy(config('evm.fees')));
        $this->app->singleton(AbiCodec::class, fn () => new AbiCodecWeb3p);

        // bind, not singleton: a contract handle carries per-contract state, so
        // sharing one instance across a request or an Octane worker is unsafe.
        $this->app->bind(ContractClient::class, function (Application $app) {
            return new ContractClientGeneric(
                $app->make(RpcClient::class),
                $app->make(Signer::class),
                $app->make(AbiCodec::class),
                (int) config('evm.chain_id', 137),
                [
                    'estimate_padding' => (float) config('evm.tx.estimate_padding', 1.2),
                    'gas_floor' => (int) config('evm.tx.gas_floor', 21000),
                    'confirm_timeout' => (int) config('evm.tx.confirm_timeout', 120),
                    'max_replacements' => (int) config('evm.tx.max_replacements', 2),
                    'poll_interval_ms' => (int) config('evm.tx.poll_interval_ms', 800),
                    'queue' => (string) config('evm.tx.queue', 'evm-send'),
                ]
            );
        });

        $this->app->bind(LogFilterBuilder::class, function ($app) {
            return LogFilterBuilder::make($app->make(RpcClient::class));
        });
    }
}
