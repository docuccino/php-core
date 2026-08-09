<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * Renders a {@see Changeset} as deterministic, plain-text terminal output: a one-line summary,
 * then breaking changes grouped ahead of non-breaking ones (the {@see Changeset} is already
 * sorted breaking-first). No colour, no timestamps — safe to snapshot in tests and to pipe into
 * a CI log or a PR comment.
 */
final class ChangesetRenderer
{
    private const string MARK_ADDED = '+';

    private const string MARK_REMOVED = '-';

    private const string MARK_CHANGED = '~';

    public function render(Changeset $changeset): string
    {
        if ($changeset->isEmpty()) {
            return "No API changes.\n";
        }

        $breaking = $changeset->breakingChanges();
        $nonBreaking = $changeset->nonBreakingChanges();

        $lines = [$this->summaryLine($changeset)];

        if ($breaking !== []) {
            $lines[] = '';
            $lines[] = 'BREAKING';
            foreach ($breaking as $change) {
                $lines[] = $this->line($change);
            }
        }

        if ($nonBreaking !== []) {
            $lines[] = '';
            $lines[] = 'NON-BREAKING';
            foreach ($nonBreaking as $change) {
                $lines[] = $this->line($change);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function summaryLine(Changeset $changeset): string
    {
        $total = count($changeset->changes);
        $breaking = count($changeset->breakingChanges());

        return sprintf(
            '%d change%s (%d breaking)',
            $total,
            $total === 1 ? '' : 's',
            $breaking,
        );
    }

    private function line(Change $change): string
    {
        $mark = match ($change->kind) {
            ChangeKind::Added => self::MARK_ADDED,
            ChangeKind::Removed => self::MARK_REMOVED,
            ChangeKind::Changed => self::MARK_CHANGED,
        };

        $line = sprintf('  %s [%s] %s  (%s)', $mark, $change->target->value, $change->path, $change->code);

        foreach ($change->fields as $field) {
            $line .= sprintf("\n      %s: %s -> %s", $field->field, self::scalar($field->old), self::scalar($field->new));
        }

        return $line;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '(none)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? gettype($value) : $encoded;
    }
}
