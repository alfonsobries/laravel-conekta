<?php

namespace Alfonsobries\ConektaCashier;

use Illuminate\Support\ServiceProvider;

class LaravelConektaCashierServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        // If the config file exists means that the package was already published
        if (file_exists(config_path('conekta.php'))) {
            return;
        }

        $this->publishes([
            $this->getConfigFile() => config_path('conekta.php'),
        ], 'config');

        $migrations_path = __DIR__. DIRECTORY_SEPARATOR. '..'. DIRECTORY_SEPARATOR. 'database'. DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;
        $this->publishes([
            $migrations_path . 'add_conekta_data_to_billable.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_add_conekta_data_to_billable.php'),
            $migrations_path . 'create_subscriptions_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_subscriptions_table.php'),
            $migrations_path . 'create_plans_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_plans_table.php'),
        ], 'migrations');
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->mergeConfigFrom(
            $this->getConfigFile(),
            'conekta'
        );
    }

    /**
     * @return string
     */
    protected function getConfigFile(): string
    {
        return __DIR__
            . DIRECTORY_SEPARATOR
            . '..'
            . DIRECTORY_SEPARATOR
            . 'config'
            . DIRECTORY_SEPARATOR
            . 'conekta.php';
    }
}
