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


            $funded = vwplantillastructure::where('Funded', true)->count();
            $unfunded = vwplantillastructure::where('Funded', false)->count();
            $occupied = vwplantillastructure::where('Funded', true)
                ->whereNotNull('ControlNo')
                ->count();
            $unoccupied = vwplantillastructure::where('Funded', true)
                ->whereNull('ControlNo')
                ->count();
            $total_positions = vwplantillastructure::count();


        // count the total of applicant
        $total = Submission::count();

        return response()->json([
            'qualified' => $qualified,
            'pending' => $pending,
            'unqualified' => $unqualified,
            'total_applicant' => $total,
            'funded' => $funded,
            'unfunded' => $unfunded,
            'occupied' => $occupied,
            'unoccupied' => $unoccupied,
            'total_positions' => $total_positions,
        ]);
    }

    // employee total and status
    // funded and unfunded position
    // total occupied and unccupied position
    // public function plantillaData()
    // {
    //     $funded = vwplantillastructure::where('Funded', true)->count();
    //     $unfunded = vwplantillastructure::where('Funded', false)->count();
    //     $occupied = vwplantillastructure::where('Funded', true)
    //         ->whereNotNull('ControlNo')
    //         ->count();
    //     $unoccupied = vwplantillastructure::where('Funded', true)
    //         ->whereNull('ControlNo')
    //         ->count();
    //     $total = vwplantillastructure::count();

    //     return response()->json([
    //         'funded' => $funded,
    //         'unfunded' => $unfunded,
    //         'occupied' => $occupied,
    //         'unoccupied' => $unoccupied,
    //         'total' => $total,
    //     ]);
    // }

    // public function plantillaData()
    // {
    //     $data = vwplantillastructure::selectRaw("
    //     COUNT(*) as total,
    //     SUM(CASE WHEN Funded = 1 THEN 1 ELSE 0 END) as funded,
    //     SUM(CASE WHEN Funded = 0 THEN 1 ELSE 0 END) as unfunded,
    //     SUM(CASE WHEN Funded = 1 AND ControlNo IS NOT NULL THEN 1 ELSE 0 END) as occupied,
    //     SUM(CASE WHEN Funded = 1 AND ControlNo IS NULL THEN 1 ELSE 0 END) as unoccupied
    // ")->first();

    //     return response()->json([
    //         'total'      => (int) $data->total,
    //         'funded'     => (int) $data->funded,
    //         'unfunded'   => (int) $data->unfunded,
    //         'occupied'   => (int) $data->occupied,
    //         'unoccupied' => (int) $data->unoccupied,
    //     ]);
    // }
}
