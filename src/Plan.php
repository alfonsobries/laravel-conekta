<?php

namespace Laravel\Cashier;

use Carbon\Carbon;
use Conekta\Plan as ConektaPlan;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Plan extends Model
{
    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    public static function createAsConektaPlan($name, $attributes)
    {
        $trial_ends_at = null;
        if ($attributes['trial_ends_at'] && $attributes['trial_ends_at'] instanceof Carbon) {
            $trial_ends_at = $attributes['trial_ends_at'];
            $attributes['trial_period_days'] = Carbon::now()->diffInDays($attributes['trial_ends_at']);
            unset($attributes['trial_ends_at']);
        }

        $plan_id = self::generatePlanId($attributes);

        $attributes = array_merge([
            'id' => $plan_id,
            'name' => $name,
            'amount' => 1000,
            'currency' => 'MXN',
            'interval' => 'month',
            'frequency' => 1,
            'trial_period_days' => null,
            'expiry_count' => null,
        ], $attributes);

        try {
            $plan = ConektaPlan::find($plan_id);
        } catch (\Exception $e) {
            $plan = null;
        }
        
        if ($plan) {
            $plan->delete();
        }

        $plan = ConektaPlan::create($attributes);

        self::updateOrCreate(['conekta_id' => $plan->id], [
            'conekta_id' => $plan->id,
            'trial_ends_at' => $trial_ends_at,
        ]);

        return $plan;
    }

    private static function generatePlanId($attributes)
    {
        $only = [   
            'currency',
            'amount',
            'interval',
            'frequency',
            'trial_period_days',
            'trial_ends_at',
            'expiry_count',
        ];

        $attributes = array_filter($attributes, function ($key) use ($only) {
            return in_array($key, $only);
        }, ARRAY_FILTER_USE_KEY);

        // Sort the array
        $attributes = array_replace(array_flip($only), $attributes);

        return strtolower(implode('-', $attributes));
    }

    /**
     * Get the plan as Conekta Object
     *
     * @return \Conekta\Plan
     *
     * @throws \LogicException
     */
    public function asConektaPlan()
    {
        $plan = ConektaPlan::find($this->conekta_id);
        
        if (! $plan) {
            throw new LogicException('The Conekta plan doesnt exists');
        }

        return $plan;
    }
}
