<?php

namespace Database\Factories;

use Crater\Models\ExchangeRateProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ExchangeRateProviderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ExchangeRateProvider::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'driver' => $this->faker->word,
            'key' => Str::random(10),
            'active' => $this->faker->randomElement([true, false]),
        ];
    }
}
