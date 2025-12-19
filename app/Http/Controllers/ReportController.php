<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function generatePlantilla(Request $request)
    {
        $rows = DB::table('vwplantillastructure as p')
            ->leftJoin('vwActive as a', 'a.ControlNo', '=', 'p.ControlNo')
            ->leftJoin('vwofficearrangement as o', 'o.Office', '=', 'p.office')
            ->select(
                'p.*',
                'a.Status as status',
                'a.Steps as steps',
                'a.Birthdate as birthdate',
                'a.Surname as lastname',
                'a.Firstname as firstname',
                'a.MIddlename as middlename',
                'a.Grades as Grades',
                'p.level',
                'o.office_sort'
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

    private function mapEmployee($row, $xServiceByControl)
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
                    'xactiveGrades' => $row->Grades,
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

        return [
            'controlNo'   => $row->ControlNo,
            'Ordr'        => $row->Ordr,
            'itemNo'      => $row->ItemNo,
            'position'    => $row->position,
            'grades'      => $row->Grades,
            'authorized'  => '1,340,724.00',
            'actual'      => '1,340,724.00',
            'step'        => $row->steps ? $row->steps : '1',
            'code'        => '11',
            'type'        => 'C',
            'level'       => $row->level,
            'lastname'    => $row->ControlNo ? $row->lastname : 'VACANT',
            'firstname'   => $row->ControlNo ? $row->firstname : 'VACANT',
            'middlename'  => $row->ControlNo ? $row->middlename : 'VACANT',
            'birthdate'   => $row->ControlNo ? $row->birthdate : 'VACANT',
            'funded'      => $row->Funded,
            'status'      => $row->ControlNo ? $row->Status : 'VACANT',
            'dateOriginalAppointed' => $dateOriginalAppointed,
            'dateLastPromotion'     => $dateLastPromotion,
        ];
    }
}
