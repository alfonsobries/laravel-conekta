<?php

namespace Alfonsobries\ConektaCashier\Tests\Fixtures;

use Alfonsobries\ConektaCashier\Http\Controllers\WebhookController;

class CashierTestControllerStub extends WebhookController
{
    protected function eventExistsOnConekta($id)
    {
        return true;
    }
}
