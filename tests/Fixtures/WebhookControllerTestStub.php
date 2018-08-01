<?php

namespace Alfonsobries\ConektaCashier\Tests\Fixtures;

use Alfonsobries\ConektaCashier\Http\Controllers\WebhookController;

class WebhookControllerTestStub extends WebhookController
{
    public function handleChargeSucceeded()
    {
        $_SERVER['__received'] = true;
    }

    protected function eventExistsOnConekta($id)
    {
        return true;
    }
}
