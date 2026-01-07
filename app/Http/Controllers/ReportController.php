<?php

namespace App\Http\Controllers;

use PHPUnit\Util\PHP\Job;
use App\Models\Submission;
use App\Models\rating_score;
use Illuminate\Http\Request;
use App\Models\JobBatchesRsp;
use App\Services\RatingService;
use PhpParser\Node\Expr\FuncCall;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{

    // Generate Report on plantilla Structure...
    public function generatePlantilla(Request $request)
    {
        // $rows = DB::table('vwplantillastructure as p')
        //     ->leftJoin('vwActive as a', 'a.ControlNo', '=', 'p.ControlNo')
        //     ->leftJoin('vwofficearrangement as o', 'o.Office', '=', 'p.office')
        //     ->leftJoin('xService as s', 's.ControlNo', '=', 'p.ControlNo')->select('PMID')->lastest()->limit(1)
        //     ->select(
        //         'p.*',
        //         'a.Status as status',
        //         'a.Steps as steps',
        //         'a.Birthdate as birthdate',
        //         'a.Surname as lastname',
        //         'a.Firstname as firstname',
        //         'a.MIddlename as middlename',
        //     // 'a.MIddlename as Gr',
        //         'p.SG as salarygrade',
        //         'p.level',
        //         'o.office_sort',
        //         's.RateYear'
        //     )

        // authorized salary



        // actual salary
        $latestXService = DB::table('xService')
            ->select('ControlNo', DB::raw('MAX(PMID) as latest_pmid'))
            ->groupBy('ControlNo');


        $rows = DB::table('vwplantillastructure as p')
            ->leftJoin('vwActive as a', 'a.ControlNo', '=', 'p.ControlNo')
            ->leftJoin('vwofficearrangement as o', 'o.Office', '=', 'p.office')

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
                'o.office_sort',
                's.RateYear as rateyear' // ✅ correct RateYear
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

        foreach ($rows->groupBy('office') as $officeName => $officeRows) {
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
                // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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
                    // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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
                        // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
                        ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))

                        ->values();

                    $remainingGroupRows = $groupRows->reject(
                        fn($r) =>
                        is_null($r->division) &&
                        is_null($r->section) &&
                        is_null($r->unit)
                    );

                    // ----- SORT HERE by divordr -----
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
                            // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
                            ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))

                            ->values();

                        $remainingDivisionRows = $divisionRows->reject(
                            fn($r) =>
                            is_null($r->section) &&
                            is_null($r->unit)
                        );

                        // ----- SORT HERE by secordr -----
                        foreach ($remainingDivisionRows->sortBy('secordr')->groupBy('section') as $sectionName => $sectionRows) {
                            $sectionData = [
                                'section'   => $sectionName,
                                'employees' => [],
                                'units'     => []
                            ];

                            $sectionEmployees = $sectionRows->filter(
                                fn($r) =>
                                is_null($r->unit)
                            );
                            $sectionData['employees'] = $sectionEmployees
                                ->sortBy('ItemNo')
                                // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
                                ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))

                                ->values();

                            $remainingSectionRows = $sectionRows->reject(
                                fn($r) =>
                                is_null($r->unit)
                            );

                            // ----- SORT HERE by unitordr -----
                            foreach ($remainingSectionRows->sortBy('unitordr')->groupBy('unit') as $unitName => $unitRows) {
                                $sectionData['units'][] = [
                                    'unit'      => $unitName,
                                    'employees' => $unitRows
                                        ->sortBy('ItemNo')
                                        // ->map(fn($r) => $this->mapEmployee($r, $xServiceByControl))
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
        }

        $result = collect($result)->sortBy('office_sort')->values()->all();

        return response()->json($result);
    }

    private function mapEmployee($row, $xServiceByControl,)

    {
        $controlNo = $row->ControlNo;
        $status = $row->status;






        $dateOriginalAppointed = null;
        $dateLastPromotion = null;

        if ($controlNo && isset($xServiceByControl[$controlNo])) {
            $xList = $xServiceByControl[$controlNo]
                ->filter(fn ($svc) => $svc->Status == $status)
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
                            ->filter(fn ($svc) => strtotime($svc->FromDate) > strtotime($resignedToDate))
                            ->sortBy(fn ($svc) => strtotime($svc->FromDate) - strtotime($resignedToDate))
                            ->first();
                        $dateOriginalAppointed = $nextRow ? $nextRow->FromDate : null;
                    } else {
                        $dateOriginalAppointed = $first->FromDate;
                    }
                } else {
                    $dateOriginalAppointed = $xList->last()->FromDate;
                }

                // Promotion logic (numeric, non-strict grades)
                $numericGrades = $xList->pluck('Grades')->filter(function($g) {
                    return is_numeric($g);
                })->map(function($g) {
                    return (float)$g;
                });

                $highestGrade = $numericGrades->max();

                // Appointed Grades
                $appointedRow = $xList->first(fn($svc) => $svc->FromDate == $dateOriginalAppointed);
                $initialGrades = !is_null($appointedRow) ? $appointedRow->Grades : ($row->Grades ?? null);

                // Log for debugging
                logger([
                    // 'xactiveGrades' => $row->Grades,
                    'appointedRowGrades' => isset($appointedRow) ? $appointedRow->Grades : null,
                    'initialGrades' => $initialGrades,
                    'highestGrade' => $highestGrade,
                    'all xService Grades' => $xList->pluck('Grades'),
                    'numericGrades' => $numericGrades,
                    'dateOriginalAppointed' => $dateOriginalAppointed,
                ]);

                if (!is_null($dateOriginalAppointed) && !is_null($highestGrade) && !is_null($initialGrades)) {
                    // if current/initial grade is greater than or equal to highest, there is no promotion
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


        // ===============================
        // VACANT → FORCE ZERO
        // ===============================
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

        // ===============================
        // AUTHORIZED SALARY (ANNUAL)
        // ===============================
        $salaryGrade = $row->salarygrade;
        $monthlySalary = 0;

        if (!is_null($salaryGrade)) {
            $monthlySalary = DB::table('tblSalarySchedule')
                ->where('Grade', $salaryGrade)
                ->where('Steps', 1) // forced Step 1
                ->value('Salary') ?? 0;
        }

        $authorizedAnnual = $monthlySalary * 12;
        $authorizedSalaryFormatted = number_format($authorizedAnnual, 2);

        // ===============================
        // ACTUAL SALARY (ANNUAL)
        // ===============================
        $actual = number_format($row->rateyear ?? 0, 2);

        // ===============================
        // RETURN (FILLED POSITION)
        // ===============================
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

    // get all job post
    public function getJobPost(){

        $job = JobBatchesRsp::select('id','Office','Position','post_date','end_date')->get();

        return response()->json($job);


    }


    // report job post with applicant
    public function getApplicantJobPost($jobpostId)
    {
        $jobs = Submission::where('job_batches_rsp_id', $jobpostId)
            ->with(['nPersonalInfo:id,firstname,lastname',
            'job_batch_rsp:id,Office,Position',
            ])
            ->get()
            ->map(function ($item) {
                return [
                'n_personal_info' => $item->nPersonalInfo,
                'submission_id' => $item->id,
                    // 'nPersonalInfo_id' => $item->nPersonalInfo_id,
                    // 'ControlNo' => $item->ControlNo,
                    // 'job_batches_rsp_id' => $item->job_batches_rsp_id,
                    // 'education_remark' => $item->education_remark,
                    // 'experience_remark' => $item->experience_remark,
                    // 'training_remark' => $item->training_remark,
                    // 'eligibility_remark' => $item->eligibility_remark,
                    // 'total_qs' => $item->total_qs,
                    // 'grand_total' => $item->grand_total,
                    // 'ranking' => $item->ranking,
                'status' => $item->status,
                    // 'submitted' => $item->submitted,
                'apply_date' => $item->created_at ? $item->created_at->format('F d, Y') : null,
                'office' => $item->job_batch_rsp->Office ?? null,
                'position' => $item->job_batch_rsp->Position ?? null,



                    // 'updated_at' => $item->updated_at,
                    // 'education_qualification' => $item->education_qualification,
                    // 'experience_qualification' => $item->experience_qualification,
                    // 'training_qualification' => $item->training_qualification,
                    // 'eligibility_qualification' => $item->eligibility_qualification,
                ];
            });

        return response()->json($jobs);
    }

    // report job post with applicant have schedules
    public function getApplicantHaveSchedules($jobpostId)
    {
        $jobs = Submission::where('job_batches_rsp_id', $jobpostId)
            ->with('schedules:id,submission_id,batch_name,full_name')
            ->get()
            ->map(function ($item) {

                $schedule = $item->schedules->first(); // if hasMany

                return [
                    'id' => $item->id,
                    'ApplicantHaveSchedules' => $item->schedules,

                    'batch_name' => $schedule?->batch_name,
                    'full_name' => $schedule?->full_name,
                ];
            });

        return response()->json($jobs);
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
            return response()->json(['message' => 'No applicant ratings found'], 404);
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
    public function placementList($office)
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
    public function topFiveApplicants($postDate)
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


    // public function topFiveApplicants($postDate)
    // {
    //     // 1️⃣ Get ALL job posts for the given date
    //     $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
    //         ->select(
    //             'id',
    //             'Office',
    //             'Division',
    //             'Position',
    //             'SalaryGrade',
    //             'ItemNo'
    //         )
    //         ->get();

    //     $result = [];

    //     // 2️⃣ Loop each job post
    //     foreach ($jobPosts as $jobPost) {

    //         // 3️⃣ Get ALL rating scores for this job post
    //         $allScores = rating_score::select(
    //             'rating_score.id',
    //             'rating_score.user_id as rater_id',
    //             'users.name as rater_name',
    //             'rating_score.nPersonalInfo_id',
    //             'rating_score.ControlNo',
    //             'rating_score.job_batches_rsp_id',
    //             'rating_score.education_score as education',
    //             'rating_score.experience_score as experience',
    //             'rating_score.training_score as training',
    //             'rating_score.performance_score as performance',
    //             'rating_score.behavioral_score as bei',
    //             'rating_score.total_qs',
    //             'rating_score.grand_total',
    //             'rating_score.ranking',
    //             'nPersonalInfo.firstname',
    //             'nPersonalInfo.lastname',
    //             'nPersonalInfo.image_path'
    //         )
    //             ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
    //             ->leftJoin('users', 'users.id', '=', 'rating_score.user_id')
    //             ->where('rating_score.job_batches_rsp_id', $jobPost->id)
    //             ->get();

    //         // 4️⃣ Group scores per applicant
    //         $scoresByApplicant = $allScores->groupBy(
    //             fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo
    //         );

    //         $applicants = [];

    //         foreach ($scoresByApplicant as $applicantKey => $rows) {
    //             $first = $rows->first();

    //             $scoresArray = $rows->map(fn($row) => [
    //                 'education'   => (float) $row->education,
    //                 'experience'  => (float) $row->experience,
    //                 'training'    => (float) $row->training,
    //                 'performance' => (float) $row->performance,
    //                 'bei'         => (float) $row->bei,
    //             ])->toArray();

    //             $computed = RatingService::computeFinalScore($scoresArray);

    //             $applicants[] = [
    //                 'nPersonalInfo_id' => $first->nPersonalInfo_id,
    //                 'ControlNo'        => $first->ControlNo,
    //                 'firstname'        => $first->firstname,
    //                 'lastname'         => $first->lastname,
    //             ] + $computed;
    //         }

    //         // 5️⃣ Rank applicants & get TOP 5
    //         $rankedApplicants = collect(
    //             RatingService::addRanking($applicants)
    //         )
    //             ->sortBy('ranking')
    //             ->take(5)
    //             ->values();

    //         // 6️⃣ Push final job post result
    //         $result[] = [
    //             'office'        => $jobPost->Office,
    //             'division'      => $jobPost->Division,
    //             'position'      => $jobPost->Position,
    //             'salary_grade'  => $jobPost->SalaryGrade,
    //             'item_no'       => $jobPost->ItemNo,
    //             'top_applicants' => $rankedApplicants,
    //         ];
    //     }

    //     return response()->json([
    //         'post_date' => $postDate,
    //         'job_posts' => $result
    //     ]);
    // }

    // Palacement list iwth same position level
    // public function placementList($office)
    // {
    //     $serviceRanks = DB::table(DB::raw("
    //     (
    //         SELECT
    //             ControlNo,
    //             Designation,
    //             Grades,
    //             Renew,
    //             FromDate,
    //             ToDate,
    //             ROW_NUMBER() OVER (
    //                 PARTITION BY ControlNo
    //                 ORDER BY FromDate DESC
    //             ) AS rn
    //         FROM xService
    //     ) AS xs
    // "));

    //     $officeData = DB::table('vwplantillaStructure as vps')
    //         ->leftJoinSub(
    //             $serviceRanks->clone()->where('rn', 1),
    //             'curr',
    //             fn($join) => $join->on('curr.ControlNo', '=', 'vps.ControlNo')
    //         )
    //         ->leftJoinSub(
    //             $serviceRanks->clone()->where('rn', 2),
    //             'prev',
    //             fn($join) => $join->on('prev.ControlNo', '=', 'vps.ControlNo')
    //         )
    //         ->where('vps.office', $office)
    //         ->select(
    //             'vps.office',
    //             'vps.position',
    //             'vps.ControlNo',
    //             'vps.SG',
    //             'vps.Name4',
    //             'vps.ItemNo',

    //             'prev.Designation as previous_designation',
    //             'prev.Grades as previous_grade',
    //             // 'prev.FromDate as previous_from',
    //             // 'prev.ToDate as previous_to',

    //             // 'curr.Designation as current_designation',
    //             // 'curr.Grades as current_grade',

    //             DB::raw("
    //             CASE
    //                 WHEN
    //                     prev.Designation = curr.Designation

    //                 THEN 'Same position level'
    //                 ELSE curr.Renew
    //             END AS nature_of_movement
    //         ")
    //         )
    //         ->get();

    //     return response()->json([
    //         'office' => $officeData,
    //     ]);
    // }

    // list of qualified applicants  for job post publication
    public function listQualifiedApplicantsPublication($postDate)
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



    // formatting helpers for qualified applicants for the internal
    // Helper method to format education
    private function formatEducationForQualified($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return 'No relevant education based on the specific requirement of the position.';
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



    // list of qualified applicants  for job post publication
    public function listUnQualifiedApplicantsPublication($postDate)
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
            'Header' => 'Applicants UnQualified Standard',
            'Date' => "$postDate Publication",
            'jobPosts' => $responseJobs
        ]);
    }
    // // list of Unqualified applicants  for job post publication
    // public function listUnQualifiedApplicantsPublication($postDate)
    // {
    //     $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
    //         ->select('id', 'Position', 'ItemNo')
    //         ->with([
    //             'criteria:id,job_batches_rsp_id,Education,Experience,Training,Eligibility',
    //             'submissions' => function ($query) {
    //                 $query->select(
    //                     'id',
    //                     'job_batches_rsp_id',
    //                     'nPersonalInfo_id',
    //                     'ControlNo',
    //                     'status'
    //                 )
    //                     ->where('status', 'Unqualified')
    //                     ->with([
    //                         'nPersonalInfo:id,firstname,lastname',
    //                         'nPersonalInfo.education',
    //                         'nPersonalInfo.work_experience',
    //                         'nPersonalInfo.training',
    //                         'nPersonalInfo.eligibity',
    //                     ]);
    //             }
    //         ])
    //         ->get();

    //     $responseJobs = [];



    //     foreach ($jobPosts as $job) {

    //         $applicants = [];

    //         foreach ($job->submissions as $submission) {

    //             // ✅ INTERNAL / OUTSIDER
    //             if ($submission->nPersonalInfo) {
    //                 $applicants[] = [
    //                     'firstname' => $submission->nPersonalInfo->firstname,
    //                     'lastname'  => $submission->nPersonalInfo->lastname,
    //                     'current_designation' => null,
    //                     'office' => null,
    //                     'status' => $submission->status,
    //                     'applicant_status' => 'OUTSIDER',
    //                     'education' => $submission->nPersonalInfo->education,
    //                     'training' => $submission->nPersonalInfo->training,
    //                     'eligibity' => $submission->nPersonalInfo->eligibity,
    //                     'work_experience' => $submission->nPersonalInfo->work_experience,
    //                 ];
    //             }

    //             // ✅ EXTERNAL
    //             elseif ($submission->ControlNo) {

    //                 $tempReorg = DB::table('tempRegAppointmentReorg')
    //                     ->where('ControlNo', $submission->ControlNo)
    //                     ->select('Office', 'Designation')
    //                     ->first();

    //                 $xPDS = new \App\Http\Controllers\xPDSController();
    //                 $employeeData = $xPDS->getPersonalDataSheet(
    //                     new \Illuminate\Http\Request([
    //                         'controlno' => $submission->ControlNo
    //                     ])
    //                 );
    //                 $employeeJson = $employeeData->getData(true);

    //                 $applicants[] = [
    //                     'controlno' => $submission->ControlNo,
    //                     'firstname' => $employeeJson['User'][0]['Firstname'] ?? '',
    //                     'lastname'  => $employeeJson['User'][0]['Surname'] ?? '',
    //                     'current_designation' => $tempReorg->Designation ?? null,
    //                     'office' => $tempReorg->Office ?? null,
    //                     'status' => $submission->status,
    //                     'applicant_status' => 'EXTERNAL',
    //                     'education' => $employeeJson['Education'] ?? [],
    //                     'training' => $employeeJson['Training'] ?? [],
    //                     'eligibity' => $employeeJson['Eligibility'] ?? [],
    //                     'work_experience' => $employeeJson['Experience'] ?? [],
    //                 ];
    //             }
    //         }

    //         // ✅ BUILD FINAL JOB OBJECT (ORDER GUARANTEED)
    //         $responseJobs[] = [
    //             'id' => $job->id,
    //             'Position' => $job->Position,
    //             'ItemNo' => $job->ItemNo,
    //             'criteria' => $job->criteria,
    //             'applicants' => $applicants
    //         ];
    //     }

    //     return response()->json([
    //         'Header' => 'Applicants Qualified Standard',
    //         'Date' => "$postDate Publication",
    //         'jobPosts' => $responseJobs
    //     ]);
    // }
}
