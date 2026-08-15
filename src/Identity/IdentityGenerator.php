<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Support\Json;

/**
 * Computes stable UIR identities. Format: `<kind>:<algoVersion>:<hash>`, where `<hash>` is the first
 * 16 characters (~80 bits) of the lowercase, unpadded base32 of a canonical input tuple's SHA-256 —
 * which is what the schema's `nodeId` pattern expects. The document id is the one exception:
 * `doc:<configKey>` verbatim, so the config key stays legible.
 *
 * Identity inputs never include file paths, line numbers or array positions. Spec:
 * docs/design/uir-and-extensions.md §2.
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

    /**
     * Two routes on one method and path but different hosts are two operations, so the host is part of
     * the identity — but only when there is one, so a route that answers on every host is identified by
     * method and path alone. A templated host normalises its `{param}` segments the same way a path does.
     */
    public function operationId(string $documentId, string $method, string $pathTemplate, ?string $host = null): string
    {
        $tuple = [
            $documentId,
            strtoupper($method),
            $this->normalizePathTemplate($pathTemplate),
        ];

        if ($host !== null && $host !== '') {
            $tuple[] = $this->normalizePathTemplate($host);
        }

        return $this->id('op', $tuple);
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

    /**
     * The identity of a schema published verbatim under a component name of its own, where the exact
     * bytes are the thing being published: `$scope` is whatever else distinguishes this publication from
     * another carrying the same bytes.
     *
     * {@see inlineSchemaId()} cannot serve here. It normalises annotations and `required` order away so
     * an inline schema keeps its identity across a cosmetic edit — which is right for an inline schema
     * and wrong for a component, because two components that differ at all are two published nodes and
     * a differ that pairs components by id would otherwise see only one of them.
     *
     * @param  array<string, mixed>  $schema
     */
    public function publishedSchemaId(string $scope, array $schema): string
    {
        return $this->id('sch', [$scope, Json::stable($schema)]);
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
     * Replaces each `{param}` with `{p<index>}` left to right, so renaming a path parameter never
     * breaks operation identity.
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
