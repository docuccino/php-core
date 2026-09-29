<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

/**
 * One object in a rule set whose members are partitioned by the value of one of them — the tag — as a
 * recovery proved it: which members each tag value keeps, and which of those the request must send. The
 * body publishes one component per value, discriminated by the tag, instead of one object carrying every
 * member ({@see TaggedBranches}). What the rules must say for a partition to be proved is the recovering
 * adapter's vocabulary, not core's.
 *
 * A member named by no branch and not in {@see $gated} is on every branch, as the merged object states it.
 *
 * @phpstan-type TaggedBranch array{value: string, name: string, members: list<string>, required: list<string>}
 */
final readonly class TaggedVariants
{
    /**
     * @param  string  $path  the object's field path, in rule-key notation; `''` for the body itself
     * @param  string  $tag  the member whose value selects a branch
     * @param  list<string>  $gated  the members only some values keep
     * @param  list<TaggedBranch>  $branches  one per value the tag accepts: the gated members that value
     *                                        keeps, the members it requires beyond those the merged object
     *                                        requires, and an identifier-safe word naming the branch
     * @param  bool  $admitsEmpty  whether the empty object is accepted too, carrying no tag at all
     */
    public function __construct(
        public string $path,
        public string $tag,
        public array $gated,
        public array $branches,
        public bool $admitsEmpty = false,
    ) {}

    /** Whether a rule key is one the partition is read off — the tag or a gated member — so losing it loses the proof. */
    public function names(string $key): bool
    {
        [$owner, $member] = self::ownerAndMember($key);

        return $owner === $this->path && ($member === $this->tag || in_array($member, $this->gated, true));
    }

    /** Whether a rule key is a member of the object, whose rules the recovery may have rewritten for the branches. */
    public function owns(string $key): bool
    {
        return self::ownerAndMember($key)[0] === $this->path;
    }

    /**
     * A rule key as the object holding it and its own name; `''` holds the body's members.
     *
     * @return array{string, string}
     */
    public static function ownerAndMember(string $key): array
    {
        $position = strrpos($key, '.');

        return $position === false ? ['', $key] : [substr($key, 0, $position), substr($key, $position + 1)];
    }
}
