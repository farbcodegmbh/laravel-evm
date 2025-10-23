import { defineConfig } from 'vitepress';

export default defineConfig({
  title: 'Laravel EVM',
  description: 'Reliable EVM interaction for Laravel (contracts, async transactions, fees, nonces, multi-RPC)',
  srcDir: '.',
  outDir: '../build/docs',
  cleanUrls: true,
  lastUpdated: true,
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/pages/README' },
      { text: 'Architecture', link: '/pages/architecture' },
      { text: 'Configuration', link: '/pages/configuration' },
      { text: 'Transactions', link: '/pages/transactions' },
      { text: 'Events', link: '/pages/events' },
  { text: 'Facades', link: '/pages/facades' },
  { text: 'Examples', link: '/pages/examples' }
    ],
    sidebar: {
      '/pages/': [
        {
          text: 'Overview',
          items: [
            { text: 'Introduction', link: '/pages/README#introduction' },
            { text: 'Quick Start', link: '/pages/README#quick-start' },
            { text: 'Core Concepts', link: '/pages/README#core-concepts' }
          ]
        },
        {
          text: 'Architecture',
          items: [
            { text: 'Component Diagram', link: '/pages/architecture#component-diagram' },
            { text: 'Transaction Lifecycle', link: '/pages/architecture#transaction-job-lifecycle' },
            { text: 'Events', link: '/pages/events' }
          ]
        },
        {
          text: 'Runtime',
          items: [
            { text: 'Configuration', link: '/pages/configuration' },
            { text: 'Facades', link: '/pages/facades' },
            { text: 'Transactions', link: '/pages/transactions' },
            { text: 'Events', link: '/pages/events' },
            { text: 'Examples', link: '/pages/examples' }
          ]
        },
        {
          text: 'Advanced',
          items: [
            { text: 'Extensibility Points', link: '/pages/architecture#extensibility-points' },
            { text: 'Concurrency Model', link: '/pages/architecture#concurrency-model' },
            { text: 'Security Considerations', link: '/pages/architecture#security-considerations' }
          ]
        }
      ]
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/farbcodegmbh/laravel-evm' }
    ],
    footer: {
      message: 'MIT Licensed',
      copyright: 'Copyright © 2025 Farbcode GmbH'
    }
  }
});
