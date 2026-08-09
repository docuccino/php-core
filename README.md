# docuccino/core

> **This repository is a read-only subtree split** of [docuccino/docuccino](https://github.com/docuccino/docuccino).
> Open issues and pull requests on the monorepo — commits pushed here are overwritten.

The framework-agnostic heart of [Docuccino](https://docuccino.app): the UIR
document model, canonicalizer, identity/hashing, JSON-Schema validator and the
OpenAPI / UIR emitters. It has no framework dependency and is consumed by the
framework adapters (e.g. `docuccino/laravel`).

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

## Documentation

Full documentation is at <https://docs.docuccino.app>. See especially the
[UIR format overview](https://docs.docuccino.app/uir/) and
[spec hosting](https://docs.docuccino.app/uir/hosting/); the versioned UIR JSON Schema is served at
<https://spec.docuccino.app/uir/1.0/schema.json>.

## License

MIT. See [LICENSE](LICENSE).
