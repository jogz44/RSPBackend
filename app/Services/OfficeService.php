<?php

namespace App\Services;

use App\Http\Resources\EmployeeResource;
use App\Models\EmployeeAssign;
use App\Models\EmployeeReAssign;
use App\Models\Office;
use App\Models\vwActive;
use App\Models\vwplantillastructure;

class OfficeService
{


   public function employee(string $office)
    {
        // employees whose home office matches
        $homeEmployees = vwActive::select('ControlNo', 'Office', 'Designation', 'Status', 'Name4')
            ->where('Office', $office)
            ->get();

        // control numbers of CONTRACTUAL/JOB ORDER/CASUAL employees explicitly assigned to this office
        $assignedControlNos = EmployeeAssign::where('office', $office)
            ->pluck('control_no')
            ->toArray();

        $nonPlantillaStatuses = ['CONTRACTUAL', 'JOB ORDER', 'CASUAL','HONORARIUM'];

        // filter: keep regular employees as-is; keep non-plantilla employees only if in EmployeeAssign
        $filterAssigned = function ($employee) use ($nonPlantillaStatuses, $assignedControlNos) {
            if (in_array(strtoupper($employee->Status), $nonPlantillaStatuses)) {
                return in_array($employee->ControlNo, $assignedControlNos);
            }
            return true;
        };

        $homeEmployees = $homeEmployees->filter($filterAssigned)->values();

        // control numbers of employees actively re-assigned INTO this office
        $reassignedInControlNos = EmployeeReAssign::where('office', $office)
            ->where('active', 1)
            ->pluck('control_no')
            ->toArray();

        // pull those employees' details too (their home Office may differ)
        $reassignedInEmployees = vwActive::select('ControlNo', 'Office', 'Designation', 'Status', 'Name4')
            ->whereIn('ControlNo', $reassignedInControlNos)
            ->get()
            ->filter($filterAssigned)
            ->values();

        // merge + dedupe by ControlNo
        $employees = $homeEmployees->concat($reassignedInEmployees)
            ->unique('ControlNo')
            ->values();

        // re-check active reassignment status for the final combined list
        $reassignedControlNos = EmployeeReAssign::whereIn('control_no', $employees->pluck('ControlNo'))
            ->where('active', 1)
            ->pluck('control_no')
            ->toArray();

        $resource = $employees->map(
            fn($employee) => new EmployeeResource($employee, $reassignedControlNos)
        );

        return $resource;
    }

    // structure of office plantilla
    public function structure($office)
    {
        



        // BASE RESULT STRUCTURE
        $officeData = [
       
            'office' => $office,
            'office2' => []
        ];

        // GET ALL RECORDS FOR THE OFFICE
        $allunits = vwplantillastructure::where('office', $office)
            ->orderBy('office2')
            ->orderBy('group')
            ->orderBy('division')
            ->orderBy('section')
            ->orderBy('unit')
            ->get();

        /* ============================================================
       1. PROCESS OFFICE2
    ============================================================ */

        $office2List = $allunits->unique('office2');

        foreach ($office2List as $office2Row) {

            $office2Name = $office2Row->office2 ?? null;

            $office2Data = [
                'office2' => $office2Name,
                'group' => []
            ];

            // FILTER ALL RECORDS UNDER THIS office2
            $office2units = $allunits->where('office2', $office2Name);

            /* ============================================================
           2. PROCESS group UNDER THIS office2
        ============================================================ */

            $group = $office2units->unique('group');

            foreach ($group as $groupRow) {

                $groupName = $groupRow->group ?? null;

                $groupData = [
                    'group' => $groupName,
                    'divisions' => [],
                    'sections_without_division' => [],
                    'units_without_division' => []
                ];

                // FILTER RECORDS FOR THIS GROUP
                $groupunits = $office2units->where('group', $groupName);

                /* ============================================================
               3. PROCESS divisionS UNDER THIS GROUP
            ============================================================ */
                $divisions = $groupunits->whereNotNull('division')->unique('division');

                foreach ($divisions as $division) {

                    $divisionData = [
                        'division' => $division->division,
                        'sections' => [],
                        'units_without_section' => []
                    ];

                    // sectionS UNDER THIS division
                    $sections = $groupunits
                        ->where('division', $division->division)
                        ->whereNotNull('section')
                        ->unique('section');

                    foreach ($sections as $section) {

                        $sectionData = [
                            'section' => $section->section,
                            'units' => $groupunits
                                ->where('division', $division->division)
                                ->where('section', $section->section)
                                ->whereNotNull('unit')
                                ->pluck('unit')
                                ->unique()
                                ->values()
                                ->toArray()
                        ];

                        $divisionData['sections'][] = $sectionData;
                    }

                    // unitS WITHOUT section
                    $divisionunits = $groupunits
                        ->where('division', $division->division)
                        ->whereNull('section')
                        ->whereNotNull('unit')
                        ->pluck('unit')
                        ->unique()
                        ->values()
                        ->toArray();

                    $divisionData['units_without_section'] = $divisionunits;

                    $groupData['divisions'][] = $divisionData;
                }

                /* ============================================================
               4. sectionS WITHOUT division UNDER THIS GROUP
            ============================================================ */

                $sectionsWithoutdivision = $groupunits
                    ->whereNull('division')
                    ->whereNotNull('section')
                    ->unique('section');

                foreach ($sectionsWithoutdivision as $section) {

                    $sectionData = [
                        'section' => $section->section,
                        'units' => $groupunits
                            ->whereNull('division')
                            ->where('section', $section->section)
                            ->whereNotNull('unit')
                            ->pluck('unit')
                            ->unique()
                            ->values()
                            ->toArray()
                    ];

                    $groupData['sections_without_division'][] = $sectionData;
                }

                // unitS WITHOUT division AND section
                $unitsWithoutdivision = $groupunits
                    ->whereNull('division')
                    ->whereNull('section')
                    ->whereNotNull('unit')
                    ->pluck('unit')
                    ->unique()
                    ->values()
                    ->toArray();

                $groupData['units_without_division'] = $unitsWithoutdivision;

                $office2Data['group'][] = $groupData;
            }

            $officeData['office2'][] = $office2Data;
        }

        return [$officeData];
    }
}
