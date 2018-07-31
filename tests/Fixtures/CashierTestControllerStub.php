<?php

namespace Laravel\Cashier\Tests\Fixtures;

use Laravel\Cashier\Http\Controllers\WebhookController;

class CashierTestControllerStub extends WebhookController
{
    protected function eventExistsOnConekta($id)
    {
        return true;
    }
}
