<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * What a {@see Change} is about, as members rather than as the text its `path` opens with: the operation a
 * parameter, response or schema position is on, or the component, page or path item it changes whole. A
 * path template, a webhook name and a component name can each hold a dot or a space, so no split of the
 * path recovers them — this is what to group a changeset's changes by.
 *
 * An operation is named by the method a client sends, as its `path` names it, so a custom `GET` beside the
 * fixed `get` — which OpenAPI forbids — shares the fixed one's subject as it shares its request line.
 */
final readonly class ChangeSubject
{
    private function __construct(
        public SubjectKind $kind,
        public ?string $method = null,
        public ?string $path = null,
        public ?string $webhook = null,
        public ?string $name = null,
        public ?string $slug = null,
    ) {}

    /** An operation under `paths`: the method a client sends, and the path template. */
    public static function operation(string $method, string $path): self
    {
        return new self(SubjectKind::Operation, method: $method, path: $path);
    }

    /** An operation under `webhooks`: the method the API sends, and the webhook's name. */
    public static function webhookOperation(string $method, string $webhook): self
    {
        return new self(SubjectKind::Operation, method: $method, webhook: $webhook);
    }

    /** A path item as a whole, where nothing under it could be compared. */
    public static function pathItem(string $path): self
    {
        return new self(SubjectKind::PathItem, path: $path);
    }

    /** A webhook's path item as a whole, where nothing under it could be compared. */
    public static function webhookPathItem(string $webhook): self
    {
        return new self(SubjectKind::PathItem, webhook: $webhook);
    }

    /** A schema under `components.schemas`, by its name there. */
    public static function schema(string $name): self
    {
        return new self(SubjectKind::Schema, name: $name);
    }

    /** A scheme under `components.securitySchemes`, by its name there. */
    public static function securityScheme(string $name): self
    {
        return new self(SubjectKind::SecurityScheme, name: $name);
    }

    /** A content page, by its slug. */
    public static function page(string $slug): self
    {
        return new self(SubjectKind::Page, slug: $slug);
    }

    /**
     * The kind, then only the members it has: `method` with `path` or `webhook` for an operation, `path` or
     * `webhook` for a path item, `name` for a schema or security scheme, `slug` for a page.
     *
     * @return array{kind: string, method?: string, path?: string, webhook?: string, name?: string, slug?: string}
     */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind->value];

        if ($this->method !== null) {
            $out['method'] = $this->method;
        }

        if ($this->path !== null) {
            $out['path'] = $this->path;
        }

        if ($this->webhook !== null) {
            $out['webhook'] = $this->webhook;
        }

        if ($this->name !== null) {
            $out['name'] = $this->name;
        }

        if ($this->slug !== null) {
            $out['slug'] = $this->slug;
        }

        return $out;
    }
}
