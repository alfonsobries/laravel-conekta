<?php

namespace Laravel\Cashier\Tests;

use Carbon\Carbon;
use Conekta\Conekta;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Http\Request;
use Laravel\Cashier\Tests\Fixtures\CashierTestControllerStub;
use Laravel\Cashier\Tests\Fixtures\User;
use PHPUnit_Framework_TestCase;

class CashierTest extends PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        if (file_exists(__DIR__.'/../.env')) {
            $dotenv = new \Dotenv\Dotenv(__DIR__.'/../');
            $dotenv->load();
        }
    }

    public function setUp()
    {
        Eloquent::unguard();

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string('conekta_id')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('subscriptions', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('conekta_id');
            $table->string('conekta_plan');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('plans', function ($table) {
            $table->increments('id');
            $table->string('conekta_id');
            $table->timestamp('trial_ends_at')->nullable();
            // $table->string('name');
            // $table->integer('amount');
            // $table->string('currency', 3)->default('MXN');
            // $table->string('interval')->nullable();
            // $table->string('frequency')->nullable();
            // $table->integer('trial_period_days');
            // $table->integer('expiry_count');
            $table->timestamps();
        });

        $this->setApiKey();
    }

    public function tearDown()
    {
        $this->schema()->drop('users');
        $this->schema()->drop('subscriptions');
        $this->schema()->drop('plans');
    }

    /**
     * Tests.
     */
    public function testSubscriptionsCanBeCreated()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        $plan = $this->createPlan('Montly Plan');
        $plan2 = $this->createPlan('Yearly Plan', ['interval' => 'year']);
        
        // Create Subscription
        $user->newSubscription($plan->id)
            ->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription()->conekta_id);

        $this->assertTrue($user->subscribed());
        $this->assertTrue($user->subscribedToPlan($plan->id));
        $this->assertFalse($user->subscribedToPlan($plan2->id));
        $this->assertTrue($user->subscribed($plan->id));
        $this->assertFalse($user->subscribed($plan2->id));
        $this->assertTrue($user->subscription()->active());
        $this->assertFalse($user->subscription()->cancelled());
        $this->assertFalse($user->subscription()->onGracePeriod());
        $this->assertTrue($user->subscription()->recurring());
        $this->assertFalse($user->subscription()->ended());

        // Cancel Subscription
        $subscription = $user->subscription();
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertTrue($subscription->ended());

        $subscription->fill(['ends_at' => $oldGracePeriod])->save();

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Swap Plan
        $subscription->swap($plan2->id);

        $this->assertEquals($plan2->id, $subscription->conekta_plan);
        $this->assertTrue($user->subscribedToPlan($plan2->id));
        $this->assertFalse($user->subscribedToPlan($plan->id));
    }

    public function test_generic_trials()
    {
        $user = new User;
        $this->assertFalse($user->onGenericTrial());
        $user->trial_ends_at = Carbon::tomorrow();
        $this->assertTrue($user->onGenericTrial());
        $user->trial_ends_at = Carbon::today()->subDays(5);
        $this->assertFalse($user->onGenericTrial());
    }

    public function test_creating_subscription_with_trial()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        $plan = $this->createPlan('Montly Plan', [
            'trial_period_days' => 7
        ]);
        
        // Create Subscription
        $user->newSubscription($plan->id)
            // ->trialDays(7)
            ->create($this->getTestToken());

        $subscription = $user->subscription();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);
    }

    public function test_creating_subscription_with_explicit_trial()
    {
        $user = User::create([
             'email' => 'alfonso@vexilo.com',
             'name' => 'Alfonso Bribiesca',
        ]);

        $plan = $this->createPlan('Montly Plan', [
            'trial_ends_at' => Carbon::tomorrow()->hour(3)->minute(15)
        ]);
        
        // Create Subscription
        $user->newSubscription($plan->id)
            ->create($this->getTestToken());

        $subscription = $user->subscription();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->trial_ends_at);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->trial_ends_at);
    }

    /**
     * @group foo
     */
    public function test_marking_as_cancelled_from_webhook()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        $plan = $this->createPlan('Montly Plan');

        $user->newSubscription($plan->id)
            ->create($this->getTestToken());

        $subscription = $user->subscription();

        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['id' => 'foo', 'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $subscription->conekta_id,
                    'customer' => $user->conekta_id,
                ],
            ],
        ]));

        $controller = new CashierTestControllerStub;
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());

        $user = $user->fresh();
        $subscription = $user->subscription();

        $this->assertTrue($subscription->cancelled());
    }

    /** @test */
    public function create_charge()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        $conekta_order = $user->createOrder([], 'Box of Cohiba S1s', 35000);
        
        $this->assertNotEmpty($conekta_order->id);
    }

    /**
     * Creates a new Conekta\Plan
     *
     * @return \Conekta\Plan
     */
    protected function createPlan($name, $attributes = [])
    {
        $conekta_plan = \Laravel\Cashier\Plan::createAsConektaPlan(
            $name,
            array_merge([
                'amount' => 1000,
                'currency' => 'MXN',
                'interval' => 'month',
                'frequency' => 1,
                'trial_period_days' => 0,
                'trial_ends_at' => null,
                'expiry_count' => null,
            ], $attributes)
        );
        
        return $conekta_plan;
    }

    protected function getTestToken()
    {
        return 'tok_test_visa_4242';
    }

    public function setApiKey()
    {
        $apiEnvKey = getenv('CONEKTA_SECRET');

        if (!$apiEnvKey) {
            $apiEnvKey = 'key_ZLy4aP2szht1HqzkCezDEA';
        }
        
        Conekta::setApiKey($apiEnvKey);
    }

    public function setApiVersion($version)
    {
        Conekta::setApiVersion($version);
    }

    /**
     * Schema Helpers.
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}
