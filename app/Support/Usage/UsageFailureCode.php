<?php

namespace App\Support\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use League\Flysystem\FilesystemException;
use PDOException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * O código técnico de uma falha — lista fechada. Nunca a mensagem da exceção:
 * `fromThrowable()` olha para a CLASSE e mais nada.
 */
enum UsageFailureCode: string
{
    case Database = 'database';
    case Storage = 'storage';
    case ExternalService = 'external_service';
    case Timeout = 'timeout';
    case Unexpected = 'unexpected';

    public static function fromThrowable(Throwable $throwable): self
    {
        return match (true) {
            $throwable instanceof QueryException, $throwable instanceof PDOException => self::Database,
            $throwable instanceof FilesystemException => self::Storage,
            $throwable instanceof ConnectionException,
            $throwable instanceof RequestException,
            $throwable instanceof TransportExceptionInterface => self::ExternalService,
            $throwable instanceof ProcessTimedOutException => self::Timeout,
            default => self::Unexpected,
        };
    }
}
