<?php

namespace Database\Seeders;

use App\Support\TopicTaxonomy;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        TopicTaxonomy::seed();

        if (app()->environment(['local', 'testing']) || env('SEED_DEMO', false)) {
            $this->call(DemoSeeder::class);
        }
    }
}
