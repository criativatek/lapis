<?php

namespace Tests\Feature\Usage;

use App\Support\Usage\UsageFailureCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class UsageFailureCodeTest extends TestCase
{
    #[Test]
    public function a_throwable_maps_to_a_closed_code_by_its_class_only(): void
    {
        $this->assertSame(UsageFailureCode::Database, UsageFailureCode::fromThrowable(new QueryException('sqlite', 'select 1', [], new \Exception('x'))));
        $this->assertSame(UsageFailureCode::Database, UsageFailureCode::fromThrowable(new \PDOException('x')));
        $this->assertSame(UsageFailureCode::Storage, UsageFailureCode::fromThrowable(UnableToWriteFile::atLocation('a/b')));
        $this->assertSame(UsageFailureCode::ExternalService, UsageFailureCode::fromThrowable(new ConnectionException('x')));
        $this->assertSame(UsageFailureCode::ExternalService, UsageFailureCode::fromThrowable(new TransportException('x')));
        $this->assertSame(UsageFailureCode::Timeout, UsageFailureCode::fromThrowable(
            new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL),
        ));
        $this->assertSame(UsageFailureCode::Unexpected, UsageFailureCode::fromThrowable(new \RuntimeException('x')));
    }
}
