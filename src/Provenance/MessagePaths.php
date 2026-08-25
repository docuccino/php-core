<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Relativises the machine paths inside a fragment of diagnostic text — a third-party exception's
 * message, or a locator naming the file something anonymous was written in — so a diagnostic built
 * from it can be published. Diagnostics are embedded in the document, so a diagnostic that names the
 * build machine breaks byte-identical output where it is hardest to notice; every absolute run this
 * finds goes through {@see SourcePathResolver}, so the ladder and its degradation are the ones
 * {@see RootRelativeSourcePathResolver} already owns and there is no second notion of a publishable
 * path.
 *
 * Most of what reaches it is a FINISHED message somebody else wrote — an analyser's internal error, a
 * YAML parser quoting the line it choked on, a validator naming an unresolvable `$ref` — so the rule
 * cannot be "compose around the result". Syntax does not say where a run came from: a route signature
 * (`GET /api/forms/{form}`), a JSON pointer (`#/components/schemas/User/properties/password`) and a
 * `regex:` rule are absolute-looking runs too, identical on every machine and worth exactly the
 * characters they are written with. So the two directions are ranked: a run that leaks is a
 * determinism defect, a run that is reduced wrongly is the product stating something the application
 * does not say — and the second is the one that must be impossible. Four things get us there:
 *
 * 1. **Exclusions.** A run behind a `#` is a URI fragment, a `/` behind a `\` is an escape, a POSIX
 *    run carrying a backslash is a regex or a JSON string, a run carrying a brace is a URI template.
 *    None of those reaches the ladder at all.
 * 2. **Proof.** A local stream wrapper (`phar://`), a Windows drive and a UNC share cannot be
 *    anything but a filesystem path, so those are always reduced.
 * 3. **Attribution.** Where the ladder recognised a root — the base path, or a `composer.json`
 *    ancestor — the answer is a prefix strip, and a prefix strip cannot invent text. Asking whether
 *    it recognised one takes {@see PROBE}: the answer alone cannot say, since a root one segment up
 *    leaves the same bare name a root it never found leaves.
 * 4. **File shape.** A run the ladder could not attribute is reduced only when its last segment
 *    names a file (`Reader.php`). `/api/forms` and `/docs/reference/configuration` keep every
 *    character they had, because nothing here established they were ever paths. How far such a run
 *    reaches through a space is {@see pathRun()}.
 *
 * Machine words that no path grammar reaches — the `include_path='…'` tail PHP appends to a failed
 * include, a temp directory — are redacted literally afterwards, by the prefixes this process can
 * name for itself.
 */
final readonly class MessagePaths
{
    /**
     * A path body: anything but the punctuation that delimits a path in prose. An interior space is
     * allowed because `$HOME` ordinarily contains one on macOS and Windows; which spaces a reduction may
     * then cross is decided by {@see pathRun()}, not by the matcher.
     *
     * A colon delimits a path far more often than it sits inside one — `X.php:18`, `regex:/…/`, the
     * `include_path='.:/…'` list — so it is excluded, EXCEPT where more path follows it before the next
     * delimiter. A colon is a legal character in a POSIX directory name (a timestamped cache directory
     * spells one), and a run cut in front of the colon no longer ends in a filename, which is the only
     * thing reason 4 has left to go on: `/home/alice/a:b/Reader.php` was published whole while
     * `/home/alice/ab/Reader.php` reduced to its name. The lookahead admits a colon of its own, so a
     * `10:30:00` segment is crossed rather than stopping at the second one.
     */
    private const BODY = '(?:[^\\s\'"(),;:<>]| (?=\\S)|:(?=[^\\s\'"(),;<>]*/))';

    /**
     * Two segments appended to a path to ask the ladder something its answer alone cannot say: did it
     * recognise a root? It strips a root it recognised and otherwise answers a bare name, so both
     * segments survive a recognised root and only the last survives no root at all. One segment cannot
     * tell those apart — a root that IS the path leaves exactly the one segment a bare name leaves.
     */
    private const PROBE = 'docuccino/probe';

    /** One literal backslash, as the pattern spells it. */
    private const BS = '\\\\';

    /**
     * Stream wrappers that name a file on THIS machine, so a run bearing one is a path by proof
     * rather than by shape. A URL scheme names a host instead and is not here; `php://` and `data://`
     * name no file at all.
     */
    private const LOCAL_WRAPPERS = ['file', 'phar', 'zip', 'compress.zlib', 'compress.bzip2'];

    /**
     * What introduces a route signature rather than a file. `/api/forms` is already left alone for
     * having no filename, but `/api/users.json` has one, and a format suffix is an ordinary way to
     * spell a route.
     */
    private const METHODS = ['GET ', 'PUT ', 'HEAD ', 'POST ', 'PATCH ', 'TRACE ', 'QUERY ', 'DELETE ', 'OPTIONS '];

    /** The pattern, assembled once — the alternatives are ordered proof-first so a wrapper wins. */
    private string $run;

    /** @var list<string> Absolute prefixes this process can prove name the machine it is running on. */
    private array $machineRoots;

    private ClassNames $classNames;

    public function __construct(private SourcePathResolver $paths)
    {
        $this->run = self::pattern();
        $this->machineRoots = self::machineRoots();
        $this->classNames = new ClassNames($paths);
    }

    public function relative(string $message): string
    {
        return $this->redact($this->scrub($this->classNames->inText($message)));
    }

    /** The run pass. Recurses on the tail of a match, which is always strictly shorter. */
    private function scrub(string $text): string
    {
        return preg_replace_callback(
            $this->run,
            fn (array $match): string => $this->rewrite($match[0]),
            $text,
        ) ?? $text;
    }

    private function rewrite(string $match): string
    {
        $run = rtrim($match);
        $trailing = substr($match, strlen($run));

        if (! self::couldBeAPath($run)) {
            return $match;
        }

        foreach (self::candidates($run) as $candidate) {
            $attributed = $this->attributed($candidate);

            if ($attributed !== null) {
                return $attributed.$this->scrub(substr($run, strlen($candidate)).$trailing);
            }
        }

        $unattributed = self::pathRun($run);
        $reduced = self::proven($unattributed) || self::namesAFile($unattributed)
            ? $this->resolve($unattributed)
            : $unattributed;

        return $reduced.$this->scrub(substr($run, strlen($unattributed)).$trailing);
    }

    /**
     * The exclusions. A brace makes a run a URI template rather than a file anyone named, and a
     * backslash inside a POSIX run makes it an escaped string — a `regex:` rule, a JSON pointer in a
     * quoted message — not a path with a separator.
     */
    private static function couldBeAPath(string $run): bool
    {
        if (str_contains($run, '{') || str_contains($run, '}')) {
            return false;
        }

        return self::proven($run) || ! str_contains($run, '\\');
    }

    /** A wrapper scheme, a Windows drive or a UNC share — shapes nothing but a path has. */
    private static function proven(string $run): bool
    {
        if (str_starts_with($run, '\\\\')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $run) === 1) {
            return true;
        }

        return self::wrapper($run) !== null;
    }

    /** The wrapper scheme a run carries, if it is one we can prove names a local file. */
    private static function wrapper(string $run): ?string
    {
        foreach (self::LOCAL_WRAPPERS as $scheme) {
            if (str_starts_with($run, $scheme.'://')) {
                return $scheme;
            }
        }

        return null;
    }

    /** Whether the run's last segment names a file, which is the only thing left that says "path". */
    private static function namesAFile(string $run): bool
    {
        return preg_match('#\\.[A-Za-z0-9_]{1,16}$#', basename(str_replace('\\', '/', $run))) === 1;
    }

    /**
     * The run cut at each of its interior spaces, shortest first. The first candidate the ladder
     * attributes wins, so attribution swallows a space only where a root actually accounts for it —
     * `/Users/tm artin/checkout/app/X.php on line 3` gives up `on line 3` and keeps the path.
     *
     * @return non-empty-list<string>
     */
    private static function candidates(string $run): array
    {
        $candidates = [];
        $offset = 0;

        while (($space = strpos($run, ' ', $offset)) !== false) {
            $candidates[] = substr($run, 0, $space);
            $offset = $space + 1;
        }

        $candidates[] = $run;

        return $candidates;
    }

    /**
     * How much of a run a reduction may cover once NO root accounted for it: the run cut at the first
     * space that is not inside a directory segment. A spaced directory (`/Users/ca rol/Library/…`) puts
     * its space between two separators with text against both; a sentence carrying on after a path puts
     * its first space where no separator follows at all (`/docs/reference/configuration for the key`),
     * and a second path in the same sentence puts one right against the next separator. Only the first
     * shape may be crossed, so proof and file shape see a whole spaced path and never a sentence.
     */
    private static function pathRun(string $run): string
    {
        $offset = 1;

        while (($space = strpos($run, ' ', $offset)) !== false) {
            if (strpos($run, '/', $space) === false || $run[$space - 1] === '/' || $run[$space + 1] === '/') {
                return substr($run, 0, $space);
            }

            $offset = $space + 1;
        }

        return $run;
    }

    /** The relative form, but only where the ladder recognised a root. */
    private function attributed(string $run): ?string
    {
        return $this->stripped(self::pathPart($run)) === null ? null : $this->resolve($run);
    }

    /**
     * The ladder, with the wrapper put back. A phar keeps its interior path verbatim — that half is
     * inside the archive and identical wherever the archive sits — so only the archive relativises,
     * which is what turns an analyser's own `phar:///opt/…/phpstan.phar/src/X.php` into something the
     * document may carry.
     */
    private function resolve(string $run): string
    {
        $scheme = self::wrapper($run);

        if ($scheme === null) {
            return $this->relativise($run);
        }

        $path = substr($run, strlen($scheme) + 3);

        if ($scheme === 'phar' && ($boundary = self::pharBoundary($path)) !== null) {
            return $scheme.'://'.$this->relativise(substr($path, 0, $boundary)).substr($path, $boundary);
        }

        return $scheme.'://'.$this->relativise($path);
    }

    /**
     * The ladder's answer, taken from the probe wherever it recognised a root: what it leaves in front of
     * the probe segments IS the prefix strip. That is also the only way to relativise a run that IS the
     * root, where {@see SourcePathResolver::relative()} has nothing left to answer with but the name of
     * the directory the checkout happens to sit in — a different string on every machine.
     */
    private function relativise(string $path): string
    {
        return $this->stripped($path) ?? $this->paths->relative($path);
    }

    /** The path under the root the ladder recognised, or null where it recognised none. */
    private function stripped(string $path): ?string
    {
        $answer = $this->paths->relative(rtrim(str_replace('\\', '/', $path), '/').'/'.self::PROBE);

        if ($answer === self::PROBE) {
            return '';
        }

        return str_ends_with($answer, '/'.self::PROBE)
            ? substr($answer, 0, -strlen('/'.self::PROBE))
            : null;
    }

    /** Where the archive ends and the path inside it begins, or null when the run names no archive. */
    private static function pharBoundary(string $path): ?int
    {
        $at = strrpos(str_replace('\\', '/', $path), '.phar/');

        return $at === false ? null : $at + 5;
    }

    /** The filesystem half of a run — the same string, less any wrapper scheme. */
    private static function pathPart(string $run): string
    {
        $scheme = self::wrapper($run);

        return $scheme === null ? $run : substr($run, strlen($scheme) + 3);
    }

    /**
     * The machine words no path grammar reaches. PHP appends `include_path='.:/opt/…'` to every
     * failed include, and that tail spells the machine's PHP prefix and patch version; a temp
     * directory is the same kind of fact. Both are prefixes this process can name for itself, so they
     * are redacted literally — no matching, and so nothing to mistake for an author's text.
     */
    private function redact(string $message): string
    {
        foreach ($this->machineRoots as $root) {
            $message = str_replace($root.'/', '', $message);

            // A bare root, not a prefix of anything: only where it is deep enough that no sentence of
            // ours could be spelling it, so `/tmp` in prose survives and an install prefix does not.
            // TWO segments is that line, not three: PHP's failed-include tail names every entry BARE,
            // so a three-segment threshold published a two-segment prefix (`/opt/php`) whole while
            // hiding a deeper one — the same code emitting different bytes for the machine it ran on.
            if (substr_count($root, '/') >= 2) {
                $message = str_replace($root, '', $message);
            }
        }

        return $message;
    }

    /** @return list<string> longest first, so a nested root cannot leave the outer one behind */
    private static function machineRoots(): array
    {
        $roots = [sys_get_temp_dir()];

        foreach (explode(PATH_SEPARATOR, (string) ini_get('include_path')) as $entry) {
            $roots[] = $entry;
        }

        $absolute = [];

        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', trim($root)), '/');

            if ($root !== '' && str_starts_with($root, '/') && str_contains(substr($root, 1), '/')) {
                $absolute[$root] = strlen($root);
            }
        }

        arsort($absolute);

        return array_keys($absolute);
    }

    private static function pattern(): string
    {
        $body = self::BODY;
        $segments = '(?:'.$body.'*/)*'.$body.'*';
        $windows = '(?:'.$body.'*[/'.self::BS.'])*'.$body.'*';
        $schemes = implode('|', array_map(
            static fn (string $scheme): string => str_replace('.', '\\.', $scheme),
            self::LOCAL_WRAPPERS,
        ));

        return '%'
            // A local stream wrapper: proof, whatever follows it.
            .'(?<![\\w:/])(?:'.$schemes.')://'.$segments
            // A UNC share: two backslashes, a host and at least one more segment.
            .'|'.self::BS.self::BS.'[^\\s'.self::BS.'/]+'.self::BS.$windows
            // A Windows drive. The forward-slash form needs a boundary so `http://` cannot pose as
            // one; the backslash form needs none, since nothing else is spelled `X:\`.
            .'|(?:(?<![\\w:])[A-Za-z]:[/'.self::BS.']|(?<!:)[A-Za-z]:'.self::BS.')'.$windows
            // A POSIX absolute run, which needs an interior separator: a lone `/tmp` in a sentence is
            // prose. Not behind a word character (a namespace, a URL's host), a `:` or a `/` (a URL's
            // scheme), a `\` (an escape), a `#` (a URI fragment), a `~` (already home-relative, so
            // already portable) or an HTTP method (a route signature — nothing introduces a FILE with
            // one, and a YAML parser quoting the line it choked on hands us whole ones).
            .'|(?<!'.implode(')(?<!', self::METHODS).')(?<![\\w:/#~'.self::BS.'])/(?:'.$body.'*/)+'.$body.'*'
            .'%';
    }
}
