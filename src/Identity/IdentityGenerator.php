<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;

/**
 * Computes stable UIR identities (design §2).
 *
 * Format: `<kind>:<algoVersion>:<hash>` where `<hash>` is the first 16 characters (~80 bits)
 * of the base32 (lowercase, no padding) encoding of the full SHA-256 of a canonical input
 * tuple — matching the worked-example id lengths and the schema's `nodeId` pattern (§2). The
 * document id is the sole exception: `doc:<configKey>` verbatim, so the human-readable config
 * key remains legible.
 *
 * Identity inputs never include file paths, line numbers or array positions.
 *
 * @internal
 */
final readonly class IdentityGenerator
{
    public const string ALGO_VERSION = 'v1';

    private const int HASH_CHARS = 16;

    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {}

    public function documentId(string $configKey): string
    {
        return 'doc:'.$configKey;
    }

    public function operationId(string $documentId, string $method, string $pathTemplate): string
    {
        return $this->id('op', [
            $documentId,
            strtoupper($method),
            $this->normalizePathTemplate($pathTemplate),
        ]);
    }

    public function parameterId(string $operationId, string $in, string $name): string
    {
        return $this->id('par', [$operationId, $in, $name]);
    }

    /**
     * @param  list<string>  $typeArguments
     */
    public function namedSchemaId(string $fqcn, array $typeArguments = []): string
    {
        return $this->id('sch', [$fqcn, ...$typeArguments]);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function inlineSchemaId(array $schema): string
    {
        $structural = $this->canonicalizer->canonicalizeSchemaForIdentity($schema);

        return $this->id('sch', ['inline', $this->serializer->serialize($structural)]);
    }

    public function responseId(string $operationId, string $status, string $mediaType): string
    {
        return $this->id('res', [$operationId, $status, $mediaType]);
    }

    public function pageId(string $slug): string
    {
        return $this->id('page', [$slug]);
    }

    /**
     * The positionally-normalised path template: each `{param}` replaced by `{p<index>}`
     * left-to-right, so renaming a path parameter never breaks operation identity.
     */
    public function normalizePathTemplate(string $template): string
    {
        $index = 0;

        $normalized = preg_replace_callback(
            '/\{[^}]*}/',
            static function () use (&$index): string {
                return '{p'.$index++.'}';
            },
            $template,
        );

        return $normalized ?? $template;
    }

    /**
     * @param  list<string>  $tuple
     */
    private function id(string $kind, array $tuple): string
    {
        $json = json_encode($tuple, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = Base32::encode(hash('sha256', $json, binary: true));

        return $kind.':'.self::ALGO_VERSION.':'.substr($hash, 0, self::HASH_CHARS);
    }
}
