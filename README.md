# docuccino/core

[![Latest version](https://img.shields.io/packagist/v/docuccino/core?label=packagist)](https://packagist.org/packages/docuccino/core)
[![Downloads](https://img.shields.io/packagist/dt/docuccino/core)](https://packagist.org/packages/docuccino/core)
[![PHP version](https://img.shields.io/packagist/dependency-v/docuccino/core/php)](https://packagist.org/packages/docuccino/core)
[![CI](https://github.com/docuccino/docuccino/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/docuccino/docuccino/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/docuccino/core)](LICENSE)

**The framework-agnostic engine behind [Docuccino](https://docuccino.app)** — the document model,
canonicalizer, identities, OpenAPI emitters and semantic diff.

Core compiles an application into a **UIR**: a Universal Intermediate Representation, an OpenAPI
3.2-shaped JSON document that is deterministic and carries a stable identity and provenance for every
operation, schema and parameter. From it, core emits OpenAPI 3.2 and 3.1, validates against the
published JSON Schema, and answers *what changed* between two documents rather than only *what the
endpoints are*.

It has no framework dependency and no static-analysis dependency. Framework adapters consume it —
if you are documenting a Laravel application, you want **[`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel)**,
which pulls this in for you.

## Install

```bash
composer require docuccino/core
```

## Usage

Emit a canonical OpenAPI 3.2 document from a built UIR document:

```php
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;

$json = (new OpenApi32Emitter())->emit($uirDocument, new EmitOptions());
```

Compare two documents over their identities, and classify what moved:

```php
use Docuccino\Core\Diff\DocumentDiffer;

$changes = (new DocumentDiffer())->diff($oldDocument, $newDocument);
```

## Part of Docuccino

| Package | Role |
| --- | --- |
| [`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel) | The Laravel adapter: provider, config, commands, viewer, integrations. **Start here.** |
| **`docuccino/core`** ← you are here | Framework-agnostic document model, canonicalizer, identities, emitters, diff. |
| [`docuccino/inference-phpstan`](https://packagist.org/packages/docuccino/inference-phpstan) | PHPStan + Larastan type inference. Install as a **dev** dependency. |
| [`docuccino/attributes`](https://packagist.org/packages/docuccino/attributes) | Dependency-free PHP attribute classes. |

## Documentation

Full documentation is at **[docs.docuccino.app](https://docs.docuccino.app)**. See especially the
[UIR format overview](https://docs.docuccino.app/uir/),
[spec hosting](https://docs.docuccino.app/uir/hosting/) and
[writing an extension](https://docs.docuccino.app/extending/extension-authoring/). The versioned UIR
JSON Schema is served at <https://spec.docuccino.app/uir/1.0/schema.json>.

## Issues and contributing

**This repository is a read-only subtree split** of
[docuccino/docuccino](https://github.com/docuccino/docuccino). Open issues and pull requests on the
monorepo — commits pushed here are overwritten. See
[CONTRIBUTING.md](https://github.com/docuccino/docuccino/blob/main/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
