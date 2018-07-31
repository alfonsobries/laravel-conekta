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
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    // *
    //  * The quantity of the subscription.
    //  *
    //  * @var int
     
    // protected $quantity = 1;

    /**
     * The date and time the trial will expire.
     *
     * @var \Carbon\Carbon
     */
    // protected $trialExpires;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    // protected $skipTrial = false;


    // *
    //  * The metadata to apply to the subscription.
    //  *
    //  * @var array|null
     
    // protected $metadata;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($owner, $plan)
    {
        $this->plan = Plan::where('conekta_id', $plan)->firstOrFail();
        $this->owner = $owner;
    }

    // *
    //  * Specify the quantity of the subscription.
    //  *
    //  * @param  int  $quantity
    //  * @return $this
     
    // public function quantity($quantity)
    // {
    //     $this->quantity = $quantity;

    //     return $this;
    // }

    // /**
    //  * Specify the number of days of the trial.
    //  *
    //  * @param  int  $trialDays
    //  * @return $this
    //  */
    // public function trialDays($trialDays)
    // {
    //     $this->trialExpires = Carbon::now()->addDays($trialDays);

    //     return $this;
    // }

    // /**
    //  * Specify the ending date of the trial.
    //  *
    //  * @param  \Carbon\Carbon  $trialUntil
    //  * @return $this
    //  */
    // public function trialUntil(Carbon $trialUntil)
    // {
    //     $this->trialExpires = $trialUntil;

    //     return $this;
    // }

    // /**
    //  * Force the trial to end immediately.
    //  *
    //  * @return $this
    //  */
    // public function skipTrial()
    // {
    //     $this->skipTrial = true;

    //     return $this;
    // }

    // *
    //  * The metadata to apply to a new subscription.
    //  *
    //  * @param  array  $metadata
    //  * @return $this
     
    // public function withMetadata($metadata)
    // {
    //     $this->metadata = $metadata;

    //     return $this;
    // }

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

    // /**
    //  * Get the tax percentage for the Stripe payload.
    //  *
    //  * @return int|null
    //  */
    // protected function getTaxPercentageForPayload()
    // {
    //     if ($taxPercentage = $this->owner->taxPercentage()) {
    //         return $taxPercentage;
    //     }
    // }
}
