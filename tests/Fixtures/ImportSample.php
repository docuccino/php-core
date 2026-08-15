<?php

declare(strict_types=1);

namespace Docuccino\Sample\Http;

use App\Data\MfaChallengeData;
use App\Data\MfaEnrollmentChallengeData as Enrollment;
use App\Models;
use Docuccino\Core\Tests\Fixtures\SampleStatus;

/**
 * A source file with a namespace + `use` imports (one aliased, one a namespace prefix, one a real enum),
 * read by ImportContext to resolve unqualified class names the way PHP would. Never
 * instantiated/analysed — ImportContext only parses its imports.
 */
final class ImportSample
{
    public MfaChallengeData $challenge;

    public Enrollment $enrollment;

    public Models\User $user;

    public SampleStatus $status;
}
