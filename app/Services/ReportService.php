<?php

namespace App\Services;

use App\Jobs\GeneratePlantillaReportJob;
use App\Jobs\QueueWorkerTestJob;
use App\Models\Job_batches_user;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\User;
use App\Models\vwActive;
use App\Models\xPersonal;
use App\Models\xService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Models\excel\Education_background;
use App\Models\excel\Work_experience;
use App\Models\excel\Learning_development;
use App\Models\excel\Civil_service_eligibity;

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

        $latestXService = DB::table('xService')
            ->select(
                'ControlNo',
                DB::raw('MAX(ToDate) as latest_todate'),
                DB::raw('MAX(FromDate) as latest_fromdate')
            )
            ->groupBy('ControlNo');

        $rows = DB::table('vwplantillastructure as p')
            ->leftJoin('vwActive as a', 'a.ControlNo', '=', 'p.ControlNo')
            ->leftJoin('vwofficearrangement as o', 'o.Office', '=', 'p.office')
            ->leftJoinSub($eligibility, 'eligibility', function ($join) {
                $join->on('eligibility.ControlNo', '=', 'p.ControlNo');
            })
            ->leftJoinSub($latestXService, 'lx', function ($join) {
                $join->on('lx.ControlNo', '=', 'p.ControlNo');
            })
            ->leftJoin('xService as s', function ($join) {
                $join->on('s.ControlNo', '=', 'lx.ControlNo')
                    ->on('s.ToDate', '=', 'lx.latest_todate')
                    ->on('s.FromDate', '=', 'lx.latest_fromdate');
            })
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
                's.RateYear as rateyear',
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

        $result = [];
        $resultFunded = [];
        $resultUnfunded = [];

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

        $fundedRows   = $rows->filter(fn($r) => (int) $r->Funded === 1);
        $unfundedRows = $rows->filter(fn($r) => (int) $r->Funded === 0);

        $resultFunded   = $buildStructure($fundedRows);
        $resultUnfunded = $buildStructure($unfundedRows);

        return response()->json([
            'funded'   => $resultFunded,
            'unfunded' => $resultUnfunded,
        ]);
    }

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

        $xList = collect();
        if (isset($xServiceByControl[$controlNo])) {
            $xList = $xServiceByControl[$controlNo];
        }

        $currentStep = (int) ($row->steps ?? 1);
        $currentPosition = $row->position;
        $budgetYearStep = $this->computeBudgetYearStep($xList, $currentStep, $currentPosition);
        $currentYearAmount = number_format($row->rateyear ?? 0, 2);

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

        $xList = $xList->sortByDesc('FromDate')->values();

        $currentService = $xList->first(function ($svc) use ($allowedStatuses) {
            return in_array(strtolower($svc->Status), $allowedStatuses)
                && !empty($svc->Designation);
        });

        if (!$currentService) {
            return $currentStep;
        }

        $designation = $currentService->Designation;
        $step = (int) ($currentService->Steps ?? $currentStep);

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

        $startYear = (int) Carbon::parse($samePosSameStep->first()->FromDate)->year;
        $currentYear = (int) Carbon::now()->year;
        $yearsRendered = ($currentYear - $startYear) + 1;

        if ($yearsRendered >= 3) {
            return $currentStep + 1;
        }

        return $currentStep;
    }

    // generate report plantilla
    public function plantilla($request)
    {
        $jobId = Str::uuid()->toString();

        Cache::put("plantilla_job_{$jobId}", [
            'status' => 'queued',
            'progress' => 0
        ], 600);

        GeneratePlantillaReportJob::dispatch($jobId)->onQueue('reports');

        $user = Auth::user();
        if ($user instanceof \App\Models\User) {
            activity('Generate Plantilla Structure Report')
                ->causedBy($user)
                ->withProperties([
                    'job_id'     => $jobId,
                    'ip'         => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ])
                ->log("{$user->name} generate plantilla report. Job ID: {$jobId}");
        }

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued'
        ]);
    }

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

        Cache::put("plantilla_job_{$jobId}", [
            'status' => 'cancelled',
            'progress' => $status['progress'] ?? 0
        ], 600);

        return response()->json([
            'status' => 'cancelled',
            'message' => 'Job cancellation requested'
        ]);
    }

    // applicant final summary of rating qualification standard
    public function applicantFinalScore($jobpostId)
    {
        $records = rating_score::select(
            'rating_score.id',
            'rating_score.user_id as rater_id',
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

        $jobPost = [
            'job_batches_rsp_id' => $jobpostId,
            'Office'      => $records->first()->Office,
            'Position'    => $records->first()->Position,
            'SalaryGrade' => $records->first()->SalaryGrade,
            'Plantilla Item No' => $records->first()->ItemNo,
        ];

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
                        'id'             => $row->id,
                        'rater_id'       => $row->rater_id,
                        'rater_name'     => $row->rater_name,
                        'rater_position' => $row->rater_position,
                        'education'      => $row->education,
                        'experience'     => $row->experience,
                        'training'       => $row->training,
                        'performance'    => $row->performance,
                        'bei'            => $row->bei,
                        'total_qs'       => $row->total_qs,
                        'grand_total'    => $row->grand_total,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'jobPost'    => $jobPost,
            'applicants' => $applicants,
        ]);
    }

    // Placement list
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
            ->where('xs.rn', 2);

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
            )
            ->orderByRaw('CAST(vps.ItemNo AS INT) ASC')
            ->get();

        return response()->json([
            'office' => $officeData,
        ]);
    }

    // top 5 ranking applicant date publication
    public function topApplicant($postDate)
    {
        $rater = User::select('name', 'position', 'role_type', 'representative', 'active', 'role_id', 'prefix', 'suffix')->where('active', 1)->where('role_id', 2)->get();
        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
            ->select('id', 'Office', 'Division', 'Section', 'Position', 'SalaryGrade', 'ItemNo')
            ->get();

        $offices = [];

        foreach ($jobPosts as $jobPost) {

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
                'rating_score.grand_total',
                'rating_score.exam_score',
                'nPersonalInfo.firstname',
                'nPersonalInfo.lastname'
            )
                ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
                ->where('rating_score.job_batches_rsp_id', $jobPost->id)
                ->get();

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
                    'exam_score'  => (float) $row->exam_score,
                ])->toArray();

                $computed = RatingService::computeFinalScore($scoresArray);

                $applicants[] = [
                    'nPersonalInfo_id' => $first->nPersonalInfo_id,
                    'ControlNo'        => $first->ControlNo,
                    'firstname'        => $first->firstname,
                    'lastname'         => $first->lastname,
                ] + $computed;
            }

            $topApplicants = collect(RatingService::addRanking($applicants))
                ->sortBy('ranking')
                ->values();

            if (!isset($offices[$jobPost->Office])) {
                $offices[$jobPost->Office] = [
                    'office'    => $jobPost->Office,
                    'job_posts' => []
                ];
            }

            $offices[$jobPost->Office]['job_posts'][] = [
                'Division'         => $jobPost->Division,
                'Position'         => $jobPost->Position,
                'Salary Grade'     => $jobPost->SalaryGrade,
                'Plantilla Item No' => $jobPost->ItemNo,
                'Top Applicant'    => $topApplicants,
            ];
        }

        return response()->json([
            'Header' => 'Top Ranking Applicants',
            'Date'   => "$postDate Publication",
            'Offices' => array_values($offices),
            'rater'  => $rater,
        ]);
    }

    // list of qualified applicants for job post publication
    // public function listQualified($postDate)
    // {
    //     Log::info('php ini', [
    //         'memory_limit' => ini_get('memory_limit'),
    //         'php_ini'      => php_ini_loaded_file()
    //     ]);

    //     try {
    //         ini_set('max_execution_time', 3600);

    //         // ✅ Normalize any date format to Y-m-d
    //         $postDate = Carbon::parse($postDate)->toDateString();

    //         Log::info('postDate normalized', ['postDate' => $postDate]);

    //         // =====================================================
    //         // FETCH JOB POSTS (no submissions in with())
    //         // =====================================================
    //         $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
    //             ->select('id', 'Position', 'ItemNo', 'SalaryGrade', 'Office')
    //             ->with([
    //                 'criteria:id,job_batches_rsp_id,Education,Experience,Training,Eligibility',
    //                 'criteriaRatings' => function ($query) {
    //                     $query->select('id', 'job_batches_rsp_id')
    //                         ->with(['educations', 'experiences', 'trainings']);
    //                 },
    //             ])
    //             ->get();

    //         $jobPostIds = $jobPosts->pluck('id');

    //         // =====================================================
    //         // FETCH SUBMISSIONS AS PLAIN OBJECTS — no Eloquent hydration
    //         // =====================================================
    //         // $allSubmissionsRaw = Submission::whereIn('job_batches_rsp_id', $jobPostIds)
    //         //     ->where('status', 'Qualified')
    //         //     ->select(
    //         //         'id',
    //         //         'job_batches_rsp_id',
    //         //         'nPersonalInfo_id',
    //         //         'ControlNo',
    //         //         'status',
    //         //         'education_remark',
    //         //         'experience_remark',
    //         //         'training_remark',
    //         //         'eligibility_remark',
    //         //         'education_qualification',
    //         //         'experience_qualification',
    //         //         'training_qualification',
    //         //         'eligibility_qualification',
    //         //     )
    //         //     ->get()
    //         //     ->map(function ($s) {
    //         //         $s->education_qualification   = is_array($s->education_qualification)   ? $s->education_qualification   : (json_decode($s->education_qualification,  true) ?? []);
    //         //         $s->experience_qualification  = is_array($s->experience_qualification)  ? $s->experience_qualification  : (json_decode($s->experience_qualification, true) ?? []);
    //         //         $s->training_qualification    = is_array($s->training_qualification)    ? $s->training_qualification    : (json_decode($s->training_qualification,   true) ?? []);
    //         //         $s->eligibility_qualification = is_array($s->eligibility_qualification) ? $s->eligibility_qualification : (json_decode($s->eligibility_qualification, true) ?? []);
    //         //         return $s;
    //         //     });
    //         // ✅ Plain objects — no Carbon hydration
    //         $allSubmissionsRaw = DB::table('submission')
    //             ->whereIn('job_batches_rsp_id', $jobPostIds)
    //             ->where('status', 'Qualified')
    //             ->select(
    //                 'id', 'job_batches_rsp_id', 'nPersonalInfo_id', 'ControlNo', 'status',
    //                 'education_remark', 'experience_remark', 'training_remark', 'eligibility_remark',
    //                 'education_qualification', 'experience_qualification',
    //                 'training_qualification', 'eligibility_qualification',
    //             )
    //             ->get()
    //             ->map(function ($s) {
    //                 $s->education_qualification   = json_decode($s->education_qualification,  true) ?? [];
    //                 $s->experience_qualification  = json_decode($s->experience_qualification, true) ?? [];
    //                 $s->training_qualification    = json_decode($s->training_qualification,   true) ?? [];
    //                 $s->eligibility_qualification = json_decode($s->eligibility_qualification, true) ?? [];
    //                 return $s;
    //             });
    //             \Log::info('submissions fetched', ['count' => $allSubmissionsRaw->count(), 'memory' => memory_get_usage(true)/1024/1024 . ' MB']);
    //         // ✅ Group submissions by job_batches_rsp_id
    //         $submissionsByJob = $allSubmissionsRaw->groupBy('job_batches_rsp_id');

    //         // =====================================================
    //         // BULK FETCH — split submissions by type
    //         // =====================================================
    //         $externalSubs = $allSubmissionsRaw->filter(fn($s) => !empty($s->nPersonalInfo_id));
    //         $internalSubs = $allSubmissionsRaw->filter(fn($s) => empty($s->nPersonalInfo_id) && !empty($s->ControlNo));

    //         // --- Collect all IDs ---
    //         $extEduIds   = $externalSubs->flatMap(fn($s) => $s->education_qualification   ?? [])->unique();
    //         $extExpIds   = $externalSubs->flatMap(fn($s) => $s->experience_qualification  ?? [])->unique();
    //         $extTrainIds = $externalSubs->flatMap(fn($s) => $s->training_qualification    ?? [])->unique();
    //         $extEligIds  = $externalSubs->flatMap(fn($s) => $s->eligibility_qualification ?? [])->unique();

    //         $intEduIds   = $internalSubs->flatMap(fn($s) => $s->education_qualification   ?? [])->unique();
    //         $intExpIds   = $internalSubs->flatMap(fn($s) => $s->experience_qualification  ?? [])->unique();
    //         $intTrainIds = $internalSubs->flatMap(fn($s) => $s->training_qualification    ?? [])->unique();
    //         $intEligIds  = $internalSubs->flatMap(fn($s) => $s->eligibility_qualification ?? [])->unique();

    //         // --- EXTERNAL (Eloquent models) chunked at 1000 ---
    //         $extEducations    = $extEduIds->chunk(1000)->flatMap(fn($c) => Education_background::whereIn('id', $c)->get()->all())->keyBy('id');
    //         $extExperiences   = $extExpIds->chunk(1000)->flatMap(fn($c) => Work_experience::whereIn('id', $c)->get()->all())->keyBy('id');
    //         $extTrainings     = $extTrainIds->chunk(1000)->flatMap(fn($c) => Learning_development::whereIn('id', $c)->get()->all())->keyBy('id');
    //         $extEligibilities = $extEligIds->chunk(1000)->flatMap(fn($c) => Civil_service_eligibity::whereIn('id', $c)->get()->all())->keyBy('id');

    //         // --- INTERNAL (DB tables) chunked at 1000 ---
    //         $intEducations    = $intEduIds->chunk(1000)->flatMap(fn($c) => DB::table('xEducation')->whereIn('PMID', $c)->get()->all())->keyBy('PMID');
    //         // $intExperiences   = $intExpIds->chunk(1000)->flatMap(fn($c) => DB::table('xExperience')->whereIn('ID', $c)->get()->all())->keyBy('ID');
    //         // ✅ TAMA — i-loop per submission
    //         // $intExperiences = collect();
    //         // foreach ($internalSubs as $submission) {
    //         //     // ✅ i-find muna ang Submission model para ma-call ang method
    //         //     $submissionModel = Submission::find($submission->id);
    //         //     $records = $submissionModel->getExperienceRecordsInternal(
    //         //         $submission->ControlNo,
    //         //         $submission->experience_qualification ?? []
    //         //     );
    //         //     $intExperiences = $intExperiences->merge($records);
    //         // }
    //         // $intExperiences = $intExperiences->keyBy('id');
    //         // ✅ REPLACE WITH THIS
    //         $intExperiences = $intExpIds->chunk(1000)->flatMap(
    //             fn($c) => DB::table('xExperience')->whereIn('ID', $c)->get()->all()
    //         )->keyBy('ID');

    //         $intTrainings     = $intTrainIds->chunk(1000)->flatMap(fn($c) => DB::table('xTrainings')->whereIn('PMID', $c)->get()->all())->keyBy('PMID');
    //         $intEligibilities = $intEligIds->chunk(1000)->flatMap(fn($c) => DB::table('xCivilService')->whereIn('PMID', $c)->get()->all())->keyBy('PMID');

    //         // --- BULK FETCH nPersonalInfo ---
    //         $nPersonalInfoIds = $externalSubs->pluck('nPersonalInfo_id')->unique();
    //         $nPersonalInfos   = DB::table('nPersonalInfo')
    //             ->whereIn('id', $nPersonalInfoIds)
    //             ->select('id', 'firstname', 'lastname')
    //             ->get()->keyBy('id');

    //         // --- BULK FETCH xPersonal and vwActive ---
    //         $controlNos = $internalSubs->pluck('ControlNo')->unique();

    //         $xPersonals = $controlNos->chunk(1000)->flatMap(
    //             fn($c) => DB::table('xPersonal')->whereIn('ControlNo', $c)->select('ControlNo', 'Firstname', 'Surname')->get()->all()
    //         )->keyBy('ControlNo');

    //         $tempReorgs = $controlNos->chunk(1000)->flatMap(
    //             fn($c) => DB::table('vwActive')->whereIn('ControlNo', $c)->select('ControlNo', 'Office', 'Designation')->get()->all()
    //         )->keyBy('ControlNo');

    //         // --- BULK FETCH yOffice ---
    //         $officeNames = $jobPosts->pluck('Office')->unique();
    //         $offices = DB::table('yOffice')
    //             ->whereIn('Descriptions', $officeNames)
    //             ->select('Descriptions', 'Abbr')
    //             ->get()->keyBy('Descriptions');

    //         Log::info('bulk fetches done', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);

    //         // =====================================================
    //         // BUILD RESPONSE — no queries inside the loop
    //         // =====================================================
    //         $responseJobs = [];

    //         foreach ($jobPosts as $job) {
    //             $office     = $offices->get($job->Office);
    //             $applicants = [];

    //             foreach ($submissionsByJob->get($job->id, collect()) as $submission) {

    //                 // =====================
    //                 // EXTERNAL (nPersonalInfo_id set)
    //                 // =====================
    //                 if ($submission->nPersonalInfo_id) {

    //                     $personalInfo = $nPersonalInfos->get($submission->nPersonalInfo_id);
    //                     if (!$personalInfo) continue;

    //                     $educationRecords   = $extEducations->filter(fn($r) => in_array($r->id, $submission->education_qualification   ?? []));
    //                     // ✅ consistent na — 'id' na lahat
    //                     $experienceRecords = $extExperiences->filter(
    //                         fn($r) => in_array($r->id, $submission->experience_qualification ?? [])
    //                     );

    //                     $trainingRecords    = $extTrainings->filter(fn($r) => in_array($r->id, $submission->training_qualification    ?? []));
    //                     $eligibilityRecords = $extEligibilities->filter(fn($r) => in_array($r->id, $submission->eligibility_qualification ?? []));

    //                     $applicants[] = [
    //                         'firstname'          => $personalInfo->firstname,
    //                         'lastname'           => $personalInfo->lastname,
    //                         'status'             => $submission->status,
    //                         'applicant_status'   => 'OUTSIDER',

    //                         'education'          => $educationRecords->values(),
    //                         'experience'         => $experienceRecords->values(),
    //                         'training'           => $trainingRecords->values(),
    //                         'eligibility'        => $eligibilityRecords->values(),

    //                         'education_remark'   => $submission->education_remark   ?? null,
    //                         'experience_remark'  => $submission->experience_remark  ?? null,
    //                         'training_remark'    => $submission->training_remark    ?? null,
    //                         'eligibility_remark' => $submission->eligibility_remark ?? null,

    //                         'education_text'     => $this->formatEducationForQualifiedExternal($educationRecords),
    //                         'experience_text'    => $this->formatExperienceForQualifiedExternal($experienceRecords),
    //                         'training_text'      => $this->formatTrainingForQualifiedExternal($trainingRecords),
    //                         'eligibility_text'   => $this->formatEligibilityForQualifiedExternal($eligibilityRecords),
    //                     ];
    //                 }

    //                 // =====================
    //                 // INTERNAL (ControlNo set)
    //                 // =====================
    //                 elseif (!empty($submission->ControlNo)) {

    //                     $personal  = $xPersonals->get($submission->ControlNo);
    //                     $tempReorg = $tempReorgs->get($submission->ControlNo);

    //                     if (!$personal) continue;

    //                     $educationRecords   = $intEducations->filter(fn($r) => in_array($r->PMID, $submission->education_qualification   ?? []));
    //                     // $experienceRecords  = $intExperiences->filter(fn($r) => in_array($r->ID,   $submission->experience_qualification  ?? []));
    //                     $experienceRecords = $intExperiences->filter(
    //                         fn($r) => in_array($r->ID, $submission->experience_qualification ?? [])
    //                     );

    //                     $trainingRecords    = $intTrainings->filter(fn($r) => in_array($r->PMID, $submission->training_qualification    ?? []));
    //                     $eligibilityRecords = $intEligibilities->filter(fn($r) => in_array($r->PMID, $submission->eligibility_qualification ?? []));

    //                     $applicants[] = [
    //                         'controlno'           => $submission->ControlNo,
    //                         'firstname'           => $personal->Firstname,
    //                         'lastname'            => $personal->Surname,
    //                         'current_designation' => $tempReorg->Designation ?? null,
    //                         'office'              => $tempReorg->Office       ?? null,
    //                         'status'              => $submission->status,
    //                         'applicant_status'    => 'INTERNAL',

    //                         'education'           => $educationRecords->values(),
    //                         'experience'          => $experienceRecords->values(),
    //                         'training'            => $trainingRecords->values(),
    //                         'eligibility'         => $eligibilityRecords->values(),

    //                         'education_remark'    => $submission->education_remark   ?? null,
    //                         'experience_remark'   => $submission->experience_remark  ?? null,
    //                         'training_remark'     => $submission->training_remark    ?? null,
    //                         'eligibility_remark'  => $submission->eligibility_remark ?? null,

    //                         'education_text'      => $this->formatEducationForQualifiedInternal($educationRecords),
    //                         'experience_text'     => $this->formatExperienceForQualifiedInternal($experienceRecords),
    //                         'training_text'       => $this->formatTrainingForQualifiedInternal($trainingRecords),
    //                         'eligibility_text'    => $this->formatEligibilityForQualifiedInternal($eligibilityRecords),
    //                     ];
    //                 }
    //             }

    //             $responseJobs[] = [
    //                 'id'              => $job->id,
    //                 'Office'          => $job->Office,
    //                 'yOffice'         => $office->Descriptions ?? $job->Office,
    //                 'Abbr'            => $office->Abbr         ?? null,
    //                 'Position'        => $job->Position,
    //                 'ItemNo'          => $job->ItemNo,
    //                 'SalaryGrade'     => $job->SalaryGrade,
    //                 'criteria'        => $job->criteria,
    //                 'criteria_rating' => $job->criteriaRatings,
    //                 'applicants'      => $applicants,
    //             ];
    //         }

    //         \Log::info('before json encode', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);
    //         $payload = [
    //             'Header'   => 'Applicants Qualified Standard',
    //             'Date'     => Carbon::parse($postDate)->format('F d, Y') . ' Publication',
    //             'jobPosts' => $responseJobs,
    //         ];

    //         \Log::info('json encode start', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);

    //         $json = json_encode($payload);

    //         \Log::info('json encode done', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB', 'size_mb' => round(strlen($json) / 1024 / 1024, 2)]);

    //         return response($json, 200)->header('Content-Type', 'application/json');
    //         // return response()->json([
    //         //     'Header'   => 'Applicants Qualified Standard',
    //         //     'Date'     => Carbon::parse($postDate)->format('F d, Y') . ' Publication',
    //         //     'jobPosts' => $responseJobs,
    //         // ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //             'file'    => $e->getFile(),
    //             'line'    => $e->getLine(),
    //         ], 500);
    //     }
    // }
    //new list of qualified
    public function listQualified($postDate)
{
    \Log::info('php ini', [
        'memory_limit' => ini_get('memory_limit'),
        'php_ini'      => php_ini_loaded_file()
    ]);

    try {
        ini_set('max_execution_time', 3600);

        // ✅ Normalize any date format to Y-m-d
        $postDate = Carbon::parse($postDate)->toDateString();

        \Log::info('postDate normalized', ['postDate' => $postDate]);

        // =====================================================
        // FETCH JOB POSTS (no submissions in with())
        // =====================================================
        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
            ->select('id', 'Position', 'ItemNo', 'SalaryGrade', 'Office')
            ->with([
                'criteria:id,job_batches_rsp_id,Education,Experience,Training,Eligibility',
                'criteriaRatings' => function ($query) {
                    $query->select('id', 'job_batches_rsp_id')
                        ->with(['educations', 'experiences', 'trainings']);
                },
            ])
            ->get();

        $jobPostIds = $jobPosts->pluck('id');

        // =====================================================
        // FETCH SUBMISSIONS AS PLAIN OBJECTS — no Eloquent hydration
        // =====================================================
        $allSubmissionsRaw = DB::table('submission')
            ->whereIn('job_batches_rsp_id', $jobPostIds)
            ->where('status', 'Qualified')
            ->select(
                'id', 'job_batches_rsp_id', 'nPersonalInfo_id', 'ControlNo', 'status',
                'education_remark', 'experience_remark', 'training_remark', 'eligibility_remark',
                'education_qualification', 'experience_qualification',
                'training_qualification', 'eligibility_qualification',
            )
            ->get()
            ->map(function ($s) {
                $s->education_qualification   = json_decode($s->education_qualification,  true) ?? [];
                $s->experience_qualification  = json_decode($s->experience_qualification, true) ?? [];
                $s->training_qualification    = json_decode($s->training_qualification,   true) ?? [];
                $s->eligibility_qualification = json_decode($s->eligibility_qualification, true) ?? [];
                return $s;
            });

        \Log::info('submissions fetched', ['count' => $allSubmissionsRaw->count(), 'memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);

        // ✅ Group submissions by job_batches_rsp_id
        $submissionsByJob = $allSubmissionsRaw->groupBy('job_batches_rsp_id');

        // =====================================================
        // BULK FETCH — split submissions by type
        // =====================================================
        $externalSubs = $allSubmissionsRaw->filter(fn($s) => !empty($s->nPersonalInfo_id));
        $internalSubs = $allSubmissionsRaw->filter(fn($s) => empty($s->nPersonalInfo_id) && !empty($s->ControlNo));

        // --- Collect all IDs ---
        $extEduIds   = $externalSubs->flatMap(fn($s) => $s->education_qualification   ?? [])->unique();
        $extExpIds   = $externalSubs->flatMap(fn($s) => $s->experience_qualification  ?? [])->unique();
        $extTrainIds = $externalSubs->flatMap(fn($s) => $s->training_qualification    ?? [])->unique();
        $extEligIds  = $externalSubs->flatMap(fn($s) => $s->eligibility_qualification ?? [])->unique();

        $intEduIds   = $internalSubs->flatMap(fn($s) => $s->education_qualification   ?? [])->unique();
        $intExpIds   = $internalSubs->flatMap(fn($s) => $s->experience_qualification  ?? [])->unique();
        $intTrainIds = $internalSubs->flatMap(fn($s) => $s->training_qualification    ?? [])->unique();
        $intEligIds  = $internalSubs->flatMap(fn($s) => $s->eligibility_qualification ?? [])->unique();

        // --- EXTERNAL (Eloquent models) chunked at 1000 ---
        $extEducations    = $extEduIds->chunk(1000)->flatMap(fn($c) => Education_background::whereIn('id', $c)->get()->all())->keyBy('id');
        $extExperiences   = $extExpIds->chunk(1000)->flatMap(fn($c) => Work_experience::whereIn('id', $c)->get()->all())->keyBy('id');
        $extTrainings     = $extTrainIds->chunk(1000)->flatMap(fn($c) => Learning_development::whereIn('id', $c)->get()->all())->keyBy('id');
        $extEligibilities = $extEligIds->chunk(1000)->flatMap(fn($c) => Civil_service_eligibity::whereIn('id', $c)->get()->all())->keyBy('id');

        // --- INTERNAL (DB tables) chunked at 1000 ---
        $intEducations    = $intEduIds->chunk(1000)->flatMap(fn($c) => DB::table('xEducation')->whereIn('PMID', $c)->get()->all())->keyBy('PMID');
        $intExperiences   = $intExpIds->chunk(1000)->flatMap(fn($c) => DB::table('xExperience')->whereIn('ID', $c)->get()->all())->keyBy('ID');
        $intTrainings     = $intTrainIds->chunk(1000)->flatMap(fn($c) => DB::table('xTrainings')->whereIn('PMID', $c)->get()->all())->keyBy('PMID');
        $intEligibilities = $intEligIds->chunk(1000)->flatMap(fn($c) => DB::table('xCivilService')->whereIn('PMID', $c)->get()->all())->keyBy('PMID');

        // --- BULK FETCH nPersonalInfo ---
        $nPersonalInfoIds = $externalSubs->pluck('nPersonalInfo_id')->unique();
        $nPersonalInfos   = DB::table('nPersonalInfo')
            ->whereIn('id', $nPersonalInfoIds)
            ->select('id', 'firstname', 'lastname')
            ->get()->keyBy('id');

        // --- BULK FETCH xPersonal and vwActive ---
        $controlNos = $internalSubs->pluck('ControlNo')->unique();

        $xPersonals = $controlNos->chunk(1000)->flatMap(
            fn($c) => DB::table('xPersonal')->whereIn('ControlNo', $c)->select('ControlNo', 'Firstname', 'Surname')->get()->all()
        )->keyBy('ControlNo');

        $tempReorgs = $controlNos->chunk(1000)->flatMap(
            fn($c) => DB::table('vwActive')->whereIn('ControlNo', $c)->select('ControlNo', 'Office', 'Designation')->get()->all()
        )->keyBy('ControlNo');

        // --- BULK FETCH xService (for internal experience records) ---
        // Group by ControlNo so we can look up per submission
        // --- BULK FETCH xService (for internal experience) grouped by ControlNo ---
        $intServiceRecords = $controlNos->chunk(1000)->flatMap(
            fn($c) => DB::table('xService')
                ->whereIn('ControlNo', $c)
                ->select('PMID as id', 'ControlNo', 'FromDate as WFrom', 'ToDate as WTo', 'Designation as WPosition', 'Office as WCompany')
                ->orderBy('FromDate')
                ->get()->all()
        )->groupBy('ControlNo');

        // --- BULK FETCH yOffice ---
        $officeNames = $jobPosts->pluck('Office')->unique();
        $offices = DB::table('yOffice')
            ->whereIn('Descriptions', $officeNames)
            ->select('Descriptions', 'Abbr')
            ->get()->keyBy('Descriptions');

        \Log::info('bulk fetches done', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);

        // =====================================================
        // BUILD RESPONSE — no queries inside the loop
        // =====================================================
        $responseJobs = [];

        foreach ($jobPosts as $job) {
            $office     = $offices->get($job->Office);
            $applicants = [];

            foreach ($submissionsByJob->get($job->id, collect()) as $submission) {

                // =====================
                // EXTERNAL (nPersonalInfo_id set)
                // =====================
                if ($submission->nPersonalInfo_id) {

                    $personalInfo = $nPersonalInfos->get($submission->nPersonalInfo_id);
                    if (!$personalInfo) continue;

                    $educationRecords   = $extEducations->filter(fn($r) => in_array($r->id, $submission->education_qualification   ?? []));
                    $experienceRecords  = $extExperiences->filter(fn($r) => in_array($r->id, $submission->experience_qualification  ?? []));
                    $trainingRecords    = $extTrainings->filter(fn($r) => in_array($r->id, $submission->training_qualification    ?? []));
                    $eligibilityRecords = $extEligibilities->filter(fn($r) => in_array($r->id, $submission->eligibility_qualification ?? []));

                    $applicants[] = [
                        'firstname'          => $personalInfo->firstname,
                        'lastname'           => $personalInfo->lastname,
                        'status'             => $submission->status,
                        'applicant_status'   => 'OUTSIDER',

                        'education'          => $educationRecords->values(),
                        'experience'         => $experienceRecords->values(),
                        'training'           => $trainingRecords->values(),
                        'eligibility'        => $eligibilityRecords->values(),

                        'education_remark'   => $submission->education_remark   ?? null,
                        'experience_remark'  => $submission->experience_remark  ?? null,
                        'training_remark'    => $submission->training_remark    ?? null,
                        'eligibility_remark' => $submission->eligibility_remark ?? null,

                        'education_text'     => $this->formatEducationForQualifiedExternal($educationRecords),
                        'experience_text'    => $this->formatExperienceForQualifiedExternal($experienceRecords),
                        'training_text'      => $this->formatTrainingForQualifiedExternal($trainingRecords),
                        'eligibility_text'   => $this->formatEligibilityForQualifiedExternal($eligibilityRecords),
                    ];
                }

                // =====================
                // INTERNAL (ControlNo set)
                // =====================
                // elseif (!empty($submission->ControlNo)) {

                //     $personal  = $xPersonals->get($submission->ControlNo);
                //     $tempReorg = $tempReorgs->get($submission->ControlNo);

                //     if (!$personal) continue;

                //     $educationRecords   = $intEducations->filter(fn($r) => in_array($r->PMID, $submission->education_qualification ?? []));
                //     $eligibilityRecords = $intEligibilities->filter(fn($r) => in_array($r->PMID, $submission->eligibility_qualification ?? []));
                //     $trainingRecords    = $intTrainings->filter(fn($r) => in_array($r->PMID, $submission->training_qualification ?? []));

                //     // ✅ Merge xExperience + xService records for this ControlNo
                //     $xExpRecords     = $intExperiences->filter(fn($r) => in_array($r->ID, $submission->experience_qualification ?? []))->values();
                //     $xServiceRecords = collect($intServiceRecords->get($submission->ControlNo, []))
                //         ->map(function ($r) {
                //             // ✅ Safe date parser — tries multiple formats
                //             $parseDate = function ($dateStr) {
                //                 if (empty($dateStr)) return null;
                //                 $clean = strtoupper(trim($dateStr));
                //                 if ($clean === 'CURRENT' || $clean === '') return date('m/d/Y');

                //                 // Try common formats
                //                 foreach (['Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'm-d-Y', 'd-m-Y'] as $format) {
                //                     $parsed = \DateTime::createFromFormat($format, $dateStr);
                //                     if ($parsed !== false) {
                //                         return $parsed->format('m/d/Y');
                //                     }
                //                 }

                //                 // Last resort — strtotime
                //                 $ts = strtotime($dateStr);
                //                 return $ts !== false ? date('m/d/Y', $ts) : null;
                //             };

                //             $r->WFrom = $parseDate($r->WFrom);

                //             $wTo = strtoupper(trim($r->WTo ?? ''));
                //             $r->WTo = ($wTo === '' || $wTo === 'CURRENT')
                //                 ? date('m/d/Y')
                //                 : $parseDate($r->WTo);

                //             return $r;
                //         });

                //     $experienceRecords = $xExpRecords->merge($xServiceRecords->values());

                //     $applicants[] = [
                //         'controlno'           => $submission->ControlNo,
                //         'firstname'           => $personal->Firstname,
                //         'lastname'            => $personal->Surname,
                //         'current_designation' => $tempReorg->Designation ?? null,
                //         'office'              => $tempReorg->Office       ?? null,
                //         'status'              => $submission->status,
                //         'applicant_status'    => 'INTERNAL',

                //         'education'           => $educationRecords->values(),
                //         'experience'          => $experienceRecords->values(),
                //         'training'            => $trainingRecords->values(),
                //         'eligibility'         => $eligibilityRecords->values(),

                //         'education_remark'    => $submission->education_remark   ?? null,
                //         'experience_remark'   => $submission->experience_remark  ?? null,
                //         'training_remark'     => $submission->training_remark    ?? null,
                //         'eligibility_remark'  => $submission->eligibility_remark ?? null,

                //         'education_text'      => $this->formatEducationForQualifiedInternal($educationRecords),
                //         'experience_text'     => $this->formatExperienceForQualifiedInternal($experienceRecords),
                //         'training_text'       => $this->formatTrainingForQualifiedInternal($trainingRecords),
                //         'eligibility_text'    => $this->formatEligibilityForQualifiedInternal($eligibilityRecords),
                //     ];
                // }
                // =====================
                // INTERNAL (ControlNo set)
                // =====================
                elseif (!empty($submission->ControlNo)) {

                    $personal  = $xPersonals->get($submission->ControlNo);
                    $tempReorg = $tempReorgs->get($submission->ControlNo);

                    if (!$personal) continue;

                    $educationRecords   = $intEducations->filter(fn($r) => in_array($r->PMID, $submission->education_qualification ?? []));
                    $trainingRecords    = $intTrainings->filter(fn($r) => in_array($r->PMID, $submission->training_qualification ?? []));
                    $eligibilityRecords = $intEligibilities->filter(fn($r) => in_array($r->PMID, $submission->eligibility_qualification ?? []));

                    // ✅ xService records — match exactly what getExperienceRecordsInternal does
                    $serviceRecords = collect($intServiceRecords->get($submission->ControlNo, []));

                    // ✅ Find latest record (by WTo then WFrom) — same logic as original
                    $latestServiceId = $serviceRecords
                        ->sortByDesc(fn($r) => [$r->WTo, $r->WFrom])
                        ->first()?->id;

                    $serviceRecords = $serviceRecords->map(function ($r) use ($latestServiceId) {
                        $isLatest = $r->id === $latestServiceId;

                        $toDate = !empty($r->WTo) ? strtotime($r->WTo) : null;

                        // ✅ Cap future WTo to today only on latest record
                        if ($isLatest && $toDate && $toDate > time()) {
                            $toDate = time();
                        }

                        $r->WFrom = !empty($r->WFrom) ? date('m/d/Y', strtotime($r->WFrom)) : null;
                        $r->WTo   = $toDate ? date('m/d/Y', $toDate) : null;
                        $r->experience_status = 'SERVICE';
                        return $r;
                    });

                    // ✅ xExperience records filtered by qualification IDs
                    $xExpRecords = $intExperiences->filter(
                        fn($r) => in_array($r->ID, $submission->experience_qualification ?? [])
                    )->values()->map(function ($r) {
                        $r->id = $r->ID;

                        $r->WFrom = (!empty($r->WFrom) && strtoupper(trim($r->WFrom)) !== 'CURRENT')
                            ? date('m/d/Y', strtotime($r->WFrom))
                            : null;

                        $wTo = strtoupper(trim($r->WTo ?? ''));
                        $r->WTo = ($wTo === 'CURRENT' || $wTo === '')
                            ? date('m/d/Y')
                            : date('m/d/Y', strtotime($r->WTo));

                        $r->experience_status = 'EXPERIENCE';
                        return $r;
                    });

                    // ✅ Merge — same as original return $serviceRecords->merge($experienceRecords)
                    $experienceRecords = $serviceRecords->merge($xExpRecords->values());

                    $applicants[] = [
                        'controlno'           => $submission->ControlNo,
                        'firstname'           => $personal->Firstname,
                        'lastname'            => $personal->Surname,
                        'current_designation' => $tempReorg->Designation ?? null,
                        'office'              => $tempReorg->Office       ?? null,
                        'status'              => $submission->status,
                        'applicant_status'    => 'INTERNAL',

                        'education'           => $educationRecords->values(),
                        'experience'          => $experienceRecords->values(),
                        'training'            => $trainingRecords->values(),
                        'eligibility'         => $eligibilityRecords->values(),

                        'education_remark'    => $submission->education_remark   ?? null,
                        'experience_remark'   => $submission->experience_remark  ?? null,
                        'training_remark'     => $submission->training_remark    ?? null,
                        'eligibility_remark'  => $submission->eligibility_remark ?? null,

                        'education_text'      => $this->formatEducationForQualifiedInternal($educationRecords),
                        'experience_text'     => $this->formatExperienceForQualifiedInternal($experienceRecords),
                        'training_text'       => $this->formatTrainingForQualifiedInternal($trainingRecords),
                        'eligibility_text'    => $this->formatEligibilityForQualifiedInternal($eligibilityRecords),
                    ];
                }
            }

            $responseJobs[] = [
                'id'              => $job->id,
                'Office'          => $job->Office,
                'yOffice'         => $office->Descriptions ?? $job->Office,
                'Abbr'            => $office->Abbr         ?? null,
                'Position'        => $job->Position,
                'ItemNo'          => $job->ItemNo,
                'SalaryGrade'     => $job->SalaryGrade,
                'criteria'        => $job->criteria,
                'criteria_rating' => $job->criteriaRatings,
                'applicants'      => $applicants,
            ];
        }

        \Log::info('before json encode', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);

        $payload = [
            'Header'   => 'Applicants Qualified Standard',
            'Date'     => Carbon::parse($postDate)->format('F d, Y') . ' Publication',
            'jobPosts' => $responseJobs,
        ];

        \Log::info('json encode start', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB']);

        $json = json_encode($payload);

        \Log::info('json encode done', ['memory' => memory_get_usage(true) / 1024 / 1024 . ' MB', 'size_mb' => round(strlen($json) / 1024 / 1024, 2)]);

        return response($json, 200)->header('Content-Type', 'application/json');

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ], 500);
    }
}

    // list of unqualified applicants for job post publication
    public function listUnQualified($postDate)
    {
        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
            ->select('id', 'Position', 'ItemNo', 'SalaryGrade', 'Office')
            ->with([
                'criteria:id,job_batches_rsp_id,Education,Experience,Training,Eligibility',
                'criteriaRatings' => function ($query) {
                    $query->select('id', 'job_batches_rsp_id')
                        ->with(['educations', 'experiences', 'trainings']);
                },
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
                        'education_qualification',
                        'experience_qualification',
                        'training_qualification',
                        'eligibility_qualification',
                    )->where('status', 'Unqualified')
                        ->with(['nPersonalInfo:id,firstname,lastname']);
                }
            ])
            ->get();

        $responseJobs = [];

        foreach ($jobPosts as $job) {

            $office = DB::table('yOffice')->select('Descriptions', 'Abbr')->where('Descriptions', $job->Office)->first();
            $applicants = [];

            foreach ($job->submissions as $submission) {

                // =====================
                // EXTERNAL (nPersonalInfo_id set)
                // =====================
                if ($submission->nPersonalInfo_id) {

                    $educationRecords   = $submission->getEducationRecordsExternal();
                    $experienceRecords  = $submission->getExperienceRecordsExternal();
                    $trainingRecords    = $submission->getTrainingRecordsExternal();
                    $eligibilityRecords = $submission->getEligibilityRecordsExternal();

                    $applicants[] = [
                        'firstname'          => $submission->nPersonalInfo->firstname,
                        'lastname'           => $submission->nPersonalInfo->lastname,
                        'status'             => $submission->status,
                        'applicant_status'   => 'OUTSIDER',

                        'education'          => $educationRecords,
                        'experience'         => $experienceRecords,
                        'training'           => $trainingRecords,
                        'eligibility'        => $eligibilityRecords,

                        'education_remark'   => $submission->education_remark   ?? null,
                        'experience_remark'  => $submission->experience_remark  ?? null,
                        'training_remark'    => $submission->training_remark    ?? null,
                        'eligibility_remark' => $submission->eligibility_remark ?? null,

                        'education_text'     => $this->formatEducationForQualifiedExternal($educationRecords),
                        'experience_text'    => $this->formatExperienceForQualifiedExternal($experienceRecords),
                        'training_text'      => $this->formatTrainingForQualifiedExternal($trainingRecords),
                        'eligibility_text'   => $this->formatEligibilityForQualifiedExternal($eligibilityRecords),
                    ];
                }

                // =====================
                // INTERNAL (ControlNo set)
                // =====================
                elseif (!empty($submission->ControlNo)) {

                    $personal = DB::table('xPersonal')
                        ->where('ControlNo', $submission->ControlNo)
                        ->select('Firstname', 'Surname')
                        ->first();

                    if (!$personal) continue;

                    $tempReorg = DB::table('vwActive')
                        ->where('ControlNo', $submission->ControlNo)
                        ->select('Office', 'Designation')
                        ->first();

                    $educationRecords   = $submission->getEducationRecordsInternal();
                    $experienceRecords  = $submission->getExperienceRecordsInternal($submission->ControlNo);
                    $trainingRecords    = $submission->getTrainingRecordsInternal();
                    $eligibilityRecords = $submission->getEligibilityRecordsInternal();

                    $applicants[] = [
                        'controlno'           => $submission->ControlNo,
                        'firstname'           => $personal->Firstname,
                        'lastname'            => $personal->Surname,
                        'current_designation' => $tempReorg->Designation ?? null,
                        'office'              => $tempReorg->Office       ?? null,
                        'status'              => $submission->status,
                        'applicant_status'    => 'INTERNAL',

                        'education'           => $educationRecords,
                        'experience'          => $experienceRecords,
                        'training'            => $trainingRecords,
                        'eligibility'         => $eligibilityRecords,

                        'eligibility_remark'  => $submission->eligibility_remark ?? null,
                        'training_remark'     => $submission->training_remark    ?? null,
                        'experience_remark'   => $submission->experience_remark  ?? null,
                        'education_remark'    => $submission->education_remark   ?? null,

                        'education_text'      => $this->formatEducationForQualifiedInternal($educationRecords),
                        'experience_text'     => $this->formatExperienceForQualifiedInternal($experienceRecords),
                        'training_text'       => $this->formatTrainingForQualifiedInternal($trainingRecords),
                        'eligibility_text'    => $this->formatEligibilityForQualifiedInternal($eligibilityRecords),
                    ];
                }
            }

            if (!empty($applicants)) {
                $responseJobs[] = [
                    'id'              => $job->id,
                    'Office'          => $job->Office,
                    'yOffice'         => $office->Descriptions ?? $job->Office,
                    'Abbr'            => $office->Abbr         ?? null,
                    'Position'        => $job->Position,
                    'ItemNo'          => $job->ItemNo,
                    'SalaryGrade'     => $job->SalaryGrade,
                    'criteria'        => $job->criteria,
                    'criteria_rating' => $job->criteriaRatings,
                    'applicants'      => $applicants,
                ];
            }
        }

        return response()->json([
            'Header'   => 'Applicants UnQualified Standard',
            'Date'     => Carbon::parse($postDate)->format('F d, Y') . ' Publication',
            'jobPosts' => $responseJobs,
        ]);
    }

    // =====================================================
    // FORMATTING HELPERS
    // =====================================================

    // ✅ Helper: Convert total hours to years, months, days
    public function convertHoursToYearsMonthsDays(int $totalHours, string $label = ''): string
    {
        $hoursPerDay   = 8;
        $daysPerMonth  = 22;
        $monthsPerYear = 12;

        $totalDays = (int) floor($totalHours / $hoursPerDay);
        $years     = (int) floor($totalDays / ($daysPerMonth * $monthsPerYear));
        $remaining = $totalDays % ($daysPerMonth * $monthsPerYear);
        $months    = (int) floor($remaining / $daysPerMonth);
        $days      = $remaining % $daysPerMonth;

        $parts = [];
        if ($years > 0)  $parts[] = "{$years} " . ($years  === 1 ? 'year'  : 'years');
        if ($months > 0) $parts[] = "{$months} " . ($months === 1 ? 'month' : 'months');
        if ($days > 0)   $parts[] = "{$days} "   . ($days   === 1 ? 'day'   : 'days');

        $result = implode(', ', $parts) ?: '0 days';

        return $label ? "{$result} {$label}" : $result;
    }

    // ✅ Fast weekday counter — no day-by-day loop, pure math
    private function countWeekdaysBetween(\DateTime $start, \DateTime $end): int
    {
        $days     = (int) $start->diff($end)->days;
        $weeks    = intdiv($days, 7);
        $extra    = $days % 7;
        $startDow = (int) $start->format('N'); // 1=Mon, 7=Sun
        $weekdays = $weeks * 5;

        for ($i = 0; $i < $extra; $i++) {
            $dow = (($startDow - 1 + $i) % 7) + 1;
            if ($dow < 6) $weekdays++;
        }

        return $weekdays;
    }

    // --- EXTERNAL format helpers ---

    public function formatEducationForQualifiedExternal($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return '';
        }

        $formatted = [];
        foreach ($educationRecords as $edu) {
            $degree = $edu->degree ?? '';
            $unit   = $edu->highest_units ?? '';
            $formatted[] = "• {$degree} ({$unit} units)";
        }

        return implode('<br>', $formatted);
    }

    public function formatExperienceForQualifiedExternal($experienceRecords)
    {
        if ($experienceRecords->isEmpty()) {
            return '';
        }

        $totalHours = 0;
        foreach ($experienceRecords as $exp) {
            $from = $exp->work_date_from ?? null;
            $to   = $exp->work_date_to   ?? null;

            if ($from && $to) {
                $start = \DateTime::createFromFormat('d/m/Y', $from);
                $end   = \DateTime::createFromFormat('d/m/Y', $to);
                if ($start && $end && $end >= $start) {
                    $totalHours += $this->countWeekdaysBetween($start, $end) * 8;
                }
            }
        }

        return $this->convertHoursToYearsMonthsDays($totalHours, 'of relevant experience');
    }

    public function formatTrainingForQualifiedExternal($trainingRecords)
    {
        if ($trainingRecords->isEmpty()) {
            return '';
        }

        $totalHours = $trainingRecords->sum(fn($t) => (float) ($t->number_of_hours ?? 0));

        return "{$totalHours} hours of relevant training";
    }

    public function formatEligibilityForQualifiedExternal($eligibilityRecords)
    {
        if ($eligibilityRecords->isEmpty()) {
            return '';
        }

        $formatted = [];
        foreach ($eligibilityRecords as $eligibility) {
            $name   = $eligibility->eligibility ?? '';
            $rating = !empty($eligibility->rating)
                ? ' - Rating: ' . number_format(floor((float)$eligibility->rating * 100) / 100, 2)
                : '';
            $formatted[] = "• {$name}{$rating}";
        }

        return implode('<br>', $formatted);
    }

    // --- INTERNAL format helpers ---

    public function formatEducationForQualifiedInternal($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return '.';
        }

        $formatted = [];
        foreach ($educationRecords as $edu) {
            $degree = $edu->Degree ?? '';
            $unit   = $edu->NumUnits ?? '';
            $formatted[] = "• {$degree} ({$unit} units)";
        }

        return implode('<br>', $formatted);
    }

    public function formatExperienceForQualifiedInternal($experienceRecords)
    {
        if ($experienceRecords->isEmpty()) {
            return '';
        }

        $totalHours = 0;
        foreach ($experienceRecords as $exp) {
            $from = $exp->WFrom ?? null;
            $to   = $exp->WTo   ?? null;

            if ($from && $to) {
                $start = \DateTime::createFromFormat('m/d/Y', $from);
                $end   = \DateTime::createFromFormat('m/d/Y', $to);
                if ($start && $end && $end >= $start) {
                    $totalHours += $this->countWeekdaysBetween($start, $end) * 8;
                }
            }
        }

        return $this->convertHoursToYearsMonthsDays($totalHours, 'of relevant experience');
    }

    public function formatTrainingForQualifiedInternal($trainingRecords)
    {
        if ($trainingRecords->isEmpty()) {
            return '';
        }

        $totalHours = $trainingRecords->sum(fn($t) => (float) ($t->NumHours ?? 0));

        return "{$totalHours} hours of relevant training";
    }

    public function formatEligibilityForQualifiedInternal($eligibilityRecords)
    {
        if ($eligibilityRecords->isEmpty()) {
            return '';
        }

        $formatted = [];
        foreach ($eligibilityRecords as $eligibility) {
            $name   = $eligibility->CivilServe ?? '';
            $rating = !empty($eligibility->Rates)
                ? ' - Rating: ' . number_format(floor((float)$eligibility->Rates * 100) / 100, 2)
                : '';
            $formatted[] = "• {$name}{$rating}";
        }

        return implode('<br>', $formatted);
    }

    // fetch the rating of the rater on the specific job post
    public function ratingFormQualificationStandard($validated)
    {
        $user = User::where('id', $validated['raterId'])->first();

        $jobBatch = JobBatchesRsp::with([
            'ratingScores' => function ($query) use ($validated) {
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
                )->where('user_id', $validated['raterId'])
                    ->with([
                        'internalApplicant' => fn($q) => $q->select('id', 'firstname', 'lastname'),
                        'externalApplicant' => fn($q) => $q->select('ControlNo', 'Firstname', 'Surname'),
                    ]);
            },
            'criteriaRatings' => function ($query) {
                $query->select('id', 'job_batches_rsp_id')
                    ->with(['educations', 'experiences', 'trainings', 'performances', 'behaviorals']);
            },
            'criteria' => function ($query) {
                $query->select('id', 'job_batches_rsp_id', 'Education', 'Eligibility', 'Training', 'Experience')->get();
            }
        ])
            ->select('id', 'Office', 'Position', 'ItemNo', 'SalaryGrade')
            ->where('id', $validated['job_batches_rsp_id'])
            ->first();

        if (!$jobBatch) {
            return response()->json(['message' => 'Job batch not found'], 404);
        }

        $criteria = $jobBatch->criteriaRatings->first();
        $qs = $jobBatch->criteria->first();

        return response()->json([
            'office'       => $jobBatch->Office,
            'position'     => $jobBatch->Position,
            'item_no'      => $jobBatch->ItemNo,
            'salary_grade' => $jobBatch->SalaryGrade,

            'qs' => $qs ? [
                'education'   => $qs->Education,
                'experience'  => $qs->Experience,
                'training'    => $qs->Training,
                'eligibility' => $qs->Eligibility,
            ] : null,

            'criteria' => $criteria ? [
                'education'   => $criteria->educations,
                'experience'  => $criteria->experiences,
                'training'    => $criteria->trainings,
                'performance' => $criteria->performances,
                'behavioral'  => $criteria->behaviorals,
                'exams'       => $criteria->exams,
            ] : null,

            'rating_scores' => $jobBatch->ratingScores->map(fn($score) => [
                'nPersonalInfo_id' => $score->nPersonalInfo_id,
                'ControlNo'        => $score->ControlNo,
                'firstname'        => $score->internalApplicant?->firstname ?? $score->externalApplicant?->Firstname ?? null,
                'lastname'         => $score->internalApplicant?->lastname  ?? $score->externalApplicant?->Surname  ?? null,
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

            'rater_assigned' => [
                'id'             => $user->id,
                'name'           => $user->name,
                'role'           => $user->role ?? 'City Administrator',
                'representative' => $user->representative ?? '',
                'position'       => $user->position ?? '',
                'role_type'      => $user->role_type ?? '',
                'prefix'         => $user->prefix ?? '',
                'suffix'         => $user->suffix ?? '',
            ],
        ]);
    }

    // applicant ranking
    public function ranking($jobpostId, $request)
    {
        $jobpost = JobBatchesRsp::findOrFail($jobpostId);

        $totalAssigned = Job_batches_user::where('job_batches_rsp_id', $jobpostId)
            ->whereHas('user', fn($q) => $q->where('active', 1))
            ->count();

        $totalCompleted = Job_batches_user::where('job_batches_rsp_id', $jobpostId)
            ->where('status', 'complete')
            ->count();

        $allScores = rating_score::select(
            'rating_score.id',
            'rating_score.user_id as rater_id',
            'rating_score.nPersonalInfo_id',
            'rating_score.ControlNo',
            'rating_score.job_batches_rsp_id',
            'rating_score.education_score as education',
            'rating_score.experience_score as experience',
            'rating_score.training_score as training',
            'rating_score.performance_score as performance',
            'rating_score.behavioral_score as bei',
            'rating_score.exam_score',
            'rating_score.grand_total',
            'nPersonalInfo.firstname',
            'nPersonalInfo.lastname',
            'submission.id as submission_id'
        )
            ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
            ->leftJoin('users', 'users.id', '=', 'rating_score.user_id')
            ->leftJoin('submission', function ($join) {
                $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
                    ->whereColumn('submission.nPersonalInfo_id', 'rating_score.nPersonalInfo_id');
            })
            ->where('rating_score.job_batches_rsp_id', $jobpostId)
            ->get();

        $scoresByApplicant = $allScores->groupBy(
            fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo
        );

        $applicants = [];
        foreach ($scoresByApplicant as $applicantKey => $scoreRows) {
            $firstRow  = $scoreRows->first();
            $firstname = $firstRow->firstname;
            $lastname  = $firstRow->lastname;
            $imageUrl  = null;

            $office          = null;
            $designation     = null;
            $lengthOfService = null;

            // ── Internal Applicant ─────────────────────────────────────────────
            if ((!$firstname || !$lastname) && $firstRow->ControlNo) {

                $active    = xPersonal::where('ControlNo', $firstRow->ControlNo)->first();
                $firstname = $active->Firstname ?? null;
                $lastname  = $active->Surname   ?? null;
                $pics      = $active->Pics ?? null;

                $current_service = DB::table('xService')
                    ->where('ControlNo', $firstRow->ControlNo)
                    ->orderByDesc('ToDate')
                    ->orderByDesc('FromDate')
                    ->first();

                $office      = $current_service->Office      ?? null;
                $designation = $current_service->Designation ?? null;

                if ($pics) {
                    if (str_starts_with($pics, '\\\\') || str_starts_with($pics, '//')) {
                        $imageUrl = config('app.url') . '/api/employee/photo/' . $firstRow->ControlNo;
                    } elseif (filter_var($pics, FILTER_VALIDATE_URL)) {
                        $imageUrl = $pics;
                    }
                }

                $xservice  = xService::select('FromDate', 'ToDate')->where('ControlNo', $firstRow->ControlNo)->get();
                $totalDays = 0;
                $today     = Carbon::now();

                foreach ($xservice as $service) {
                    $from = Carbon::parse($service->FromDate);
                    $to   = Carbon::parse($service->ToDate ?? now());

                    if ($to->isFuture())   $to   = $today;
                    if ($from->isFuture()) continue;

                    $totalDays += $from->diffInDays($to);
                }

                $years  = intdiv($totalDays, 365);
                $remain = $totalDays % 365;
                $months = intdiv($remain, 30);
                $days   = $remain % 30;

                $lengthOfService = "{$years} years, {$months} months, {$days} days";
            }

            // ── External Applicant ─────────────────────────────────────────────
            if ($firstRow->nPersonalInfo_id) {
                $personalInfo = \App\Models\excel\nPersonal_info::find($firstRow->nPersonalInfo_id);
                $rawImagePath = $personalInfo->image_path ?? null;

                if ($rawImagePath) {
                    if (filter_var($rawImagePath, FILTER_VALIDATE_URL)) {
                        $imageUrl = $rawImagePath;
                    } elseif (Storage::disk('public')->exists($rawImagePath)) {
                        $imageUrl = config('app.url') . '/api/applicant/photo/' . $firstRow->nPersonalInfo_id;
                    }
                }
            }

            // ── Per-rater breakdown ────────────────────────────────────────────
            $raterBreakdown = [];
            foreach ($scoreRows as $row) {
                $total_qs = (float)$row->education
                    + (float)$row->experience
                    + (float)$row->training
                    + (float)$row->performance;

                $raterBreakdown[] = [
                    'rater_id'    => $row->rater_id,
                    'rater_name'  => $row->rater_name,
                    'education'   => number_format((float)$row->education,   2, '.', ''),
                    'experience'  => number_format((float)$row->experience,  2, '.', ''),
                    'training'    => number_format((float)$row->training,    2, '.', ''),
                    'performance' => number_format((float)$row->performance, 2, '.', ''),
                    'total_qs'    => number_format($total_qs, 2, '.', ''),
                    'bei'         => $row->bei        !== null ? number_format((float)$row->bei,        2, '.', '') : null,
                    'exam_score'  => $row->exam_score !== null ? number_format((float)$row->exam_score, 2, '.', '') : null,
                ];
            }

            // ── Averaged totals ────────────────────────────────────────────────
            $scoresArray = $scoreRows->map(fn($row) => [
                'education'   => (float)$row->education,
                'experience'  => (float)$row->experience,
                'training'    => (float)$row->training,
                'performance' => (float)$row->performance,
                'bei'         => $row->bei,
                'exam_score'  => $row->exam_score,
            ])->toArray();

            $computed = RatingService::computeFinalScore($scoresArray);

            $applicants[] = [
                'nPersonalInfo_id'  => (string)$firstRow->nPersonalInfo_id,
                'ControlNo'         => $firstRow->ControlNo,
                'submission_id'     => $firstRow->submission_id,
                'firstname'         => $firstname,
                'lastname'          => $lastname,
                'image_url'         => $imageUrl,
                'office'            => $office ?? null,
                'current_position'  => $designation ?? null,
                'length_of_service' => $lengthOfService,
                'applicant_type'    => $firstRow->ControlNo ? 'internal' : 'external',
                'total_rating'      => $computed['total_qs'],
                'bei'               => $computed['bei'],
                'exam_score'        => $computed['exam_score'],
                'final_rating'      => $computed['grand_total'],
                'grand_total'       => $computed['grand_total'],
            ];
        }

        $collection = collect($applicants);

        if (!empty($search)) {
            $collection = $collection->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['firstname']), strtolower($search))
                    || str_contains(strtolower($item['lastname']),  strtolower($search))
                    || str_contains(strtolower((string)$item['ControlNo']), strtolower($search));
            })->values();
        }

        $rankedApplicants = RatingService::addRanking($collection->values()->all());
        $collection       = collect($rankedApplicants);

        return response()->json([
            'jobpost_id'        => $jobpostId,
            'total_assigned'    => $totalAssigned,
            'total_completed'   => $totalCompleted,
            'office'            => $jobpost->Office      ?? null,
            'office2'           => $jobpost->Office2     ?? null,
            'group'             => $jobpost->Group       ?? null,
            'division'          => $jobpost->Division    ?? null,
            'section'           => $jobpost->Section     ?? null,
            'unit'              => $jobpost->Unit        ?? null,
            'position'          => $jobpost->Position    ?? null,
            'Salary_Grade'      => $jobpost->SalaryGrade ?? null,
            'Plantilla_Item_No' => $jobpost->ItemNo      ?? null,
            'data'              => $collection,
        ]);
    }

    // get the publication have effectiveDate
    public function getEffectiveDate($validated)
    {
        $publicationDate = Carbon::parse($validated['publication_date'])->toDateString();
        $effectiveDate   = Carbon::parse($validated['effective_date'])->toDateString();

        $jobPosts = JobBatchesRsp::with(['submissions' => function ($query) {
            $query->where('status', 'Hired')->with('nPersonalInfo');
        }])
            ->whereDate('post_date', $publicationDate)
            ->get()
            ->map(function ($jobPost) use ($effectiveDate) {

                $hiredApplicants = $jobPost->submissions->map(function ($submission) use ($jobPost, $effectiveDate) {
                    $info = $submission->nPersonalInfo;

                    $xPersonal = null;
                    if (!$info && $submission->ControlNo) {
                        $xPersonal = DB::table('xPersonal')
                            ->where('ControlNo', $submission->ControlNo)
                            ->select('Firstname', 'Middlename', 'Surname')
                            ->first();
                    }

                    $name = null;
                    if ($info) {
                        $name = trim("{$info->firstname} {$info->middlename} {$info->lastname}");
                    } elseif ($xPersonal) {
                        $name = trim("{$xPersonal->Firstname} {$xPersonal->Middlename} {$xPersonal->Surname}");
                    }

                    $service = xService::where('submission_id', $submission->id)
                        ->whereDate('effectiveDate', $effectiveDate)
                        ->first();

                    if (!$service) return null;

                    return [
                        'submission_id' => $submission->id,
                        'control_no'    => $submission->ControlNo,
                        'name'          => $name,
                        'salary_grade'  => $jobPost->SalaryGrade,
                        'ItemNo'        => $jobPost->ItemNo,
                        'designation'   => $jobPost->Position,
                        'effectiveDate' => Carbon::parse($service->effectiveDate)->format('M d, Y'),
                    ];
                })
                    ->filter()
                    ->values();

                return [
                    'job_post_id'      => $jobPost->id,
                    'office'           => $jobPost->Office,
                    'office2'          => $jobPost->Office2,
                    'group'            => $jobPost->Group,
                    'division'         => $jobPost->Division,
                    'section'          => $jobPost->Section,
                    'unit'             => $jobPost->Unit,
                    'post_date'        => $jobPost->post_date,
                    'end_date'         => $jobPost->end_date,
                    'status'           => $jobPost->status,
                    'hired_applicants' => $hiredApplicants,
                ];
            })
            ->filter(fn($jobPost) => $jobPost['hired_applicants']->isNotEmpty())
            ->values();

        return response()->json([
            'success'          => true,
            'publication_date' => Carbon::parse($publicationDate)->format('M d, Y'),
            'effective_date'   => Carbon::parse($effectiveDate)->format('M d, Y'),
            'total_jobposts'   => $jobPosts->count(),
            'data'             => $jobPosts,
        ]);
    }
}
