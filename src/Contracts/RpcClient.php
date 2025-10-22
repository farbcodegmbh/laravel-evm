<?php

// src/Contracts/RpcClient.php

namespace Farbcode\LaravelEvm\Contracts;

interface RpcClient
{
    /**
     * Perform an RPC call and return the unwrapped result. Implementationen dürfen
     * sowohl Hex-Strings (z.B. "0xabc123") als auch Arrays (Receipts etc.) zurückgeben.
     */
    public function call(string $method, array $params = []): mixed;

    public function callRaw(string $method, array $params = []): array;

    public function health(): array;
}
