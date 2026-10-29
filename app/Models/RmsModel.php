<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** All RMS model date columns use timestampTz / SQL Server datetimeoffset. */
abstract class RmsModel extends Model
{
    public function getDateFormat()
    {
        // Preserve milliseconds and the original offset on model writes.
        // User and Sanctum models keep their normal, offset-less date format.
        return $this->getConnection()->getDriverName() === 'sqlsrv'
            ? 'Y-m-d H:i:s.vP'
            : parent::getDateFormat();
    }
}
