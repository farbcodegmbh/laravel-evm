---
layout: home
hero:
  name: Laravel EVM
  text: Reliable EVM interaction for Laravel (contracts, async transactions, fees, nonces, multi-RPC)
  tagline: Production-focused toolkit for Ethereum & EVM chains
  actions:
    - theme: brand
      text: Get Started
      link: /pages/README
    - theme: alt
      text: Architecture
      link: /pages/architecture
features:
  - title: ⚙️ Async Transactions
    details: Queue-based EIP-1559 lifecycle with fee replacement & events.
  - title: 🛰️ Multi-RPC Failover
    details: Round-robin fallback with robust logging.
  - title: 🔐 Safe Nonce Management
    details: Prevents collisions via local tracking & guidelines.
  - title: 🧪 Test Friendly
    details: Facade singletons and replaceable strategies for unit tests.
---

# Laravel EVM

Welcome to the documentation site. Use the sidebar to explore guides.

Quick links:
- [Overview & Quick Start](/pages/README)
- [Architecture](/pages/architecture)
- [Configuration](/pages/configuration)
- [Transactions](/pages/transactions)
- [Events](/pages/events)
- [Facades](/pages/facades)

## Installation
```bash
composer require farbcodegmbh/laravel-evm
```

## Next Steps
Configure RPC endpoints and private key in your `.env`, then start the queue worker:
```bash
php artisan queue:work --queue=evm-send
```

## Contributing
Issues & PRs welcome on GitHub.
