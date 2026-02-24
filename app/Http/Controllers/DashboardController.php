<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use Illuminate\Http\Request;
use App\Models\JobBatchesRsp;
use App\Models\vwplantillastructure;
use App\Services\DashboardService;
use Illuminate\Container\Attributes\Auth;

class DashboardController extends Controller
{
    //


    // total of applicant
    // total of each status of applicant
    public function totalApplicantStatus(DashboardService $dashboardService)
    {
        $result = $dashboardService->applicantStatus();

        return $result;
    }

    // total of funded and unfunded
    // total of occupied and unoccupied
    // total of  employee
    public function getNumberOfPlantillaData(DashboardService $dashboardService)
    {

        $result = $dashboardService->plantillaData();

        return $result;
    }







}
