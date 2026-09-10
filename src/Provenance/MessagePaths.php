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
 * does not say — and the second is the one that must be impossible.
 *
 * What gets us there is a weighing, not a row of independent tests. Every reason for a rewrite
 * ({@see PathReason}) declares the CLAIM it proves ({@see PathClaim}) and whether it settles that
 * claim alone; every reason against one ({@see PathObjection}) declares the claim it denies and the
 * same. They compose by one rule, and this is the only place it is written down:
 *
 * > A claim stands for a run when a reason proves it and no conclusive objection denies it. A rewrite
 * > is authorised by a claim that stands and COVERS the text it removes, and by nothing else.
 * > `RunIsAPath` covers the run; `PrefixIsAMachineWord` covers only the text in front of the character
 * > an objection is spelled with, which is all a prefix strip takes. A suggestive reason authorises
 * > nothing while an objection stands unanswered, and nothing answers a conclusive objection.
 *
 * Read against the members: a wrapper, a drive and a UNC share prove the run is a path from its first
 * character — nothing a template is spelled with opens that way, since a route signature, a path
 * template and a JSON pointer all start at a `/` or a `#` — so they overrule a brace and the braces in
 * `glob://…/{Support,Http}/*.php` are the shell glob they look like. A root the ladder recognised
 * proves only that the text in FRONT of the objected-to character is a machine word, which is exactly
 * what a strip removes, so it overrules them too without ever admitting a template. Shape proves the
 * weaker claim suggestively, so `/elsewhere/x/Reader.php` reduces while `/api/users/{user}/avatar.png`
 * keeps every character — and a braced POSIX run no root accounted for is published whole, a leak
 * taken knowingly because the other direction is the one that must be impossible.
 *
 * What a reason does NOT prove is a member of its own rather than silence: a wrapper's proof stops at
 * the first character of its tail, since the compression wrappers filter another STREAM and
 * `compress.zlib://http://…` names a host ({@see WRAPPERS} holds that decision); and a one-segment
 * root proves nothing, `/app` being a prefix an application mounts routes under as readily as a
 * container's checkout. Asking whether the ladder recognised a root at all takes {@see PROBE}, because
 * its answer alone cannot say — a root one segment up leaves the same bare name a root it never found
 * leaves.
 *
 * Ahead of the weighing are the exclusions the PATTERN spells: a `#` fragment, a `~` home-relative
 * run, a `/` behind a `\`, a URL's scheme or host, an HTTP method in front of a route signature. Those
 * produce no run at all, so they are not weighed; what is weighed is a run that was produced. And what
 * an objection refuses is the PATH RUN and not the sentence it sits in — a match crosses an interior
 * space, so one routinely spans a template AND the file named after it, and refusing all of it
 * published the file ({@see rewrite()}). How far a run reaches through a space is {@see pathRun()}.
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

        if (! $this->admits($run)) {
            // An objection refuses a PATH RUN, not the sentence around it. {@see pathRun()} is where
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
        $reduced = $this->isAPath($unattributed) ? $this->resolve($unattributed) : $unattributed;

        return $reduced.$this->scrub(substr($run, strlen($unattributed)).$trailing);
    }

    /**
     * Whether every objection this run trips against {@see PathClaim::RunIsAPath} is answered — the
     * composition rule at the top of this class, asked of the claim a rewrite of the whole run needs.
     * The other claim is asked of one candidate at a time, by {@see machineWord()}.
     */
    private function admits(string $run): bool
    {
        foreach ($this->objections(PathClaim::RunIsAPath, $run) as $objection) {
            if (! $this->answered($objection, $run)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a reason covers the text a rewrite would remove despite this objection. Nothing answers
     * a conclusive one. A suggestive one takes a conclusive reason: for the run entire, or — where the
     * rewrite is the prefix strip a recognised root buys — for the text in front of the character the
     * objection is spelled with, since that is all such a strip removes and it is a directory this
     * machine was configured from whatever the rest of the run turns out to be.
     *
     * A run has to answer every objection it trips, so carrying both a brace and a backslash takes a
     * root in front of both.
     */
    private function answered(PathObjection $objection, string $run): bool
    {
        if ($objection->isConclusive()) {
            return false;
        }

        return $this->conclusivelyAPath($run)
            || $this->machineWord(substr($run, 0, strcspn($run, $objection->characters())));
    }

    /**
     * Whether {@see PathClaim::RunIsAPath} stands for this run at all — the last thing asked of a run
     * no root accounted for. {@see admits()} has answered every objection by the time this is reached,
     * so a reason of any strength is enough: `/api/forms` and `/docs/reference/configuration` keep
     * every character they had, because nothing proved they were ever paths.
     */
    private function isAPath(string $run): bool
    {
        return $this->reasons(PathClaim::RunIsAPath, $run) !== [];
    }

    /** Whether a CONCLUSIVE reason proves {@see PathClaim::RunIsAPath} for this run. */
    private function conclusivelyAPath(string $run): bool
    {
        return array_filter(
            $this->reasons(PathClaim::RunIsAPath, $run),
            static fn (PathReason $reason): bool => $reason->isConclusive(),
        ) !== [];
    }

    /**
     * Whether {@see PathClaim::PrefixIsAMachineWord} stands for this text: the ladder recognised a
     * root, and nothing conclusive denies what that proves. This is the one place the claim is
     * decided, so the rewrite and the objections in front of it cannot come to disagree about which
     * roots count.
     *
     * What a shallow root loses is only this claim: the run carries on to shape, so a path that names
     * a file still relativises through the same ladder and `/app/src/Foo.php` is unchanged, while a
     * path that names none (`/app/storage`) keeps its machine word. That is the trade — knowingly a
     * leak, and a leak is the direction that may be traded.
     */
    private function machineWord(string $path): bool
    {
        return $this->reasons(PathClaim::PrefixIsAMachineWord, $path) !== []
            && array_filter(
                $this->objections(PathClaim::PrefixIsAMachineWord, $path),
                static fn (PathObjection $objection): bool => $objection->isConclusive(),
            ) === [];
    }

    /**
     * The reasons that prove a claim for this run, keyed by case name so nothing can read an order
     * into the answer: what the callers ask is whether the set is empty and whether it holds a
     * conclusive member.
     *
     * @return array<string, PathReason>
     */
    private function reasons(PathClaim $claim, string $run): array
    {
        $standing = [];

        foreach (PathReason::cases() as $reason) {
            if ($reason->proves() === $claim && $this->stands($reason, $run)) {
                $standing[$reason->name] = $reason;
            }
        }

        return $standing;
    }

    /**
     * The objections against a claim this run trips, keyed and read the same way.
     *
     * @return array<string, PathObjection>
     */
    private function objections(PathClaim $claim, string $run): array
    {
        $tripped = [];

        foreach (PathObjection::cases() as $objection) {
            if ($objection->opposes() === $claim && $this->trips($objection, $run)) {
                $tripped[$objection->name] = $objection;
            }
        }

        return $tripped;
    }

    /** Whether one reason stands for this run. */
    private function stands(PathReason $reason, string $run): bool
    {
        return match ($reason) {
            PathReason::LocalWrapper => self::wrapper($run) !== null,
            PathReason::WindowsRoot => self::windowsRooted($run),
            PathReason::RecognisedRoot => $this->stripped($run) !== null,
            PathReason::FileShape => self::namesAFile($run),
        };
    }

    /** Whether one objection trips on this run. */
    private function trips(PathObjection $objection, string $run): bool
    {
        return match ($objection) {
            // Shape must not get a second go at a wrapper whose tail is another URL: its last segment
            // names a file (`archive.gz`) exactly as a real path's does.
            PathObjection::NestedScheme => self::opening($run) !== null && self::wrapper($run) === null,
            PathObjection::ShallowRoot => ! self::deepEnoughForAMachine($this->rootOf($run)),
            PathObjection::Brace => str_contains($run, '{') || str_contains($run, '}'),
            PathObjection::Backslash => str_contains($run, '\\'),
        };
    }

    /**
     * The root the ladder recognised in front of this path, or the empty string where it recognised
     * none — a depth {@see PathObjection::ShallowRoot} trips on, harmlessly, since
     * {@see PathReason::RecognisedRoot} does not stand there either.
     */
    private function rootOf(string $path): string
    {
        $normalised = rtrim(str_replace('\\', '/', $path), '/');
        $under = $this->stripped($normalised);

        return $under === null ? '' : rtrim(substr($normalised, 0, strlen($normalised) - strlen($under)), '/');
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

    /** The relative form, where {@see PathClaim::PrefixIsAMachineWord} stands for the run's path half. */
    private function attributed(string $run): ?string
    {
        return $this->machineWord(self::pathPart($run)) ? $this->resolve($run) : null;
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
