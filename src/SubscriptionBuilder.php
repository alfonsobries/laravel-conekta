<?php

namespace Alfonsobries\ConektaCashier;

use Carbon\Carbon;
use Conekta\Plan as ConektaPlan;
use DateTimeInterface;
use Alfonsobries\ConektaCashier\Plan;

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
     * @return \Alfonsobries\ConektaCashier\Subscription
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
     * @return \Alfonsobries\ConektaCashier\Subscription
     */
    public function create($token = null, array $options = [])
    {
        $customer = $this->owner->getConektaCustomer($token, $options);
        
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
