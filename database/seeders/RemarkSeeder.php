<?php

namespace Database\Seeders;

use App\Models\LibRemark;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RemarkSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            // EDUCATION
            ['remarks' => 'Does not meet Educational Requirement for the position', 'category' => 'EDUCATION'],
            ['remarks' => 'Meets Educational Requirement for the position',         'category' => 'EDUCATION'],

            // TRAINING
            ['remarks' => 'With Relevant Training',                                 'category' => 'TRAINING'],
            ['remarks' => 'Meets the training requirement for the position',         'category' => 'TRAINING'],
            ['remarks' => 'Does not meet the Training Requirement for the position', 'category' => 'TRAINING'],

            // EXPERIENCE
            ['remarks' => 'With Relevant Experience',                                'category' => 'EXPERIENCE'],
            ['remarks' => 'Meets the experience requirement for the position',        'category' => 'EXPERIENCE'],
            ['remarks' => 'Does not meet the Experience Requirement for the position','category' => 'EXPERIENCE'],

            // ELIGIBILITY
            ['remarks' => 'With Eligibility',          'category' => 'ELIGIBILITY'],
            ['remarks' => 'Without Eligibility',       'category' => 'ELIGIBILITY'],
            ['remarks' => 'Inappropriate Eligibility', 'category' => 'ELIGIBILITY'],
        ];

        $now = now();

        $data = array_map(fn($item) => array_merge($item, [
            'created_at' => $now,
            'updated_at' => $now,
        ]), $data);

        LibRemark::insert($data);
    }
}