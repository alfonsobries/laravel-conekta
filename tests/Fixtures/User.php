<?php

namespace Alfonsobries\ConektaCashier\Tests\Fixtures;

use Alfonsobries\ConektaCashier\Billable;
use Illuminate\Database\Eloquent\Model as Eloquent;

class User extends Eloquent
{
    use Billable;
}
