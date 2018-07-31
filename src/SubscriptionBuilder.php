<?php

namespace Laravel\Cashier;

use Carbon\Carbon;
use Conekta\Plan as ConektaPlan;
use DateTimeInterface;
use Laravel\Cashier\Plan;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;


    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  string  $plan
     * @return void
     */
    public function __construct($owner, $plan)
    {
        $this->plan = Plan::where('conekta_id', $plan)->firstOrFail();
        $this->owner = $owner;
    }

    /**
     * Add a new Stripe subscription to the Stripe model.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Conekta subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function create($token = null, array $options = [])
    {
        $customer = $this->getConektaCustomer($token, $options);
        
        $subscription = $customer->createSubscription([
            'plan' => $this->plan->conekta_id
        ]);

        // @TODO: Revisar estos datos
        return $this->owner->subscriptions()->create([
            'conekta_id' => $subscription->id,
            'conekta_plan' => $this->plan->conekta_id,
            'trial_ends_at' => $this->plan->trial_ends_at ?: $this->getTrialEndForPayload(),
            'ends_at' => null,
        ]);
    }

    /**
     * Get the Conekta customer instance for the current user and token.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Conekta\Customer
     */
    protected function getConektaCustomer($token = null, array $options = [])
    {
        if (! $this->owner->conekta_id) {
            $customer = $this->owner->createAsConektaCustomer($token, $options);
        } else {
            $customer = $this->owner->asConektaCustomer();

            if ($token) {
                $this->owner->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Get the trial ending date for the Conekta payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        $conekta_plan = $this->plan->asConektaPlan();
        
        if (!$conekta_plan->trial_period_days) {
            return Carbon::now();
        }

        return Carbon::now()->addDays($conekta_plan->trial_period_days);
    }
}
