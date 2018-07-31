<?php

namespace Laravel\Cashier;

use Exception;
use InvalidArgumentException;
use Stripe\Card as StripeCard;
use Conekta\Token as ConektaToken;
use Illuminate\Support\Collection;
use Stripe\Charge as StripeCharge;
use Stripe\Refund as StripeRefund;
use Stripe\Invoice as StripeInvoice;
use Conekta\Customer as ConektaCustomer;
use Conekta\PaymentSource as ConektaPaymentSource;
use Stripe\BankAccount as StripeBankAccount;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\Error\InvalidRequest as StripeErrorInvalidRequest;
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
     * @return \Stripe\Charge
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

        return StripeCharge::create($options, ['api_key' => $this->getConektaKey()]);
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
     * Add an invoice item to the customer's upcoming invoice.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Stripe\InvoiceItem
     *
     * @throws \InvalidArgumentException
     */
    public function tab($description, $amount, array $options = [])
    {
        if (! $this->conekta_id) {
            throw new InvalidArgumentException(class_basename($this).' is not a Conekta customer. See the createAsConektaCustomer method.');
        }

        $options = array_merge([
            'customer' => $this->conekta_id,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        return StripeInvoiceItem::create(
            $options,
            ['api_key' => $this->getConektaKey()]
        );
    }

    /**
     * Invoice the customer for the given amount and generate an invoice immediately.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Laravel\Cashier\Invoice|bool
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        $this->tab($description, $amount, $options);

        return $this->invoice();
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the Stripe model is on trial.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
               $subscription->conekta_plan === $plan;
    }

    /**
     * Determine if the Stripe model is on a "generic" trial at the model level.
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
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

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
     * @param  string  $subscription
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->created_at->getTimestamp();
        })
        ->first(function ($value) use ($subscription) {
            return $value->name === $subscription;
        });
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

    // /**
    //  * Invoice the billable entity outside of regular billing cycle.
    //  *
    //  * @return \Stripe\Invoice|bool
    //  */
    // public function invoice()
    // {
    //     if ($this->conekta_id) {
    //         try {
    //             return StripeInvoice::create(['customer' => $this->conekta_id], $this->getConektaKey())->pay();
    //         } catch (StripeErrorInvalidRequest $e) {
    //             return false;
    //         }
    //     }

    //     return true;
    // }

    // /**
    //  * Get the entity's upcoming invoice.
    //  *
    //  * @return \Laravel\Cashier\Invoice|null
    //  */
    // public function upcomingInvoice()
    // {
    //     try {
    //         $stripeInvoice = StripeInvoice::upcoming(
    //             ['customer' => $this->conekta_id],
    //             ['api_key' => $this->getConektaKey()]
    //         );

    //         return new Invoice($this, $stripeInvoice);
    //     } catch (StripeErrorInvalidRequest $e) {
    //         //
    //     }
    // }

    // /**
    //  * Find an invoice by ID.
    //  *
    //  * @param  string  $id
    //  * @return \Laravel\Cashier\Invoice|null
    //  */
    // public function findInvoice($id)
    // {
    //     try {
    //         return new Invoice($this, StripeInvoice::retrieve($id, $this->getConektaKey()));
    //     } catch (Exception $e) {
    //         //
    //     }
    // }

    // /**
    //  * Find an invoice or throw a 404 error.
    //  *
    //  * @param  string  $id
    //  * @return \Laravel\Cashier\Invoice
    //  */
    // public function findInvoiceOrFail($id)
    // {
    //     $invoice = $this->findInvoice($id);

    //     if (is_null($invoice)) {
    //         throw new NotFoundHttpException;
    //     }

    //     if ($invoice->customer !== $this->conekta_id) {
    //         throw new AccessDeniedHttpException;
    //     }

    //     return $invoice;
    // }

    // /**
    //  * Create an invoice download Response.
    //  *
    //  * @param  string  $id
    //  * @param  array  $data
    //  * @param  string  $storagePath
    //  * @return \Symfony\Component\HttpFoundation\Response
    //  */
    // public function downloadInvoice($id, array $data, $storagePath = null)
    // {
    //     return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    // }

    // /**
    //  * Get a collection of the entity's invoices.
    //  *
    //  * @param  bool  $includePending
    //  * @param  array  $parameters
    //  * @return \Illuminate\Support\Collection
    //  */
    // public function invoices($includePending = false, $parameters = [])
    // {
    //     $invoices = [];

    //     $parameters = array_merge(['limit' => 24], $parameters);

    //     $stripeInvoices = $this->asConektaCustomer()->invoices($parameters);

    //     // Here we will loop through the Stripe invoices and create our own custom Invoice
    //     // instances that have more helper methods and are generally more convenient to
    //     // work with than the plain Stripe objects are. Then, we'll return the array.
    //     if (! is_null($stripeInvoices)) {
    //         foreach ($stripeInvoices->data as $invoice) {
    //             if ($invoice->paid || $includePending) {
    //                 $invoices[] = new Invoice($this, $invoice);
    //             }
    //         }
    //     }

    //     return new Collection($invoices);
    // }

    // /**
    //  * Get an array of the entity's invoices.
    //  *
    //  * @param  array  $parameters
    //  * @return \Illuminate\Support\Collection
    //  */
    // public function invoicesIncludingPending(array $parameters = [])
    // {
    //     return $this->invoices(true, $parameters);
    // }

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
     * @param  string  $subscription
     * @return bool
     */
    public function subscribedToPlan($plan, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

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
