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

    // JSON-RPC transport.
    'rpc' => [
        'timeout' => env('EVM_RPC_TIMEOUT', 10),          // seconds per request
        'connect_timeout' => env('EVM_RPC_CONNECT_TIMEOUT', 3),
        'tries' => env('EVM_RPC_TRIES', 2),               // transport retries per endpoint
    ],

    // Log querying.
    'logs' => [
        'max_chunk' => env('EVM_LOGS_MAX_CHUNK', 5000),   // blocks per eth_getLogs chunk
    ],

    // Signer configuration.
    'signer' => [
        'driver' => env('EVM_SIGNER', 'private_key'),
        'private_key' => env('EVM_PRIVATE_KEY'),
        // 'kms_key' => env('EVM_KMS_KEY_ARN'),
    ],

    // Fee policy for EIP 1559.
    // Fees are derived from the chain: maxFee = baseFee * base_multiplier + tip,
    // where the tip comes from eth_maxPriorityFeePerGas. The gwei values below
    // are floors, not the primary source - raise them for a chain that needs a
    // minimum tip to be picked up (Polygon typically wants 25-30).
    'fees' => [
        'min_priority_gwei' => env('EVM_MIN_PRIORITY_GWEI', 1),
        'min_maxfee_gwei' => env('EVM_MIN_MAXFEE_GWEI', 0),
        'base_multiplier' => env('EVM_BASE_MULTIPLIER', 2),
        'replacement_factor' => env('EVM_REPLACEMENT_FACTOR', 1.5),
    ],

    // Transaction behavior.
    'tx' => [
        'estimate_padding' => env('EVM_ESTIMATE_PADDING', 1.2),
        'confirm_timeout' => env('EVM_CONFIRM_TIMEOUT', 120), // seconds
        'max_replacements' => env('EVM_MAX_REPLACEMENTS', 2),
        'poll_interval_ms' => env('EVM_POLL_INTERVAL_MS', 800),
        'queue' => env('EVM_QUEUE', 'evm-send'), // Keep concurrency=1 per signer
    ],
];
