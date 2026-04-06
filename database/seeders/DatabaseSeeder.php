<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\OfficeSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'username' => 'test@example.com',
        // ]);

        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            CriteriaLibraryASeeder::class,
            CriteriaLibraryBSeeder::class,
            CriteriaLibraryCSeeder::class,
            OfficeSeeder::class,
            // Add other seeders here
        ]);


    }
}
