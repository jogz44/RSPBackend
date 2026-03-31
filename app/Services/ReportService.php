<?php

namespace App\Services;

use App\Jobs\GeneratePlantillaReportJob;
use App\Jobs\QueueWorkerTestJob;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class ReportService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }


    // Generate Report on plantilla Structure...
    public function dbm()
    {
        $eligibility = DB::table('xCivilService')
            ->select(
                'ControlNo',
                DB::raw("STRING_AGG(CivilServe, ', ') as eligibility")
            )
            ->groupBy('ControlNo');

        // actual salary
        $latestXService = DB::table('xService')
            ->select('ControlNo', DB::raw('MAX(PMID) as latest_pmid'))
            ->groupBy('ControlNo');


        $rows = DB::table('vwplantillastructure as p')
            ->leftJoin('vwActive as a', 'a.ControlNo', '=', 'p.ControlNo')
            ->leftJoin('vwofficearrangement as o', 'o.Office', '=', 'p.office')

            // join ALL eligibilities
            ->leftJoinSub($eligibility, 'eligibility', function ($join) {
                $join->on('eligibility.ControlNo', '=', 'p.ControlNo');
            })


            // join latest PMID per employee
            ->leftJoinSub($latestXService, 'lx', function ($join) {
                $join->on('lx.ControlNo', '=', 'p.ControlNo');
            })

            // join xService using latest PMID
            ->leftJoin('xService as s', 's.PMID', '=', 'lx.latest_pmid')

            ->select(
                'p.*',
                'a.Status as status',
                'a.Steps as steps',
                'a.Birthdate as birthdate',
                'a.Surname as lastname',
                'a.Firstname as firstname',
                'a.MIddlename as middlename',
                'p.SG as salarygrade',
                'p.level',
                'p.Funded',
                'o.office_sort',
                's.RateYear as rateyear', // ✅ correct RateYear
                'eligibility.eligibility'
            )
            ->orderBy('o.office_sort')
            ->orderBy('p.office2')
            ->orderBy('p.group')
            ->orderBy('p.division')
            ->orderBy('p.section')
            ->orderBy('p.unit')
            ->orderBy('p.ItemNo')
            ->get();


        if ($rows->isEmpty()) {
            return response()->json([]);
        }

        $allControlNos = $rows->pluck('ControlNo')->filter()->unique()->values();

        $xServices = DB::table('xService')
            ->whereIn('ControlNo', $allControlNos)
            ->select('ControlNo', 'Status', 'Steps', 'FromDate', 'ToDate', 'Designation', 'SepCause', 'Grades')
            ->get();

        $xServiceByControl = $xServices->groupBy('ControlNo');

        // $result = [];
        $result = [];
        $resultFunded = [];
        $resultUnfunded = [];

        //     foreach ($rows->groupBy('office') as $officeName => $officeRows) {
        //         $officeSort = $officeRows->first()->office_sort;
        //         $officeLevel = $officeRows->first()->level;

        //         $officeData = [
        //             'office'      => $officeName,
        //             'level'       => $officeLevel,
        //             'office_sort' => $officeSort,
        //             'employees'   => [],
        //             'office2'     => []
        //         ];

        //         $officeEmployees = $officeRows->filter(
        //             fn($r) =>
        //             is_null($r->office2) &&
        //                 is_null($r->group) &&
        //                 is_null($r->division) &&
        //                 is_null($r->section) &&
        //                 is_null($r->unit)
        //         );
        //         $officeData['employees'] = $officeEmployees
        //             ->sortBy('ItemNo')
        //             // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
        //             ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))

        //             ->values();

        //         $remainingOfficeRows = $officeRows->reject(
        //             fn($r) =>
        //             is_null($r->office2) &&
        //                 is_null($r->group) &&
        //                 is_null($r->division) &&
        //                 is_null($r->section) &&
        //                 is_null($r->unit)
        //         );

        //         foreach ($remainingOfficeRows->groupBy('office2') as $office2Name => $office2Rows) {
        //             $office2Data = [
        //                 'office2'   => $office2Name,
        //                 'employees' => [],
        //                 'groups'    => []
        //             ];

        //             $office2Employees = $office2Rows->filter(
        //                 fn($r) =>
        //                 is_null($r->group) &&
        //                     is_null($r->division) &&
        //                     is_null($r->section) &&
        //                     is_null($r->unit)
        //             );
        //             $office2Data['employees'] = $office2Employees
        //                 ->sortBy('ItemNo')
        //                 // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
        //                 ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))

        //                 ->values();

        //             $remainingOffice2Rows = $office2Rows->reject(
        //                 fn($r) =>
        //                 is_null($r->group) &&
        //                     is_null($r->division) &&
        //                     is_null($r->section) &&
        //                     is_null($r->unit)
        //             );

        //             foreach ($remainingOffice2Rows->groupBy('group') as $groupName => $groupRows) {
        //                 $groupData = [
        //                     'group'     => $groupName,
        //                     'employees' => [],
        //                     'divisions' => []
        //                 ];

        //                 $groupEmployees = $groupRows->filter(
        //                     fn($r) =>
        //                     is_null($r->division) &&
        //                         is_null($r->section) &&
        //                         is_null($r->unit)
        //                 );
        //                 $groupData['employees'] = $groupEmployees
        //                     ->sortBy('ItemNo')
        //                     // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
        //                     ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))

        //                     ->values();

        //                 $remainingGroupRows = $groupRows->reject(
        //                     fn($r) =>
        //                     is_null($r->division) &&
        //                         is_null($r->section) &&
        //                         is_null($r->unit)
        //                 );

        //                 // ----- SORT HERE by divordr -----
        //                 foreach ($remainingGroupRows->sortBy('divordr')->groupBy('division') as $divisionName => $divisionRows) {
        //                     $divisionData = [
        //                         'division'  => $divisionName,
        //                         'employees' => [],
        //                         'sections'  => []
        //                     ];

        //                     $divisionEmployees = $divisionRows->filter(
        //                         fn($r) =>
        //                         is_null($r->section) &&
        //                             is_null($r->unit)
        //                     );
        //                     $divisionData['employees'] = $divisionEmployees
        //                         ->sortBy('ItemNo')
        //                         // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
        //                         ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))

        //                         ->values();

        //                     $remainingDivisionRows = $divisionRows->reject(
        //                         fn($r) =>
        //                         is_null($r->section) &&
        //                             is_null($r->unit)
        //                     );

        //                     // ----- SORT HERE by secordr -----
        //                     foreach ($remainingDivisionRows->sortBy('secordr')->groupBy('section') as $sectionName => $sectionRows) {
        //                         $sectionData = [
        //                             'section'   => $sectionName,
        //                             'employees' => [],
        //                             'units'     => []
        //                         ];

        //                         $sectionEmployees = $sectionRows->filter(
        //                             fn($r) =>
        //                             is_null($r->unit)
        //                         );
        //                         $sectionData['employees'] = $sectionEmployees
        //                             ->sortBy('ItemNo')
        //                             // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
        //                             ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))

        //                             ->values();

        //                         $remainingSectionRows = $sectionRows->reject(
        //                             fn($r) =>
        //                             is_null($r->unit)
        //                         );

        //                         // ----- SORT HERE by unitordr -----
        //                         foreach ($remainingSectionRows->sortBy('unitordr')->groupBy('unit') as $unitName => $unitRows) {
        //                             $sectionData['units'][] = [
        //                                 'unit'      => $unitName,
        //                                 'employees' => $unitRows
        //                                     ->sortBy('ItemNo')
        //                                     // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
        //                                     ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))

        //                                     ->values()
        //                             ];
        //                         }

        //                         $divisionData['sections'][] = $sectionData;
        //                     }

        //                     $groupData['divisions'][] = $divisionData;
        //                 }

        //                 $office2Data['groups'][] = $groupData;
        //             }

        //             $officeData['office2'][] = $office2Data;
        //         }

        //         $result[] = $officeData;
        //     }

        //     $result = collect($result)->sortBy('office_sort')->values()->all();

        //     return response()->json($result);
        // }

        // Helper closure to build the nested office structure
        $buildStructure = function ($filteredRows) use ($xServiceByControl) {
            $output = [];

            foreach ($filteredRows->groupBy('office') as $officeName => $officeRows) {
                $officeSort  = $officeRows->first()->office_sort;
                $officeLevel = $officeRows->first()->level;

                $officeData = [
                    'office'      => $officeName,
                    'level'       => $officeLevel,
                    'office_sort' => $officeSort,
                    'employees'   => [],
                    'office2'     => []
                ];

                $officeEmployees = $officeRows->filter(
                    fn($r) =>
                    is_null($r->office2) &&
                        is_null($r->group) &&
                        is_null($r->division) &&
                        is_null($r->section) &&
                        is_null($r->unit)
                );
                $officeData['employees'] = $officeEmployees
                    ->sortBy('ItemNo')
                    ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))
                    ->values();

                $remainingOfficeRows = $officeRows->reject(
                    fn($r) =>
                    is_null($r->office2) &&
                        is_null($r->group) &&
                        is_null($r->division) &&
                        is_null($r->section) &&
                        is_null($r->unit)
                );

                foreach ($remainingOfficeRows->groupBy('office2') as $office2Name => $office2Rows) {
                    $office2Data = [
                        'office2'   => $office2Name,
                        'employees' => [],
                        'groups'    => []
                    ];

                    $office2Employees = $office2Rows->filter(
                        fn($r) =>
                        is_null($r->group) &&
                            is_null($r->division) &&
                            is_null($r->section) &&
                            is_null($r->unit)
                    );
                    $office2Data['employees'] = $office2Employees
                        ->sortBy('ItemNo')
                        ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))
                        ->values();

                    $remainingOffice2Rows = $office2Rows->reject(
                        fn($r) =>
                        is_null($r->group) &&
                            is_null($r->division) &&
                            is_null($r->section) &&
                            is_null($r->unit)
                    );

                    foreach ($remainingOffice2Rows->groupBy('group') as $groupName => $groupRows) {
                        $groupData = [
                            'group'     => $groupName,
                            'employees' => [],
                            'divisions' => []
                        ];

                        $groupEmployees = $groupRows->filter(
                            fn($r) =>
                            is_null($r->division) &&
                                is_null($r->section) &&
                                is_null($r->unit)
                        );
                        $groupData['employees'] = $groupEmployees
                            ->sortBy('ItemNo')
                            ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))
                            ->values();

                        $remainingGroupRows = $groupRows->reject(
                            fn($r) =>
                            is_null($r->division) &&
                                is_null($r->section) &&
                                is_null($r->unit)
                        );

                        foreach ($remainingGroupRows->sortBy('divordr')->groupBy('division') as $divisionName => $divisionRows) {
                            $divisionData = [
                                'division'  => $divisionName,
                                'employees' => [],
                                'sections'  => []
                            ];

                            $divisionEmployees = $divisionRows->filter(
                                fn($r) =>
                                is_null($r->section) &&
                                    is_null($r->unit)
                            );
                            $divisionData['employees'] = $divisionEmployees
                                ->sortBy('ItemNo')
                                ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))
                                ->values();

                            $remainingDivisionRows = $divisionRows->reject(
                                fn($r) =>
                                is_null($r->section) &&
                                    is_null($r->unit)
                            );

                            foreach ($remainingDivisionRows->sortBy('secordr')->groupBy('section') as $sectionName => $sectionRows) {
                                $sectionData = [
                                    'section'   => $sectionName,
                                    'employees' => [],
                                    'units'     => []
                                ];

                                $sectionEmployees = $sectionRows->filter(fn($r) => is_null($r->unit));
                                $sectionData['employees'] = $sectionEmployees
                                    ->sortBy('ItemNo')
                                    ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))
                                    ->values();

                                $remainingSectionRows = $sectionRows->reject(fn($r) => is_null($r->unit));

                                foreach ($remainingSectionRows->sortBy('unitordr')->groupBy('unit') as $unitName => $unitRows) {
                                    $sectionData['units'][] = [
                                        'unit'      => $unitName,
                                        'employees' => $unitRows
                                            ->sortBy('ItemNo')
                                            ->map(fn($r) => $this->mapEmployeeDbm($r, $xServiceByControl))
                                            ->values()
                                    ];
                                }

                                $divisionData['sections'][] = $sectionData;
                            }

                            $groupData['divisions'][] = $divisionData;
                        }

                        $office2Data['groups'][] = $groupData;
                    }

                    $officeData['office2'][] = $office2Data;
                }

                $output[] = $officeData;
            }

            return collect($output)->sortBy('office_sort')->values()->all();
        };

        // ✅ Split rows by Funded flag
        $fundedRows   = $rows->filter(fn($r) => (int) $r->Funded === 1);
        $unfundedRows = $rows->filter(fn($r) => (int) $r->Funded === 0);

        $resultFunded   = $buildStructure($fundedRows);
        $resultUnfunded = $buildStructure($unfundedRows);

        return response()->json([
            'funded'   => $resultFunded,
            'unfunded' => $resultUnfunded,
        ]);
}
    // Update the mapEmployeeDbm function call
    private function mapEmployeeDbm($row, $xServiceByControl)
    {
        $controlNo = $row->ControlNo;
        $status = $row->status;

        $dateOriginalAppointed = null;
        $dateLastPromotion = null;

        if ($controlNo && isset($xServiceByControl[$controlNo])) {
            $xList = $xServiceByControl[$controlNo]
                ->filter(fn($svc) => $svc->Status == $status)
                ->sortBy('FromDate')
                ->values();

            if ($xList->count()) {
                if (strtolower($status) === 'regular') {
                    $first = $xList->first();
                    $designation = $first->Designation ?? null;

                    $resignedRows = $xList->filter(function ($svc) use ($designation) {
                        return (
                            ($svc->Designation ?? null) == $designation
                            && isset($svc->SepCause)
                            && strtolower(trim($svc->SepCause)) === 'resigned'
                        );
                    });

                    if ($resignedRows->count()) {
                        $resignedToDate = $resignedRows->sortByDesc('ToDate')->first()->ToDate;
                        $nextRow = $xList
                            ->filter(fn($svc) => strtotime($svc->FromDate) > strtotime($resignedToDate))
                            ->sortBy(fn($svc) => strtotime($svc->FromDate) - strtotime($resignedToDate))
                            ->first();
                        $dateOriginalAppointed = $nextRow ? $nextRow->FromDate : null;
                    } else {
                        $dateOriginalAppointed = $first->FromDate;
                    }
                } else {
                    $dateOriginalAppointed = $xList->last()->FromDate;
                }

                // Promotion logic
                $numericGrades = $xList->pluck('Grades')->filter(function ($g) {
                    return is_numeric($g);
                })->map(function ($g) {
                    return (float)$g;
                });

                $highestGrade = $numericGrades->max();
                $appointedRow = $xList->first(fn($svc) => $svc->FromDate == $dateOriginalAppointed);
                $initialGrades = !is_null($appointedRow) ? $appointedRow->Grades : ($row->Grades ?? null);

                if (!is_null($dateOriginalAppointed) && !is_null($highestGrade) && !is_null($initialGrades)) {
                    if ($initialGrades >= $highestGrade) {
                        $dateLastPromotion = null;
                    } else {
                        $promotionRows = $xList
                            ->filter(fn($svc) => $svc->Grades == $highestGrade)
                            ->sortBy('FromDate')
                            ->values();

                        $dateLastPromotion = $promotionRows->count() ? $promotionRows->first()->FromDate : null;
                    }
                }
            }
        }

        // VACANT → FORCE ZERO
        if (is_null($controlNo)) {
            return [
                'controlNo'   => null,
                'Ordr'        => $row->Ordr,
                'itemNo'      => $row->ItemNo,
                'position'    => $row->position,
                'lastname'    => 'VACANT',
                'firstname'   => '',
                'middlename'  => '',
                'birthdate'   => '',
                'currentYearSalaryGrade' => $row->salarygrade,
                'currentYearAmount'      => '0.00',
                'currentYearStep'        => '1',
                'budgetYearSalaryGrade'  => $row->salarygrade,
                'budgetYearAmount'       => '0.00',
                'budgetYearStep'         => '1',
                'increaseDescrease'      => '0.00',
                'dateOriginalAppointed' => null,
                'dateLastPromotion'     => null,
                'status'      => 'VACANT',
                'funded'      => $row->Funded,
            ];
        }

        // X-SERVICE DATA
        $xList = collect();
        if (isset($xServiceByControl[$controlNo])) {
            $xList = $xServiceByControl[$controlNo];
        }

        // CURRENT STEP
        $currentStep = (int) ($row->steps ?? 1);

        // CURRENT POSITION
        $currentPosition = $row->position;

        // COMPUTE BUDGET YEAR STEP (Pass current position)
        $budgetYearStep = $this->computeBudgetYearStep($xList, $currentStep, $currentPosition);

        // CURRENT YEAR AMOUNT
        $currentYearAmount = number_format($row->rateyear ?? 0, 2);

        // BUDGET YEAR AMOUNT
        $budgetMonthly = DB::table('tblSalarySchedule')
            ->where('Grade', $row->salarygrade)
            ->where('Steps', $budgetYearStep)
            ->value('Salary');

        $budgetYearAmount = $budgetMonthly
            ? number_format($budgetMonthly * 12, 2)
            : '0.00';

        $currentAnnualRaw = (float) str_replace(',', '', $currentYearAmount);
        $budgetAnnualRaw  = (float) str_replace(',', '', $budgetYearAmount);
        $increaseDecrease = $budgetAnnualRaw - $currentAnnualRaw;

        // RETURN DATA
        return [
            'controlNo'   => $controlNo,
            'Ordr'        => $row->Ordr,
            'itemNo'      => $row->ItemNo,
            'position'    => $row->position,
            'lastname'    => $row->lastname,
            'firstname'   => $row->firstname,
            'middlename'  => $row->middlename,
            'birthdate'   => $row->birthdate,
            'currentYearSalaryGrade' => $row->salarygrade,
            'currentYearAmount'      => $currentYearAmount,
            'currentYearStep'        => $currentStep,
            'budgetYearSalaryGrade'  => $row->salarygrade,
            'budgetYearAmount'       => $budgetYearAmount,
            'budgetYearStep'         => $budgetYearStep,
            'increaseDescrease' => number_format($increaseDecrease, 2),
            'dateOriginalAppointed' => $dateOriginalAppointed ?? null,
            'dateLastPromotion'     => $dateLastPromotion ?? null,
            'eligibility' => $row->eligibility,
            'status'      => $row->status,
            'funded'      => $row->Funded,
        ];
    }


    private function computeBudgetYearStep($xList, $currentStep)
    {
        $allowedStatuses = ['regular', 'elective', 'co-terminous'];

        if ($xList->isEmpty()) {
            return $currentStep;
        }

        // newest first
        $xList = $xList->sortByDesc('FromDate')->values();

        // current active service
        $currentService = $xList->first(function ($svc) use ($allowedStatuses) {
            return in_array(strtolower($svc->Status), $allowedStatuses)
                && !empty($svc->Designation);
        });

        if (!$currentService) {
            return $currentStep;
        }

        $designation = $currentService->Designation;
        $step = (int) ($currentService->Steps ?? $currentStep);

        // SAME position + SAME step only
        $samePosSameStep = $xList->filter(function ($svc) use (
            $designation,
            $step,
            $allowedStatuses
        ) {
            return $svc->Designation === $designation
                && (int)($svc->Steps ?? 0) === $step
                && in_array(strtolower($svc->Status), $allowedStatuses);
        })->sortBy('FromDate')->values();

        if ($samePosSameStep->isEmpty()) {
            return $currentStep;
        }

        // earliest SAME position + SAME step
        $startYear = (int) Carbon::parse($samePosSameStep->first()->FromDate)->year;

        // 🔥 CURRENT YEAR (not budget param)
        $currentYear = (int) Carbon::now()->year;

        // inclusive count (ex: 2024–2026 = 3)
        $yearsRendered = ($currentYear - $startYear) + 1;

        // DBM rule: after 3 years → +1 step
        if ($yearsRendered >= 3) {
            return $currentStep + 1;
        }

        return $currentStep;
    }

    // generate report plantilla
    public function plantilla()
    {

        // ✅ Check if queue worker is running BEFORE dispatching
        // if (!$this->isQueueWorkerRunning()) {
        //     return response()->json([
        //         'status' => 'error',
        //         'message' => 'Queue worker is not running. Please contact Deniel Tomenio for this issue.'
        //     ], 503);
        // }


        $jobId = Str::uuid()->toString();

        // Initialize job status
        Cache::put("plantilla_job_{$jobId}", [
            'status' => 'queued',
            'progress' => 0
        ], 600); // 10 minutes TTL

        // Dispatch the job
        GeneratePlantillaReportJob::dispatch($jobId)->onQueue('reports');

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued'
        ]);
    }


    // private function isQueueWorkerRunning()
    // {
    //     try {
    //         // Method 1: Check if we can connect to queue
    //         $connection = config('queue.default');
    //         $queueName = config("queue.connections.{$connection}.queue", 'default');

    //         // Try to get queue size (this will fail if Redis/database is not available)
    //         Queue::size($queueName);

    //         // Method 2: Check for active workers by trying to dispatch a test
    //         // This is optional but more reliable
    //         return $this->checkForActiveWorkers();
    //     } catch (\Exception $e) {
    //         Log::error('Queue check failed: ' . $e->getMessage());
    //         return false;
    //     }
    // }


    /**
     * Check if there are active queue workers by looking at recent job processing
     */
    // private function checkForActiveWorkers()
    // {
    //     // Create a test marker
    //     $testKey = 'queue_worker_test_' . now()->timestamp;
    //     Cache::put($testKey, 'waiting', 10); // 10 seconds TTL

    //     // Dispatch a test job
    //     QueueWorkerTestJob::dispatch($testKey)->onQueue('reports');

    //     // Wait a moment and check if job was processed
    //     sleep(2);

    //     $result = Cache::get($testKey);

    //     // If the test job ran, it would have changed this value
    //     return $result === 'processed';
    // }

    // public function checkQueueWorkerStatus()
    // {
    //     $isRunning = $this->isQueueWorkerRunning();

    //     return response()->json([
    //         'is_running' => $isRunning,
    //         'message' => $isRunning
    //             ? 'Queue worker is running'
    //             : 'Queue worker is not running'
    //     ]);
    // }

    // plantilla status
    public function status($jobId)
    {
        $status = Cache::get("plantilla_job_{$jobId}");

        if (!$status) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($status);
    }

    // cancel generate report
    public function cancel($jobId)
    {
        $status = Cache::get("plantilla_job_{$jobId}");

        if (!$status) {
            return response()->json(['status' => 'not_found'], 404);
        }

        // Update status to cancelled
        Cache::put("plantilla_job_{$jobId}", [
            'status' => 'cancelled',
            'progress' => $status['progress'] ?? 0
        ], 600);

        return response()->json([
            'status' => 'cancelled',
            'message' => 'Job cancellation requested'
        ]);
    }



    // applicant final summary of rating qulification standard
    public function applicantFinalScore($jobpostId)
    {
        $records = rating_score::select(
            'rating_score.id',
            'rating_score.user_id as rater_id',
            // 'rating_score.rater_name',
            'rater.name as rater_name',

            'rater.position as rater_position',
            'rating_score.nPersonalInfo_id',
            'rating_score.ControlNo',
            'rating_score.education_score as education',
            'rating_score.experience_score as experience',
            'rating_score.training_score as training',
            'rating_score.performance_score as performance',
            'rating_score.behavioral_score as bei',
            'rating_score.total_qs',
            'rating_score.grand_total',
            'rating_score.ranking',
            'nPersonalInfo.firstname',
            'nPersonalInfo.lastname',
            'nPersonalInfo.image_path',
            'jobpost.id as job_batches_rsp_id',
            'jobpost.Position',
            'jobpost.Office',
            'jobpost.SalaryGrade',
            'jobpost.ItemNo'
        )
            ->leftJoin('users as rater', 'rater.id', '=', 'rating_score.user_id')
            ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
            ->leftJoin('job_batches_rsp as jobpost', 'jobpost.id', '=', 'rating_score.job_batches_rsp_id')
            ->where('rating_score.job_batches_rsp_id', $jobpostId)
            ->get();

        if ($records->isEmpty()) {
            return response()->json(['message' => 'Unable to generate report. This applicant has not been rated yet'], 404);
        }

        // Job post info (same for all)
        $jobPost = [
            'job_batches_rsp_id' => $jobpostId,
            'Office'      => $records->first()->Office,
            'Position'    => $records->first()->Position,
            'SalaryGrade' => $records->first()->SalaryGrade,
            'Plantilla Item No'      => $records->first()->ItemNo,
        ];

        // Group by applicant
        $applicants = $records
            ->groupBy('nPersonalInfo_id')
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'applicant' => [
                        'nPersonalInfo_id' => (string) $first->nPersonalInfo_id,
                        'ControlNo'        => $first->ControlNo,
                        'firstname'        => $first->firstname,
                        'lastname'         => $first->lastname,
                        'image_url'        => $first->image_path
                            ? config('app.url') . '/storage/' . $first->image_path
                            : null,
                    ],
                    'score' => $rows->map(fn($row) => [
                        'id'          => $row->id,
                        'rater_id'    => $row->rater_id,
                        'rater_name'  => $row->rater_name,
                        'rater_position'  => $row->rater_position,
                        'education'   => $row->education,
                        'experience'  => $row->experience,
                        'training'    => $row->training,
                        'performance' => $row->performance,
                        'bei'         => $row->bei,
                        'total_qs'    => $row->total_qs,
                        'grand_total' => $row->grand_total,
                        // 'ranking'     => $row->ranking,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'jobPost'    => $jobPost,
            'applicants' => $applicants,
        ]);
    }


    // Palacement list
    public function list($office)
    {
        $previousService = DB::table(DB::raw("
        (
            SELECT
                ControlNo,
                Designation,
                Grades,
                Renew,
                FromDate,
                ToDate,
                ROW_NUMBER() OVER (
                    PARTITION BY ControlNo
                    ORDER BY FromDate DESC
                ) AS rn
            FROM xService
        ) AS xs
    "))
            ->where('xs.rn', 2); // previous record

        $officeData = DB::table('vwplantillaStructure as vps')
            ->leftJoinSub($previousService, 'prev', function ($join) {
                $join->on('prev.ControlNo', '=', 'vps.ControlNo');
            })
            ->where('vps.office', $office)
            ->select(
                'vps.office',
                'vps.ItemNo',
                'vps.position',
                'vps.SG',
                'vps.ControlNo',
                'vps.Name4',

                'prev.Designation as previous_designation',
                'prev.Grades as previous_grade',
                'prev.Renew as Nature of Movement',

                // 'prev.FromDate as FromDate',
                // 'prev.ToDate as ToDate'
            )
            ->orderByRaw('CAST(vps.ItemNo AS INT) ASC') // ✅ IMPORTANT
            ->get();

        return response()->json([
            'office' => $officeData,
        ]);
    }


    // top 5 ranking applicant date publication
    public function topApplicant($postDate)
    {
        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
            ->select(
                'id',
                'Office',
                'Division',
                'Section',
                'Position',
                'SalaryGrade',
                'ItemNo'
            )
            ->get();

        $offices = [];

        foreach ($jobPosts as $jobPost) {

            // ===== Fetch rating scores per job post =====
            $allScores = rating_score::select(
                'rating_score.id',
                'rating_score.nPersonalInfo_id',
                'rating_score.ControlNo',
                'rating_score.job_batches_rsp_id',
                'rating_score.education_score as education',
                'rating_score.experience_score as experience',
                'rating_score.training_score as training',
                'rating_score.performance_score as performance',
                'rating_score.behavioral_score as bei',
                'nPersonalInfo.firstname',
                'nPersonalInfo.lastname'
            )
                ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
                ->where('rating_score.job_batches_rsp_id', $jobPost->id)
                ->get();

            // ===== Group by applicant =====
            $scoresByApplicant = $allScores->groupBy(
                fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo
            );

            $applicants = [];

            foreach ($scoresByApplicant as $rows) {
                $first = $rows->first();

                $scoresArray = $rows->map(fn($row) => [
                    'education'   => (float) $row->education,
                    'experience'  => (float) $row->experience,
                    'training'    => (float) $row->training,
                    'performance' => (float) $row->performance,
                    'bei'         => (float) $row->bei,
                ])->toArray();

                $computed = RatingService::computeFinalScore($scoresArray);

                $applicants[] = [
                    'nPersonalInfo_id' => $first->nPersonalInfo_id,
                    'ControlNo'        => $first->ControlNo,
                    'firstname'        => $first->firstname,
                    'lastname'         => $first->lastname,
                ] + $computed;
            }

            // ===== Top 5 applicants =====
            $topApplicants = collect(
                RatingService::addRanking($applicants)
            )
                ->sortBy('ranking')
                ->take(5)
                ->values();

            // ===== Group by OFFICE =====
            if (!isset($offices[$jobPost->Office])) {
                $offices[$jobPost->Office] = [
                    'office' => $jobPost->Office,
                    'job_posts' => []
                ];
            }

            $offices[$jobPost->Office]['job_posts'][] = [
                'Division'       => $jobPost->Division,
                'Position'       => $jobPost->Position,
                'Salary Grade'   => $jobPost->SalaryGrade,
                'Plantilla Item No'        => $jobPost->ItemNo,
                'Top 5 Applicant' => $topApplicants
            ];
        }

        return response()->json([
            'Header'   => 'Top 5 Ranking Applicants',
            'Date' => "$postDate Publication",
            'Offices'   => array_values($offices)
        ]);
    }

    // list of qualified applicants  for job post publication
    public function listQualified($postDate)
    {
        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
            ->select('id', 'Position', 'ItemNo')
            ->with([
                'criteria:id,job_batches_rsp_id,Education,Experience,Training,Eligibility',
                'submissions' => function ($query) {
                    $query->select(
                        'id',
                        'job_batches_rsp_id',
                        'nPersonalInfo_id',
                        'ControlNo',
                        'status',
                        'education_qualification',  // [182,234,241]
                        'experience_qualification', // [12,334,241]
                        'training_qualification', // [12,334,241]
                        'eligibility_qualification', // [12,334,241]
                    )
                        ->where('status', 'Qualified')
                        ->with([
                            'nPersonalInfo:id,firstname,lastname',
                            // 'nPersonalInfo.education',
                            // 'nPersonalInfo.work_experience',
                            // 'nPersonalInfo.training',
                            // 'nPersonalInfo.eligibity',
                        ]);
                }
            ])
            ->get();


        $responseJobs = [];

        foreach ($jobPosts as $job) {

            $applicants = [];
            foreach ($job->submissions as $submission) {

                // =====================
                // INTERNAL
                // =====================
                if ($submission->nPersonalInfo_id) {

                    $educationRecords   = $submission->getEducationRecordsInternal();
                    $experienceRecords  = $submission->getExperienceRecordsInternal();
                    $trainingRecords    = $submission->getTrainingRecordsInternal();
                    $eligibilityRecords = $submission->getEligibilityRecordsInternal();

                    $applicants[] = [
                        'firstname' => $submission->nPersonalInfo->firstname,
                        'lastname'  => $submission->nPersonalInfo->lastname,
                        'status'    => $submission->status,
                        'applicant_status' => 'OUTSIDER',

                        'education'        => $educationRecords,
                        'experience'       => $experienceRecords,
                        'training'         => $trainingRecords,
                        'eligibility'      => $eligibilityRecords,

                        'education_text'   => $this->formatEducationForQualified($educationRecords),
                        'experience_text'  => $this->formatExperienceForQualified($experienceRecords),
                        'training_text'    => $this->formatTrainingForQualified($trainingRecords),
                        'eligibility_text' => $this->formatEligibilityForQualified($eligibilityRecords),
                    ];
                }

                // =====================
                // EXTERNAL
                // =====================
                elseif (!empty($submission->ControlNo)) {

                    $personal = DB::table('xPersonal')
                        ->where('ControlNo', $submission->ControlNo)
                        ->select('Firstname', 'Surname')
                        ->first();

                    // 🚨 SAFETY CHECK
                    if (!$personal) {
                        continue; // ❌ skip broken external record
                    }

                    $tempReorg = DB::table('tempRegAppointmentReorg')
                        ->where('ControlNo', $submission->ControlNo)
                        ->select('Office', 'Designation')
                        ->first();

                    $educationRecords   = $submission->getEducationRecordsExternal();
                    $experienceRecords  = $submission->getExperienceRecordsExternal();
                    $trainingRecords    = $submission->getTrainingRecordsExternal();
                    $eligibilityRecords = $submission->getEligibilityRecordsExternal();

                    $applicants[] = [
                        'controlno' => $submission->ControlNo,
                        'firstname' => $personal->Firstname,
                        'lastname'  => $personal->Surname,
                        'current_designation' => $tempReorg->Designation ?? null,
                        'office' => $tempReorg->Office ?? null,
                        'status' => $submission->status,
                        'applicant_status' => 'EXTERNAL',

                        'education'        => $educationRecords,
                        'experience'       => $experienceRecords,
                        'training'         => $trainingRecords,
                        'eligibility'      => $eligibilityRecords,

                        'education_text'   => $this->formatEducationForQualifiedExternal($educationRecords),
                        'experience_text'  => $this->formatExperienceForQualifiedExternal($experienceRecords),
                        'training_text'    => $this->formatTrainingForQualifiedExternal($trainingRecords),
                        'eligibility_text' => $this->formatEligibilityForQualifiedExternal($eligibilityRecords),
                    ];
                }
            }


            // ✅ BUILD FINAL JOB OBJECT (ORDER GUARANTEED)
            $responseJobs[] = [
                'id' => $job->id,
                'Position' => $job->Position,
                'ItemNo' => $job->ItemNo,
                'criteria' => $job->criteria,
                'applicants' => $applicants
            ];
        }

        return response()->json([
            'Header' => 'Applicants Qualified Standard',
            'Date' => "$postDate Publication",
            'jobPosts' => $responseJobs
        ]);
    }



    // list of qualified applicants  for job post publication
    public function listUnQualified($postDate)
    {
        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
            ->select('id', 'Position', 'ItemNo')
            ->with([
                'criteria:id,job_batches_rsp_id,Education,Experience,Training,Eligibility',
                'submissions' => function ($query) {
                    $query->select(
                        'id',
                        'job_batches_rsp_id',
                        'nPersonalInfo_id',
                        'ControlNo',
                        'status',
                        'education_remark',
                        'experience_remark',
                        'training_remark',
                        'eligibility_remark',
                        'education_qualification',  // [182,234,241]
                        'experience_qualification', // [12,334,241]
                        'training_qualification', // [12,334,241]
                        'eligibility_qualification', // [12,334,241]
                    )
                        ->where('status', 'Unqualified')
                        ->with([
                            'nPersonalInfo:id,firstname,lastname',
                            // 'nPersonalInfo.education',
                            // 'nPersonalInfo.work_experience',
                            // 'nPersonalInfo.training',
                            // 'nPersonalInfo.eligibity',
                        ]);
                }
            ])
            ->get();


        $responseJobs = [];

        foreach ($jobPosts as $job) {

            $applicants = [];
            foreach ($job->submissions as $submission) {

                // =====================
                // INTERNAL
                // =====================
                if ($submission->nPersonalInfo_id) {

                    $educationRecords   = $submission->getEducationRecordsInternal();
                    $experienceRecords  = $submission->getExperienceRecordsInternal();
                    $trainingRecords    = $submission->getTrainingRecordsInternal();
                    $eligibilityRecords = $submission->getEligibilityRecordsInternal();

                    $applicants[] = [
                        'firstname' => $submission->nPersonalInfo->firstname,
                        'lastname'  => $submission->nPersonalInfo->lastname,
                        'status'    => $submission->status,
                        'applicant_status' => 'OUTSIDER',

                        'education'        => $educationRecords,
                        'experience'       => $experienceRecords,
                        'training'         => $trainingRecords,
                        'eligibility'      => $eligibilityRecords,


                        'education_remark' => $submission->education_remark ?? null,
                        'experience_remark' => $submission->experience_remark ?? null,
                        'training_remark' => $submission->training_remark ??  null,
                        'eligibility_remark' => $submission->eligibility_remark ?? null,


                        'education_text'   => $this->formatEducationForQualified($educationRecords),
                        'experience_text'  => $this->formatExperienceForQualified($experienceRecords),
                        'training_text'    => $this->formatTrainingForQualified($trainingRecords),
                        'eligibility_text' => $this->formatEligibilityForQualified($eligibilityRecords),

                    ];
                }

                // =====================
                // EXTERNAL
                // =====================
                elseif (!empty($submission->ControlNo)) {

                    $personal = DB::table('xPersonal')
                        ->where('ControlNo', $submission->ControlNo)
                        ->select('Firstname', 'Surname')
                        ->first();

                    // 🚨 SAFETY CHECK
                    if (!$personal) {
                        continue; // ❌ skip broken external record
                    }

                    $tempReorg = DB::table('tempRegAppointmentReorg')
                        ->where('ControlNo', $submission->ControlNo)
                        ->select('Office', 'Designation')
                        ->first();

                    $educationRecords   = $submission->getEducationRecordsExternal();
                    $experienceRecords  = $submission->getExperienceRecordsExternal();
                    $trainingRecords    = $submission->getTrainingRecordsExternal();
                    $eligibilityRecords = $submission->getEligibilityRecordsExternal();

                    $applicants[] = [
                        'controlno' => $submission->ControlNo,
                        'firstname' => $personal->Firstname,
                        'lastname'  => $personal->Surname,
                        'current_designation' => $tempReorg->Designation ?? null,
                        'office' => $tempReorg->Office ?? null,
                        'status' => $submission->status,
                        'applicant_status' => 'EXTERNAL',

                        'education'        => $educationRecords,
                        'experience'       => $experienceRecords,
                        'training'         => $trainingRecords,
                        'eligibility'      => $eligibilityRecords,


                        'eligibility_remark' => $submission->eligibility_remark ?? null,
                        'training_remark' => $submission->training_remark ?? null,
                        'experience_remark' => $submission->experience_remark ?? null,
                        'education_remark' => $submission->education_remark ?? null,

                        'education_text'   => $this->formatEducationForQualifiedExternal($educationRecords),
                        'experience_text'  => $this->formatExperienceForQualifiedExternal($experienceRecords),
                        'training_text'    => $this->formatTrainingForQualifiedExternal($trainingRecords),
                        'eligibility_text' => $this->formatEligibilityForQualifiedExternal($eligibilityRecords),

                    ];
                }
            }


            // ✅ BUILD FINAL JOB OBJECT (ORDER GUARANTEED)
            $responseJobs[] = [
                'id' => $job->id,
                'Position' => $job->Position,
                'ItemNo' => $job->ItemNo,
                'criteria' => $job->criteria,
                'applicants' => $applicants
            ];
        }

        return response()->json([
            'Header' => 'Applicants UnQualified Standard',
            'Date' => "$postDate Publication",
            'jobPosts' => $responseJobs
        ]);

    }
    // formatting helpers for qualified applicants for the internal
    // Helper method to format education
    private function formatEducationForQualified($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return 'No relevant education.';
        }

        $formatted = [];
        foreach ($educationRecords as $edu) {
            $degree = $edu->degree ?? 'N/A';
            $unit = $edu->highest_units ?? 'N/A';
            // $year = $edu->year_graduated ?? 'N/A';
            $formatted[] = "• {$degree} ({$unit} units)";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format experience
    private function formatExperienceForQualified($experienceRecords)
    {
        if ($experienceRecords->isEmpty()) {
            return 'No relevant experience based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($experienceRecords as $exp) {
            $position = $exp->position_title ?? 'N/A';
            $department = $exp->department ?? 'N/A';
            $dateFrom = $exp->work_date_from ?? 'N/A';
            $dateTo = $exp->work_date_to ?? 'N/A';
            $formatted[] = "• {$position} at {$department} ({$dateFrom} - {$dateTo})";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format training
    private function formatTrainingForQualified($trainingRecords)
    {
        if ($trainingRecords->isEmpty()) {
            return 'No relevant training based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($trainingRecords as $training) {
            $title = $training->training_title ?? 'N/A';
            $hours = $training->number_of_hours ?? 'N/A';
            $formatted[] = "• {$title} ({$hours} hours)";
        }

        return implode('<br>', $formatted);
    }

    // ✅ Helper method to format eligibility
    private function formatEligibilityForQualified($eligibilityRecords)
    {
        if ($eligibilityRecords->isEmpty()) {
            return 'No relevant eligibility based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($eligibilityRecords as $eligibility) {
            $name = $eligibility->eligibility ?? 'N/A';
            $rating = $eligibility->rating ? " - Rating: {$eligibility->rating}" : '';
            $formatted[] = "• {$name}{$rating}";
        }

        return implode('<br>', $formatted);
    }


    // formatting helpers for qualified applicants for the external
    // Helper method to format education
    private function formatEducationForQualifiedExternal($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return 'No relevant education based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($educationRecords as $edu) {
            $degree = $edu->Degree ?? 'N/A';
            // $school = $edu->School ?? 'N/A';
            $unit = $edu->NumUnits ?? 'N/A';
            $formatted[] = "• {$degree} ({$unit} units)";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format experience
    private function formatExperienceForQualifiedExternal($experienceRecords)
    {
        if ($experienceRecords->isEmpty()) {
            return 'No relevant experience based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($experienceRecords as $exp) {
            $position = $exp->Wposition ?? 'N/A';
            $department = $exp->WCompany ?? 'N/A';
            $dateFrom = $exp->WFrom ?? 'N/A';
            $dateTo = $exp->WTo ?? 'N/A';
            $formatted[] = "• {$position} at {$department} ({$dateFrom} - {$dateTo})";
        }

        return implode('<br>', $formatted);
    }


    private function formatTrainingForQualifiedExternal($trainingRecords)
    {
        if ($trainingRecords->isEmpty()) {
            return 'No relevant training based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($trainingRecords as $training) {
            $title = $training->Training ?? 'N/A';
            $hours = $training->NumHours ?? 'N/A';
            $formatted[] = "• {$title} ({$hours} hours)";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format eligibility
    private function formatEligibilityForQualifiedExternal($eligibilityRecords)
    {
        if ($eligibilityRecords->isEmpty()) {
            return 'No relevant eligibility based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($eligibilityRecords as $eligibility) {
            $name = $eligibility->CivilServe ?? 'N/A';
            $rating = $eligibility->Rates ? " - Rating: {$eligibility->rating}" : '';
            $formatted[] = "• {$name}{$rating}";
        }

        return implode('<br>', $formatted);
    }



    // fetch the rating of the rater on the  specific job post
    public function ratingFormQualificationStandard($validated)
    {
        $user = Auth::user();

        $jobBatch = JobBatchesRsp::with([
            'ratingScores' => function ($query) use ($user){
                $query->select(
                    'id',
                    'user_id',
                    'nPersonalInfo_id',
                    'ControlNo',
                    'job_batches_rsp_id',
                    'education_score',
                    'experience_score',
                    'training_score',
                    'performance_score',
                    'behavioral_score',
                    'exam_score',
                    'total_qs',
                    'grand_total',
                    'ranking'
                )->where('user_id',$user->id)
                    // ✅ Eager load applicant names via nested with
                    ->with([
                        'internalApplicant' => fn($q) => $q->select('id', 'firstname', 'lastname', ),
                        'externalApplicant' => fn($q) => $q->select('ControlNo', 'Firstname', 'Surname'),
                    ]);
            },
            'criteriaRatings' => function ($query) {
                $query->select('id', 'job_batches_rsp_id')
                    ->with(['educations', 'experiences', 'trainings', 'performances', 'behaviorals']);
            }
        ])
            ->select('id', 'Office', 'Position', 'ItemNo')
            ->where('id', $validated['job_batches_rsp_id'])
            ->first();

        if (!$jobBatch) {
            return response()->json(['message' => 'Job batch not found'], 404);
        }

        // ✅ Flatten criteria — take first criteria_rating since it's per job batch
        $criteria = $jobBatch->criteriaRatings->first();

        return response()->json([
            'office'   => $jobBatch->Office,
            'position' => $jobBatch->Position,
            'item_no'  => $jobBatch->ItemNo,

            'criteria' => $criteria ? [
                'education'   => $criteria->educations,
                'experience'  => $criteria->experiences,
                'training'    => $criteria->trainings,
                'performance' => $criteria->performances,
                'behavioral'  => $criteria->behaviorals,
            ] : null,

            'rating_scores' => $jobBatch->ratingScores->map(fn($score) => [
                'nPersonalInfo_id' => $score->nPersonalInfo_id,
                'ControlNo'        => $score->ControlNo,

                // ✅ Resolve name — internal first, fallback to external
                'firstname'        => $score->internalApplicant?->firstname
                    ?? $score->externalApplicant?->Firstname
                    ?? null,
                'lastname'         => $score->internalApplicant?->lastname
                    ?? $score->externalApplicant?->Surname
                    ?? null,

                'education'        => $score->education_score,
                'experience'       => $score->experience_score,
                'training'         => $score->training_score,
                'performance'      => $score->performance_score,
                'behavioral'       => $score->behavioral_score,
                'exam'             => $score->exam_score,
                'total_qs'         => $score->total_qs,
                'grand_total'      => $score->grand_total,
                'ranking'          => $score->ranking,
            ]),
            // ✅ Rater info from auth user
            'rater_assigned' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'role' => $user->role ?? 'City Adminstrator' ,
                'representative' => $user->representative ?? 'Chairperson',
            ],

        ]);
    }
}
