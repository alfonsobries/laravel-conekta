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

    public static function createAsConektaPlan($plan_id, $attributes)
    {
        $attributes = array_merge(['id' => $plan_id], $attributes);

        try {
            $plan = ConektaPlan::find($plan_id);
            $plan->delete();
        } catch (\Exception $e) {
        }

        $plan = ConektaPlan::create($attributes);
        
        self::create(['conekta_id' => $plan->id]);

        return $plan;
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
