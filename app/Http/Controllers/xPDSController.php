<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class xPDSController extends Controller
{

    //this function is getting the pds of employee on the database for regular
    public function getPersonalDataSheet(Request $request)
    {
        // Validate the request
        $request->validate([
            'controlno' => 'required|string'
        ]);

        $controlNo = $request->input('controlno');
        try {
            // Fetch data from all related tables
            $data = [
                'controlno' => $controlNo,
                'User' => $this->getCombinedUserData($controlNo),
                'Education' => $this->getEducationData($controlNo),
                'Eligibility' => $this->getEligibilityData($controlNo),
                'Experience' => $this->getExperienceData($controlNo),
                'Voluntary' => $this->getVoluntaryData($controlNo),
                'Training' => $this->getTrainingData($controlNo),
                'Skills' => $this->getSkillsData($controlNo),
                'Academic' => $this->getAcademicData($controlNo),
                'Organization' => $this->getOrganizationData($controlNo),
                'Reference' => $this->getReferenceData($controlNo),
            ];

            return Response::json($data, 200);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'An error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getCombinedUserData($controlNo)
    {
        // Get base personal data
        $personalData = $this->getUserData($controlNo);

        // If no personal data found, return empty array
        if (empty($personalData)) {
            return [];
        }

        // Get additional data from other tables
        $pwdData = $this->getPWDData($controlNo);
        $personalAddtData = $this->getPersonalAddtData($controlNo);
        $personalDiversityData = $this->getPersonalDiversityData($controlNo);
        $childrenData = $this->getChildrenData($controlNo);

        // Convert first user record to array if it's an object
        $userArray = is_object($personalData[0]) ? json_decode(json_encode($personalData[0]), true) : $personalData[0];

        // Merge all additional data into the user array
        if (!empty($pwdData)) {
            $userArray = array_merge($userArray, $this->convertToArray($pwdData));
        }

        if (!empty($personalAddtData)) {
            $userArray = array_merge($userArray, $this->convertToArray($personalAddtData));
        }

        if (!empty($personalDiversityData)) {
            $userArray = array_merge($userArray, $this->convertToArray($personalDiversityData));
        }

        // Add children data
        $userArray['children'] = $childrenData;

        return [$userArray];
    }

   
    private function getUserData($controlNo)
    {
        $result = DB::table('xPersonal')
            ->where('ControlNo', $controlNo)
            ->get();

        return $this->convertToArray($result);
    }

    private function getPWDData($controlNo)
    {
        $result = DB::table('xPWD')
            ->where('ControlNo', $controlNo)
            ->first();

        return $result;
    }

    private function getPersonalAddtData($controlNo)
    {
        $result = DB::table('xPersonalAddt')
            ->where('ControlNo', $controlNo)
            ->first();

        return $result;
    }

    private function getPersonalDiversityData($controlNo)
    {
        $result = DB::table('xPersonalDiversity')
            ->where('ControlNo', $controlNo)
            ->first();

        return $result;
    }

    private function getChildrenData($controlNo)
    {
        $result = DB::table('xChildren')
            ->where('ControlNo', $controlNo)
            ->select('ChildName', 'BirthDate', 'PMID')
            ->get();

        return $this->convertToArray($result);
    }

    private function getEducationData($controlNo)
    {
        $result = DB::table('xEducation')
            ->select([
                'PMID as id',   // 👈 rename here
                'ControlNo',
                'Education',
                'School',
                'Codes',
                'Degree',
                'NumUnits',
                'YearLevel',
                'DateAttend',
                'Honors',
                'Graduated',
                'Orders',
                // 'Honors',

                // add other columns you need
            ])
            ->where('ControlNo', $controlNo)
            ->orderBy('Orders')
            ->get()
            ->map(function ($row) {
                $row->id = (int) $row->id;
                return $row;
            });

        return $this->convertToArray($result);
    }



    private function getEligibilityData($controlNo)
    {
        $result = DB::table('xCivilService')
            ->select([
                'PMID as id',   // 👈 rename here
                'ControlNo',
                'Codes',
                'CivilServe',
                'Dates',
                'Rates',
                'Place',
                'LNumber',
                'LDate',

            ])
            ->where('ControlNo', $controlNo)
            ->get()
            ->map(function ($row) {

            $row->id = (int) $row->id;

            // ✅ format LDate here
            $row->LDate = $row->LDate
                ? \Carbon\Carbon::parse($row->LDate)->format('d/m/Y')
                : null;

            return $row;
        });

    return $this->convertToArray($result);
    }

    private function getExperienceData($controlNo)
    {
        // xExperience records
        $experience = DB::table('xExperience')
            ->select([
                'ID as id',
                'CONTROLNO',
                'WFrom',
                'WTo',
                'WPosition',
                'WCompany',
                'WSalary',
                'WGrade',
                'Status',
                'WGov',
            ])
            ->where('ControlNo', $controlNo)
            ->get()
            ->map(fn($row) => [
                'id'       => $row->id,
                'WFrom'    => $row->WFrom,
                'WTo'      => $row->WTo,
                'WPosition' => $this->upper($row->WPosition),
                'WCompany' => $this->upper($row->WCompany),
                'WSalary'  => $row->WSalary ? '₱ ' . number_format($row->WSalary, 2) : '₱ 0.00',
                'WGrade'   => $row->WGrade,
                'Status'   => $this->upper($row->Status),
                'WGov'     => $this->upper($row->WGov),
                'source'   => 'xExperience',
            ]);

        $latestService = DB::table('xService')
            ->where('ControlNo', $controlNo)
            ->orderByDesc('PMID')
            ->first();

        // xService records — mapped to xExperience format
        $service = DB::table('xService')
            ->select([
                'PMID as id',
                'ControlNo',
                'FromDate',
                'ToDate',
                'Designation',
                'Office',
                'Branch',
                'RateDay',
                'RateMon',
                'Grades',
                'Steps',
                'Status',
            ])
            ->where('ControlNo', $controlNo)
            ->get()
            ->map(fn($row) => [
                'id'        => $row->id,
                'WFrom'     => $row->FromDate ? \Carbon\Carbon::parse($row->FromDate)->format('d/m/Y') : null,

                // If this is the latest record AND ToDate is in the future → show "Present"
                'WTo'       => ($row->id === $latestService->PMID && \Carbon\Carbon::parse($row->ToDate)->isFuture())
                    ? 'PRESENT'
                    : ($row->ToDate ? \Carbon\Carbon::parse($row->ToDate)->format('d/m/Y') : null),

                'WPosition' => $row->Designation,
                'WCompany'  => trim($row->Office . '/' . $row->Branch),
                // CONTRACTUAL: RateDay x 22, others: RateMon
                'WSalary'   => $row->Status === 'CONTRACTUAL'
                    ? '₱ ' . number_format($row->RateDay * 22, 2)
                    : ($row->RateMon ? '₱ ' . number_format($row->RateMon, 2) : '₱ 0.00'),
                'WGrade'    => trim($row->Grades . '-' . $row->Steps),
                'Status'    => $row->Status,
                'WGov' => ($row->Status === 'CONTRACTUAL' || $row->Status === 'HONORARIUM') ? 'NO' : 'YES',
                'source'    => 'xService',
            ]);

        // ✅ Merge both collections and convert to array
        return $experience->merge($service)->values()->toArray();
    }

    private function getVoluntaryData($controlNo)
    {
        $result = DB::table('xNGO')
            ->where('ControlNo', $controlNo)
            ->get();

        return $this->convertToArray($result);
    }

    private function getTrainingData($controlNo)
    {
        $result = DB::table('xTrainings')
            ->select([
                'PMID as id',   // 👈 rename here
                'ControlNo',
                'Training',
                'Dates',
                'NumHours',
                'Conductor',
                'DateFrom',
                'DateTo',
                'type',


            ])
           ->where('ControlNo', $controlNo)
        ->get()
        ->map(function ($row) {

            $row->id = (int) $row->id;

            // ✅ format dates here
            $row->DateFrom = $row->DateFrom
                ? \Carbon\Carbon::parse($row->DateFrom)->format('d/m/Y')
                : null;

            $row->DateTo = $row->DateTo
                ? \Carbon\Carbon::parse($row->DateTo)->format('d/m/Y')
                : null;

            return $row;
        });

    return $this->convertToArray($result);
}

    private function getSkillsData($controlNo)
    {
        $result = DB::table('xSkills')
            ->where('ControlNo', $controlNo)
            ->select('ID', 'ControlNo', 'Skills')
            ->get();

        return $this->convertToArray($result);
    }

    private function getAcademicData($controlNo)
    {
        $result = DB::table('xNonAcademic')
            ->where('ControlNo', $controlNo)
            ->get();

        return $this->convertToArray($result);
    }

    private function getOrganizationData($controlNo)
    {
        $result = DB::table('xOrganization')
            ->where('ControlNo', $controlNo)
            ->get();

        return $this->convertToArray($result);
    }

    private function getReferenceData($controlNo)
    {
        $result = DB::table('xReference')
            ->where('ControlNo', $controlNo)
            ->select('ControlNo', 'Names', 'Address', 'TelNo', 'PMID')
            ->get();

        return $this->convertToArray($result);
    }

    /**
     * Convert Laravel Collection or stdClass to array
     */
    private function convertToArray($data)
    {
        return json_decode(json_encode($data), true);
    }

    // force Capital Letter
    private function upper($value)
    {
        return strtoupper(trim($value ?? ''));
    }
}
