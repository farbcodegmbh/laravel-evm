<?php

// config for Farbcode/LaravelEvm
return [
    'chain_id' => env('EVM_CHAIN_ID', 137),

    // Multiple RPC urls supported. Client uses round robin with fallback.
    'rpc_urls' => array_values(array_filter([
        env('EVM_RPC_1'),
        env('EVM_RPC_2'),
        env('EVM_RPC_3'),
    ])),

    // Signer configuration.
    'signer' => [
        'driver' => env('EVM_SIGNER', 'private_key'),
        'private_key' => env('EVM_PRIVATE_KEY'),
        // 'kms_key' => env('EVM_KMS_KEY_ARN'),
    ],

    // Fee policy for EIP 1559.
    'fees' => [
        'min_priority_gwei' => env('EVM_MIN_PRIORITY_GWEI', 3),
        'min_maxfee_gwei'   => env('EVM_MIN_MAXFEE_GWEI', 40),
        'base_multiplier'   => env('EVM_BASE_MULTIPLIER', 3),
        'replacement_factor'=> env('EVM_REPLACEMENT_FACTOR', 1.5),
    ],

    // Transaction behavior.
    'tx' => [
        'estimate_padding' => env('EVM_ESTIMATE_PADDING', 1.2),
        'confirm_timeout'  => env('EVM_CONFIRM_TIMEOUT', 120), // seconds
        'max_replacements' => env('EVM_MAX_REPLACEMENTS', 2),
        'poll_interval_ms' => env('EVM_POLL_INTERVAL_MS', 800),

        // Queue used for sending jobs. Keep concurrency at one per writer address.
        'queue' => env('EVM_QUEUE', 'evm-send'),
    ],
];
