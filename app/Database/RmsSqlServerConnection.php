<?php

declare(strict_types=1);

namespace App\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\SqlServerConnection;

/** Keep bound Carbon instants correct when comparing datetimeoffset columns. */
final class RmsSqlServerConnection extends SqlServerConnection
{
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                // Convert the instant to UTC without dropping its timezone offset.
                $bindings[$key] = DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format($this->getQueryGrammar()->getDateFormat());
            }
        }

        return parent::prepareBindings($bindings);
    }
}