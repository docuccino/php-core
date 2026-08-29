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
 *    None of those reaches the ladder at all, unless proof opened the run or a root already accounts
 *    for the text in front of the character objected to. What they refuse is the PATH RUN and not
 *    the sentence it sits in: a match crosses an interior space, so one routinely spans a template
 *    AND the file named after it, and refusing all of it published the file ({@see rewrite()}).
 * 2. **Proof.** A local stream wrapper (`phar://`), a Windows drive and a UNC share cannot be
 *    anything but a filesystem path, so those are always reduced. All three are proof from the first
 *    character, and nothing a template is spelled with opens that way — a route signature, a path
 *    template and a JSON pointer all start at a `/` or a `#` — so a run that OPENS with one is a path
 *    even where it also carries a brace, and the braces are a shell glob rather than a placeholder.
 *    What a wrapper proves stops at the first character of its tail, though — the compression
 *    wrappers filter another STREAM, so `compress.zlib://http://…` names a host. {@see WRAPPERS}
 *    holds that decision.
 * 3. **Attribution.** Where the ladder recognised a root — the base path, or a `composer.json`
 *    ancestor — the answer is a prefix strip, and a prefix strip cannot invent text. Asking whether
 *    it recognised one takes {@see PROBE}: the answer alone cannot say, since a root one segment up
 *    leaves the same bare name a root it never found leaves. The root also has to be deep enough to
 *    be a machine word ({@see deeplyRooted()}), which is the same question asked one segment EARLY,
 *    of the text an exclusion objects to, where it is the only thing that admits a run no proof
 *    opened ({@see anchored()}) — all a bare `/Users/…/{a,b}/*.php` has to be reduced by.
 * 4. **File shape.** A run the ladder could not attribute is reduced only when its last segment
 *    names a file (`Reader.php`). `/api/forms` and `/docs/reference/configuration` keep every
 *    character they had, because nothing here established they were ever paths. How far such a run
 *    reaches through a space is {@see pathRun()}. A braced run reaches this rung only behind proof:
 *    shape cannot tell `/api/users/{user}.json` from a file, so a braced POSIX run no root accounted
 *    for is published whole — a leak, taken knowingly, because the other direction is the one that
 *    must be impossible. A run under a root too shallow for reason 3 arrives here too, and so keeps
 *    its strip wherever shape says it was a path at all: {@see relativise()} goes back to the same
 *    ladder, so the second signal costs a shallow-rooted file nothing.
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
     * A URL scheme and its separator, as RFC 3986 spells the scheme. It is what tells a wrapper's
     * tail apart from a path, and two readers spell it the same way. The PATTERN is the one that
     * decides: declining to open a run on a nested URL is what leaves `compress.zlib://http://…`
     * whole, so no run the matcher produces reaches {@see couldBeAPath()} carrying one. {@see
     * wrapper()} asks again anyway, because it is the fold's own reader and is called on strings the
     * matcher never produced — a candidate cut at a space, a run handed straight to {@see resolve()}
     * — and a second reader that read fewer shapes than the pattern would be a hole rather than a
     * conservative default.
     */
    private const NESTED_SCHEME = '[A-Za-z][A-Za-z0-9+.\\-]*://';

    /**
     * Every stream wrapper a decision has been taken about, and whether it is proof: true says a run
     * bearing it can name nothing but a file on THIS machine, so the run is a path by proof rather
     * than by shape. False is the decision that it is not one, and it is the half that has to be
     * taken by hand — an over-scrub is the direction that must be impossible — so what the reduction
     * reads is this table and never what the machine happens to have loaded. `stream_get_wrappers()`
     * is the source of truth for what is REGISTERED, and it is read by the guard in the tests, which
     * fails when a registered scheme is decided in neither direction.
     *
     * @var array<string, bool>
     */
    private const array WRAPPERS = [
        // Proof: nothing but a file on this machine. A glob names a filesystem pattern and nothing
        // else, so the absolute prefix in front of its wildcard is the machine word every other path
        // here carries.
        'file' => true,
        'phar' => true,
        'zip' => true,
        'glob' => true,
        // Proof of the LOCAL form only. These two filter another STREAM rather than naming a file,
        // so where the four above take a path they take a URL: `compress.zlib://http://host/x.gz`
        // reads a HOST, and reducing it would state an address the application never wrote. So proof
        // stops at the first character of the tail — a tail that is itself a scheme
        // ({@see NESTED_SCHEME}) is not a path, and the run is left whole, which is what a bare
        // `http://` URL already gets. A nest is left whole for the same reason rather than unwrapped:
        // it may still end at a host, and PHP opens no nest anyway (the inner stream must be castable
        // to a descriptor). Every proof scheme reads the narrowing, because one rule is cheaper than
        // an exception and the four above lose nothing by it.
        //
        // Demoting the pair to false is the alternative and is worse, not safer: the pattern would
        // stop opening on them and the POSIX branch cannot pick the path up behind `zlib://`, whose
        // `/` sits against another `/` — so `compress.zlib:///home/alice/cache.gz` would leak whole.
        'compress.zlib' => true,
        'compress.bzip2' => true,
        // A host, not a file: reducing one states an address the application never wrote.
        'http' => false,
        'https' => false,
        'ftp' => false,
        'ftps' => false,
        // No file at all — a stream of the process's own, a message's own bytes, and a field on an
        // open database connection.
        'php' => false,
        'data' => false,
        'sqlsrv' => false,
    ];

    /**
     * What introduces a route signature rather than a file. `/api/forms` is already left alone for
     * having no filename, but `/api/users.json` has one, and a format suffix is an ordinary way to
     * spell a route. The methods the document itself carries are the source of truth, so the test
     * derives its rows from there rather than spelling them again: a method missing here reduces a
     * signature to its last segment, which is the direction that must be impossible.
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
        // Bounded again after the class-name pass, which is the one thing here that can make the text
        // longer than it arrived: what the run pass reads is what {@see PublishableText} allows.
        return $this->redact($this->scrub(PublishableText::bounded($this->classNames->inText($message))));
    }

    /** The run pass. Recurses on the tail of a match, which is always strictly shorter. */
    private function scrub(string $text): string
    {
        return PublishableText::orRefused(preg_replace_callback(
            $this->run,
            fn (array $match): string => $this->rewrite($match[0]),
            $text,
        ));
    }

    private function rewrite(string $match): string
    {
        $run = rtrim($match);
        $trailing = substr($match, strlen($run));

        if (! $this->couldBeAPath($run)) {
            // An exclusion refuses a PATH RUN, not the sentence around it. {@see pathRun()} is where
            // one ends, so the refused text keeps every character and what follows goes back through
            // the same pass — and since that answer is never empty, the recursion still shortens.
            $refused = self::pathRun($run);

            return $refused.$this->scrub(substr($run, strlen($refused)).$trailing);
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
     * The exclusions, and the two things that open ahead of them. A brace makes a run a URI template
     * rather than a file anyone named, and a backslash inside a POSIX run makes it an escaped string —
     * a `regex:` rule, a JSON pointer in a quoted message — not a path with a separator.
     *
     * Proof outranks both, whichever shape carries it (reason 2): a wrapper scheme, a Windows drive
     * and a UNC share are each proof from the first character, and nothing a template is spelled with
     * opens that way — a route signature, a path template and a JSON pointer all start at a `/` or a
     * `#`. So the braces in `glob://…/{Support,Http}/*.php` and in `C:\checkout\{a,b}\x.php` are a
     * shell glob, and refusing those runs over them keeps the machine word in front of them.
     *
     * A bare POSIX run has no such proof, so both exclusions still refuse it — unless a root the
     * ladder recognised already accounts for the text in FRONT of the character they object to
     * ({@see anchored()}), which is reason 3 asked one segment early. A run has to clear every
     * exclusion it trips, so carrying both takes an anchor in front of both.
     */
    private function couldBeAPath(string $run): bool
    {
        if (self::opening($run) !== null) {
            // Proof, or nothing: a run opening with a wrapper whose tail is another URL is a stream
            // address rather than a path, and shape must not get a second go at it — its last segment
            // names a file (`archive.gz`) exactly as a real path's does.
            return self::wrapper($run) !== null;
        }

        if (self::proven($run)) {
            return true;
        }

        if ((str_contains($run, '{') || str_contains($run, '}')) && ! $this->anchored($run, '{}')) {
            return false;
        }

        return ! str_contains($run, '\\') || $this->anchored($run, '\\');
    }

    /**
     * Whether a root the ladder recognised accounts for the run in front of the character an
     * exclusion objects to. That is reason 3's question asked before the rewrite rather than during
     * it, and it is the only thing a bare POSIX run has instead of proof: the prefix is a directory
     * this machine was configured from, so the text in front of the brace or the backslash is a
     * machine word whatever the rest of the run turns out to be, and removing it is a strip of text
     * the ladder matched rather than a guess at where a path ends.
     */
    private function anchored(string $run, string $objection): bool
    {
        return $this->deeplyRooted(substr($run, 0, strcspn($run, $objection)));
    }

    /**
     * Whether the ladder recognised a root for this path AND the root is deep enough to be a machine
     * word ({@see deepEnoughForAMachine()}). Both halves are the same question — is this prefix
     * something only a machine would be spelling — and this is the one place it is asked of the
     * ladder, because reason 3 and the exclusions in front of it must not disagree about which roots
     * count.
     *
     * What a shallow root loses is only reason 3: the run carries on to reason 4, so a path that
     * names a file still relativises through the same ladder and `/app/src/Foo.php` is unchanged.
     * A path that names none (`/app/storage`) keeps its machine word, and that is the trade —
     * knowingly a leak, and a leak is the direction that may be traded.
     */
    private function deeplyRooted(string $path): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $under = $this->stripped($path);

        if ($under === null) {
            return false;
        }

        return self::deepEnoughForAMachine(rtrim(substr($path, 0, strlen($path) - strlen($under)), '/'));
    }

    /**
     * Whether a prefix is deep enough that only a machine could be spelling it. A one-segment prefix
     * is not: `/app` is a container's checkout and equally a prefix an application mounts routes
     * under, so trusting it turned `Unknown route /app/users/profile` into `Unknown route
     * users/profile` — a route nobody wrote, in a diagnostic somebody will act on — and `/tmp` is a
     * word our own sentences spell, which is why {@see machineRoots()} will not redact it literally
     * either. Two segments is the line, and it is drawn once so the ladder's roots and this process's
     * own cannot come to disagree about it.
     */
    private static function deepEnoughForAMachine(string $root): bool
    {
        return substr_count($root, '/') >= 2;
    }

    /** A wrapper scheme, a Windows drive or a UNC share — shapes nothing but a path has. */
    private static function proven(string $run): bool
    {
        return self::windowsRooted($run) || self::wrapper($run) !== null;
    }

    /**
     * A Windows drive or a UNC share: the two shapes that spell a separator with a backslash, and so
     * the only two whose backslashes {@see stripped()} may rewrite.
     */
    private static function windowsRooted(string $run): bool
    {
        return str_starts_with($run, '\\\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $run) === 1;
    }

    /**
     * The schemes {@see WRAPPERS} decided are proof, in the order it spells them.
     *
     * @return list<string>
     */
    private static function localWrappers(): array
    {
        return array_keys(array_filter(self::WRAPPERS));
    }

    /** The proof scheme a run opens with, whatever follows it. */
    private static function opening(string $run): ?string
    {
        foreach (self::WRAPPERS as $scheme => $proof) {
            if ($proof && str_starts_with($run, $scheme.'://')) {
                return $scheme;
            }
        }

        return null;
    }

    /** The wrapper scheme a run carries, if it is one we can prove names a local file. */
    private static function wrapper(string $run): ?string
    {
        $scheme = self::opening($run);

        if ($scheme === null || preg_match('%^'.self::NESTED_SCHEME.'%', substr($run, strlen($scheme) + 3)) === 1) {
            return null;
        }

        return $scheme;
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

    /** The relative form, but only where the ladder recognised a root {@see deeplyRooted()} counts. */
    private function attributed(string $run): ?string
    {
        return $this->deeplyRooted(self::pathPart($run)) ? $this->resolve($run) : null;
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

    /**
     * The path under the root the ladder recognised, or null where it recognised none.
     *
     * The ladder is asked in one spelling — a backslash is a separator to it — but what comes back is
     * PUBLISHED, and only a Windows run may keep that spelling: there the two ways of writing one path
     * have to emit the same bytes, and everywhere else a backslash is a character the application
     * wrote, in a filename or in a regex it quoted. Normalisation is 1:1 in length, so the same count
     * of characters off the end of the ORIGINAL is the strip, said in the author's own hand.
     */
    private function stripped(string $path): ?string
    {
        $normalised = rtrim(str_replace('\\', '/', $path), '/');
        $answer = $this->paths->relative($normalised.'/'.self::PROBE);

        if ($answer === self::PROBE) {
            return '';
        }

        if (! str_ends_with($answer, '/'.self::PROBE)) {
            return null;
        }

        $under = substr($answer, 0, -strlen('/'.self::PROBE));

        // A ladder that answered something other than the run's own tail has invented text, so there
        // is nothing to take the original's spelling from: publish what it said and nothing more.
        return $under === '' || self::windowsRooted($path) || ! str_ends_with($normalised, $under)
            ? $under
            : substr(substr($path, 0, strlen($normalised)), -strlen($under));
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
            // Both forms: PHP's failed-include tail names every entry BARE as well as using it as a
            // prefix. Which prefixes may go at all is decided in {@see machineRoots()}, by the same
            // depth the ladder's roots answer to.
            $message = str_replace([$root.'/', $root], '', $message);
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

            // Redaction is a literal replace with nothing to tell a machine word from a sentence of
            // ours, so only a prefix deep enough to be one gets in at all.
            if (str_starts_with($root, '/') && self::deepEnoughForAMachine($root)) {
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
            static fn (string $scheme): string => preg_quote($scheme, '%'),
            self::localWrappers(),
        ));

        return '%'
            // A local stream wrapper: proof, unless what follows is itself a URL, which is the one
            // thing a wrapper's tail can be besides a path. Declining to open there leaves a nested
            // run whole and lets the rest of the message scrub as usual.
            .'(?<![\\w:/])(?:'.$schemes.')://(?!'.self::NESTED_SCHEME.')'.$segments
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
