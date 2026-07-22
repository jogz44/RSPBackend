<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeAssignRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\EmployeeAssign;
use App\Models\EmployeeReAssign;
use App\Models\LibOffice;
use App\Models\vwActive;
use App\Services\OfficeService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    //

    use ApiResponseTrait;

    protected OfficeService $officeService;

    public function __construct(OfficeService $officeService)
    {
        $this->officeService = $officeService;
    }

    // get the employee under of the office
    public function getEmployee(string $office)
    {
        $data = $this->officeService->employee($office);

        return $this->successMessage($data, 'success', 200);
    }

    // fetch employee only JOB ORDER, CASUAL,CONTRACTUAl,HONORARIUM
    public function contractualEmployee(string $office)
    {

        $assignedControlNos = EmployeeAssign::pluck('control_no');

        $data = vwActive::select('ControlNo', 'Office', 'Designation', 'Status', 'Name4')
            ->where('Office', $office)
            ->whereIn('Status', ['CONTRACTUAL', 'CASUAL', 'HONORARIUM'])
            ->whereNotIn('ControlNo', $assignedControlNos)
            ->get();


        return $this->successMessage($data, 'success', 200);
    }

    public function officeStructure(string $office)
    {

        $data = $this->officeService->structure($office);

        return $this->successMessage($data, 'success fetch structure', 200);
    }


    // fetch
    public function index()
    {

        $data = LibOffice::select('id as officeId', 'office_name', 'created_at')->get();

        if (empty($data)) {
            return $this->successMessage($data, 'no record found ', 200);
        }

        return $this->successMessage($data, 'success fetch', 200);
    }

    // store 
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'office_name' => 'required|string|unique:lib_offices,office_name'
        ]);

        $result = LibOffice::create($validatedData);

        return $this->successMessage($result, 'success created', 200);
    }

    // update
    public function update(Request $request, int $officeId)
    {
        $office = LibOffice::find($officeId);

        if (!$office) {
            return $this->errorMessage('officeId are not found', 404);
        }

        $validatedData = $request->validate([
            'office_name' => 'required|string|unique:lib_offices,office_name,' . $officeId . ',id'
        ]);

        $office->update($validatedData);

        return $this->successMessage($office, 'success updated', 200);
    }

    // delete
    public function destroy(int $officeId)
    {

        $office = LibOffice::find($officeId);

        if (!$office) {
            return $this->errorMessage('officeId are not found', 404);
        }

        $office->delete();

        return $this->successMessage($office, 'deleted success', 200);
    }
}
