<?php

// src/Events/TxReplaced.php

namespace Farbcode\LaravelEvm\Events;

class TxReplaced
{
    /**
     * @param  string  $oldTxHash  Hash der vorherigen (ersetzten) Transaktion
     * @param  array  $newFields  Neue Felder inklusive angehobener Fees
     * @param  int  $attempt  Laufende Ersatz-Versuchsnummer (1-basiert)
     */
    public function __construct(public string $oldTxHash, public array $newFields, public int $attempt) {}
}
