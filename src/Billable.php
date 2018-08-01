<?php

namespace Alfonsobries\ConektaCashier;

use Exception;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Conekta\Token as ConektaToken;
use Stripe\Card as StripeCard;
use Stripe\Refund as StripeRefund;
use Conekta\Charge as ConektaCharge;
use Conekta\Order as ConektaOrder;
use Conekta\Customer as ConektaCustomer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait Billable
{
    /**
     * The Conekta API key.
     *
     * @var string
     */
    protected static $conektaKey;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  array  $options
     * @return \Conekta\Charge
     *
     * @throws \InvalidArgumentException
     */
    public function charge($amount, array $options = [])
    {
        $options = array_merge([
            'currency' => $this->preferredCurrency(),
        ], $options);

        $options['amount'] = $amount;

        if (! array_key_exists('source', $options) && $this->conekta_id) {
            $options['customer'] = $this->conekta_id;
        }

        if (! array_key_exists('source', $options) && ! array_key_exists('customer', $options)) {
            throw new InvalidArgumentException('No payment source provided.');
        }

        return ConektaCharge::create($options, ['api_key' => $this->getConektaKey()]);
    }

    public function createOrder($options = [], $name, $unit_price, $quantity = 1)
    {
        $conekta_customer = $this->getConektaCustomer();

        $options = array_merge([
            'currency' => $this->preferredCurrency(),
            'customer_info' => [
                'customer_id' => $conekta_customer->id
            ],
            'line_items' => [
                [
                    'name' => $name,
                    'unit_price' => $unit_price,
                    'quantity' => $quantity
                ]
            ],
        ], $options);


        return ConektaOrder::create($options);
    }

    /**
     * Refund a customer for a charge.
     *
     * @param  string  $charge
     * @param  array  $options
     * @return \Stripe\Charge
     *
     * @throws \InvalidArgumentException
     */
    public function refund($charge, array $options = [])
    {
        $options['charge'] = $charge;

        return StripeRefund::create($options, ['api_key' => $this->getConektaKey()]);
    }

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile()
    {
        return (bool) $this->card_brand;
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $plan
     * @return \Alfonsobries\ConektaCashier\SubscriptionBuilder
     */
    public function newSubscription($plan)
    {
        return new SubscriptionBuilder($this, $plan);
    }

    /**
     * Determine if the Stripe model is on trial.
     *
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription();

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
               $subscription->conekta_plan === $plan;
    }

    /**
     * Determine if the Conekta model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the Stripe model has a given subscription.
     *
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($plan = null)
    {
        $subscription = $this->subscription();

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
               $subscription->conekta_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @return \Alfonsobries\ConektaCashier\Subscription|null
     */
    public function subscription()
    {
        return $this->subscriptions->first();
    }

    /**
     * Get all of the subscriptions for the Stripe model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, $this->getForeignKey())->orderBy('created_at', 'desc');
    }

    /**
     * Get a collection of the entity's cards.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function cards($parameters = [])
    {
        $cards = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $stripeCards = $this->asConektaCustomer()->sources->all(
            ['object' => 'card'] + $parameters
        );

        if (! is_null($stripeCards)) {
            foreach ($stripeCards->data as $card) {
                $cards[] = new Card($this, $card);
            }
        }

        return new Collection($cards);
    }

    /**
     * Get the default card for the entity.
     *
     * @return \Stripe\Card|null
     */
    public function defaultCard()
    {
        $customer = $this->asConektaCustomer();

        foreach ($customer->payment_sources as $source) {
            if ($source->id === $customer->default_payment_source_id) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Update customer's credit card.
     *
     * @param  string  $token
     * @return Conekta\Customer
     */
    public function updateCard($token)
    {
        $customer = $this->asConektaCustomer();

        // @TODO
        // $token = ConektaToken::find($token);
        // // If the given token already has the card as their default source, we can just
        // // bail out of the method now. We don't need to keep adding the same card to
        // // a model's account every time we go through this particular method call.
        // if ($token[$token->type]->id === $customer->default_payment_source_id) {
        //     return;
        // }

        $source = $customer->createPaymentSource([
            'token_id' => $token,
            'type'     => 'card'
        ]);

        
        $customer->update(['default_payment_source_id' => $source->id]);

        
        $source = $customer->default_payment_source_id
                    ? $this->defaultCard()
                    : null;

        // With the default source for this model we can update the last
        // four digits and the card brand on the record in the database. This allows
        // us to display the information on the front-end when updating the cards.
        $this->fillCardDetails($source);

        $this->save();

        return $customer;
    }

    /**
     * Synchronises the customer's card from Stripe back into the database.
     *
     * @return $this
     */
    public function updateCardFromStripe()
    {
        $defaultCard = $this->defaultCard();

        if ($defaultCard) {
            $this->fillCardDetails($defaultCard)->save();
        } else {
            $this->forceFill([
                'card_brand' => null,
                'card_last_four' => null,
            ])->save();
        }

        return $this;
    }

    /**
     * Fills the model's properties with the source from Stripe.
     *
     * @param  \Conekta\PaymentSource  $card
     * @return $this
     */
    protected function fillCardDetails($card)
    {
        $this->card_brand = $card->brand;
        $this->card_last_four = $card->last4;
        return $this;
    }

    /**
     * Deletes the entity's cards.
     *
     * @return void
     */
    public function deleteCards()
    {
        $this->cards()->each(function ($card) {
            $card->delete();
        });

        $this->updateCardFromStripe();
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param  string  $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $customer = $this->asConektaCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the Conekta model is actively subscribed to one of the given plans.
     *
     * @param  string  $plans
     * @return bool
     */
    public function subscribedToPlan($plan)
    {
        $subscription = $this->subscription();

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $subscription->conekta_plan === $plan;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null($this->subscriptions->first(function ($value) use ($plan) {
            return $value->conekta_plan === $plan && $value->valid();
        }));
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId()
    {
        return ! is_null($this->conekta_id);
    }

    /**
     * Create a Conekta customer for the given Conekta model.
     *
     * @param  string  $token
     * @param  array  $options
     * @return \Conekta\Customer
     */
    public function createAsConektaCustomer($token, array $options = [])
    {
        $options = array_key_exists('email', $options) && array_key_exists('name', $options)
                ? $options : array_merge($options, ['email' => $this->email], ['name' => $this->name]);

        // Here we will create the customer instance on Conekta and store the ID of the
        // user from conekta. This ID will correspond with the Conekta user instances
        // and allow us to retrieve users from Conekta later when we need to work.
        $customer = ConektaCustomer::create($options);

        $this->conekta_id = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Conekta using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (! is_null($token)) {
            $customer = $this->updateCard($token);
        }

        return $customer;
    }

    /**
     * Get the Conekta customer instance for the current user and token.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Conekta\Customer
     */
    public function getConektaCustomer($token = null, array $options = [])
    {
        if (! $this->conekta_id) {
            $customer = $this->createAsConektaCustomer($token, $options);
        } else {
            $customer = $this->asConektaCustomer();

            if ($token) {
                $this->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Get the Stripe customer for the Stripe model.
     *
     * @return \Stripe\Customer
     */
    public function asConektaCustomer()
    {
        return ConektaCustomer::find($this->conekta_id);
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the Stripe API key.
     *
     * @return string
     */
    public static function getConektaKey()
    {
        if (static::$conektaKey) {
            return static::$conektaKey;
        }

        if ($key = getenv('CONEKTA_SECRET')) {
            return $key;
        }

        return config('services.conekta.secret');
    }

    /**
     * Set the Stripe API key.
     *
     * @param  string  $key
     * @return void
     */
    public static function setStripeKey($key)
    {
        static::$conektaKey = $key;
    }
}
