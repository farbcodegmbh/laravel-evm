# CONTRIBUTING

Contributions are welcome, and are accepted via pull requests.
Please review these guidelines before submitting any pull requests.

## Process

1. Fork the project
1. Create a new branch
1. Code, test, commit and push
1. Open a pull request detailing your changes.

## Guidelines

* Please ensure the coding style running `composer lint`.
* Send a coherent commit history, making sure each individual commit in your pull request is meaningful.
* You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
* Please remember that we follow [SemVer](http://semver.org/).

## 1. Package Development

### Setup

Clone your fork, then install the dev dependencies:
```bash
composer install
```


Build workbench:
```bash
composer build
```

### Playground

You can explore and test the package using the built-in [Workbench](https://github.com/orchestral/workbench) environment.

Start the local test application:
```bash
composer serve
```
Then run the included test commands:

```bash
vendor/bin/testbench evmtest:test
vendor/bin/testbench evmtest:integrity
```

These commands let you experiment with core read and write directly from your terminal.

### Lint

Lint your code:
```bash
composer lint
```
### Tests

Run all tests:
```bash
composer test
```

## 2. Documentation

The documentation is built using [Vitepress](https://vitepress.dev/).

To run the site locally, run:
```bash
cd docs
npm i
npm run dev
```
