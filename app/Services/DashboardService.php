<?php

namespace App\Services;

use App\Models\Submission;
use App\Models\vwplantillastructure;

class DashboardService
{


    // status of applicant
    public function applicantStatus()
    {
        // count total of applicant each status
        $qualified = Submission::where('status', 'qualified')->count();
        $pending = Submission::where('status', 'pending')->count();
        $unqualified = Submission::where('status', 'unqualified')->count();

        // count the total of applicant
        $total = Submission::count();

        return response()->json([
            'qualified' => $qualified,
            'pending' => $pending,
            'unqualified' => $unqualified,
            'total' => $total,
        ]);
    }

    // employee total and status
    // funded and unfunded position
    // total occupied and unccupied position
    public function plantillaData()
    {
        $funded = vwplantillastructure::where('Funded', true)->count();
        $unfunded = vwplantillastructure::where('Funded', false)->count();
        $occupied = vwplantillastructure::where('Funded', true)
            ->whereNotNull('ControlNo')
            ->count();
        $unoccupied = vwplantillastructure::where('Funded', true)
            ->whereNull('ControlNo')
            ->count();
        $total = vwplantillastructure::count();

        return response()->json([
            'funded' => $funded,
            'unfunded' => $unfunded,
            'occupied' => $occupied,
            'unoccupied' => $unoccupied,
            'total' => $total,
        ]);
    }
}
