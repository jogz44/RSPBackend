<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GeneratePlantillaReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // public $timeout = 300; // 5 minutes
    public $timeout = 120; // 2 minutes
    protected $jobId;

    public function __construct($jobId)
    {
        $this->jobId = $jobId;
    }

    public function handle()
    {
        try {
            // ✅ Check if job was cancelled before starting
            if ($this->isCancelled()) {
                Log::info("Job {$this->jobId} was cancelled before execution");
                return;
            }

            $this->updateProgress(5, 'Starting report generation...');

            $result = $this->generateReport();

            // ✅ Check again before saving result
            if ($this->isCancelled()) {
                Log::info("Job {$this->jobId} was cancelled during execution");
                return;
            }

            $this->updateProgress(95, 'Finalizing report...');

            Cache::put("plantilla_job_{$this->jobId}", [
                'status' => 'completed',
                'progress' => 100,
                'message' => 'Report generated successfully',
                'data' => $result
            ], 600);
        } catch (\Exception $e) {
            Log::error('Plantilla generation failed: ' . $e->getMessage());

            Cache::put("plantilla_job_{$this->jobId}", [
                'status' => 'failed',
                'progress' => 0,
                'error' => $e->getMessage()
            ], 600);
        }
    }

    private function isCancelled()
    {
        $status = Cache::get("plantilla_job_{$this->jobId}");
        return $status && $status['status'] === 'cancelled';
    }

    private function updateProgress($percentage, $message = '')
    {
        Cache::put("plantilla_job_{$this->jobId}", [
            'status' => 'processing',
            'progress' => $percentage,
            'message' => $message
        ], 600);
    }

    private function generateReport()
    {
        // Step 1: Fetch main data (20% progress)
        $this->updateProgress(10, 'Fetching employee data...');

        $latestXService = DB::table('xService')
            ->select('ControlNo', DB::raw('MAX(PMID) as latest_pmid'))
            ->groupBy('ControlNo');

        $this->updateProgress(20, 'Processing plantilla structure...');

        $rows = DB::table('vwplantillastructure as p')
            ->leftJoin('vwActive as a', 'a.ControlNo', '=', 'p.ControlNo')
            ->leftJoin('vwofficearrangement as o', 'o.Office', '=', 'p.office')
            ->leftJoinSub($latestXService, 'lx', function ($join) {
                $join->on('lx.ControlNo', '=', 'p.ControlNo');
            })
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
                'o.office_sort',
                's.RateYear as rateyear'
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
            return [];
        }

        // Step 2: Fetch service records (30% progress)
        $this->updateProgress(30, 'Fetching service records...');

        $allControlNos = $rows->pluck('ControlNo')->filter()->unique()->values();

        $xServices = DB::table('xService')
            ->whereIn('ControlNo', $allControlNos)
            ->select('ControlNo', 'Status', 'Steps', 'FromDate', 'ToDate', 'Designation', 'SepCause', 'Grades')
            ->get();

        $xServiceByControl = $xServices->groupBy('ControlNo');

        // Step 3: Build organizational structure (40-80% progress)
        $this->updateProgress(40, 'Building organizational structure...');

        $result = [];
        $officeGroups = $rows->groupBy('office');
        $totalOffices = $officeGroups->count();
        $processedOffices = 0;

        foreach ($officeGroups as $officeName => $officeRows) {
            // Check for cancellation during processing
            if ($this->isCancelled()) {
                return [];
            }

            $officeSort = $officeRows->first()->office_sort;
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
                ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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
                    ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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
                        ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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
                            ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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

                            $sectionEmployees = $sectionRows->filter(
                                fn($r) => is_null($r->unit)
                            );

                            $sectionData['employees'] = $sectionEmployees
                                ->sortBy('ItemNo')
                                ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
                                ->values();

                            $remainingSectionRows = $sectionRows->reject(
                                fn($r) => is_null($r->unit)
                            );

                            foreach ($remainingSectionRows->sortBy('unitordr')->groupBy('unit') as $unitName => $unitRows) {
                                $sectionData['units'][] = [
                                    'unit'      => $unitName,
                                    'employees' => $unitRows
                                        ->sortBy('ItemNo')
                                        ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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

            $result[] = $officeData;

            // Update progress for each office processed (40-80%)
            $processedOffices++;
            $progress = 40 + (int)(($processedOffices / $totalOffices) * 40);
            $this->updateProgress($progress, "Processing office {$processedOffices}/{$totalOffices}...");
        }

        // Step 4: Final sorting (90% progress)
        $this->updateProgress(90, 'Sorting results...');
        $result = collect($result)->sortBy('office_sort')->values()->all();

        return $result;
    }

    private function mapEmployee($row, $xServiceByControl)
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

        if (is_null($controlNo)) {
            return [
                'controlNo'   => null,
                'Ordr'        => $row->Ordr,
                'itemNo'      => $row->ItemNo,
                'position'    => $row->position,
                'salarygrade' => $row->salarygrade,
                'authorized'  => '0.00',
                'actual'      => '0.00',
                'step'        => '1',
                'code'        => '11',
                'type'        => 'C',
                'level'       => $row->level,
                'lastname'    => 'VACANT',
                'firstname'   => '',
                'middlename'  => '',
                'birthdate'   => '',
                'funded'      => $row->Funded,
                'status'      => 'VACANT',
                'dateOriginalAppointed' => null,
                'dateLastPromotion'     => null,
            ];
        }

        $salaryGrade = $row->salarygrade;
        $monthlySalary = 0;

        if (!is_null($salaryGrade)) {
            $monthlySalary = DB::table('tblSalarySchedule')
                ->where('Grade', $salaryGrade)
                ->where('Steps', 1)
                ->value('Salary') ?? 0;
        }

        $authorizedAnnual = $monthlySalary * 12;
        $authorizedSalaryFormatted = number_format($authorizedAnnual, 2);
        $actual = number_format($row->rateyear ?? 0, 2);

        return [
            'controlNo'   => $controlNo,
            'Ordr'        => $row->Ordr,
            'itemNo'      => $row->ItemNo,
            'position'    => $row->position,
            'salarygrade' => $row->salarygrade,
            'authorized'  => $authorizedSalaryFormatted,
            'actual'      => $actual,
            'step'        => $row->steps ?? '1',
            'code'        => '11',
            'type'        => 'C',
            'level'       => $row->level,
            'lastname'    => $row->lastname,
            'firstname'   => $row->firstname,
            'middlename'  => $row->middlename,
            'birthdate'   => $row->birthdate,
            'funded'      => $row->Funded,
            'status'      => $row->Status,
            'dateOriginalAppointed' => $dateOriginalAppointed ?? null,
            'dateLastPromotion'     => $dateLastPromotion ?? null,
        ];
    }
}
