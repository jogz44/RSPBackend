<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $offices = [
            ['office_name' => 'OFFICE OF THE CITY ACCOUNTANT'],
            ['office_name' => 'OFFICE OF THE CITY AGRICULTURIST'],
            ['office_name' => 'OFFICE OF THE CITY ARCHITECT'],
            ['office_name' => 'OFFICE OF THE CITY ASSESSOR'],
            ['office_name' => 'OFFICE OF THE CITY BUDGET OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY CIVIL REGISTRAR'],
            ['office_name' => 'OFFICE OF THE CITY DISASTER RISK REDUCTION AND MANAGEMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY ECONOMIC ENTERPRISES MANAGER'],
            ['office_name' => 'OFFICE OF THE CITY ENGINEER'],
            ['office_name' => 'OFFICE OF THE CITY ENVIRONMENT AND NATURAL RESOURCES OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY GENERAL SERVICES OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY HEALTH OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY HOUSING AND LAND MANAGEMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY INFORMATION AND COMMUNICATIONS TECHNOLOGY MANAGEMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY LEGAL OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY MAYOR'],
            ['office_name' => 'OFFICE OF THE CITY PLANNING AND DEVELOPMENT COORDINATOR'],
            ['office_name' => 'OFFICE OF THE CITY PUBLIC EMPLOYMENT SERVICES AND CAPABILITY DEVELOPMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY SOCIAL WELFARE AND DEVELOPMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY TOURISM, ARTS, CULTURE AND HERITAGE MANAGEMENT OFFICER'],
            ['office_name' => 'OFFICE OF THE CITY TREASURER'],
            ['office_name' => 'OFFICE OF THE CITY VETERINARIAN'],
            ['office_name' => 'OFFICE OF THE CITY VICE MAYOR / SANGGUNIANG PANLUNGSOD'],
        ];

        foreach ($offices as $office) {
            Office::create($office);
        }
    }
}
