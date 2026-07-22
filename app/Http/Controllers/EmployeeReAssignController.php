<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeReAssignStoreRequest;
use App\Http\Requests\EmployeeReAssignUpdateRequest;
use App\Models\EmployeeReAssign;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class EmployeeReAssignController extends Controller
{
    //

    use ApiResponseTrait;

    public function storeEmployeeReAssign(EmployeeReAssignStoreRequest $request)
    {
        $validatedData = $request->validated();

        // check if this control_no already has an active re-assignment
        $existingActive = EmployeeReAssign::where('control_no', $validatedData['control_no'])
            ->where('active', 1)
            ->first();

        if ($existingActive) {
            return $this->errorMessage('Employee already has an active re-assignment', 409);
        }

        $employeeReAssign = EmployeeReAssign::create([
            ...$validatedData,
            'active' => 1,
        ]);

        return $this->successMessage($employeeReAssign, 'success re-assign employee', 200);
    }


    public function updateEmployeeReAssign(EmployeeReAssignUpdateRequest $request, int $employeeReAssignId)
    {
        $validatedData = $request->validated();

        // check if this control_no already has an active re-assignment
        $findEmployee = EmployeeReAssign::find($employeeReAssignId);

        if ($findEmployee) {
            return $this->errorMessage('Employee not found', 404);
        }

        $findEmployee->update($validatedData);

        return $this->successMessage($findEmployee, 'successfully', 200);
    }


    public function returnEmployeeReAssign(Request $request, string $controlNo, int $employeeReAssignId)
    {
        $validatedData = $request->validate([
            'active' => 'required|boolean|in:0'
        ]);

        $findEmployee = EmployeeReAssign::where('id', $employeeReAssignId)
            ->where('control_no', $controlNo)
            ->first();

        if (!$findEmployee) {
            return $this->errorMessage('Employee not found', 404);
        }

        $findEmployee->update($validatedData);

        return $this->successMessage($findEmployee, 'successfully', 200);
    }


    public function deleteEmployeeReAssign(int $employeeReAssignId)
    {
    
        $findEmployee = EmployeeReAssign::find($employeeReAssignId);

        if ($findEmployee) {
            return $this->errorMessage('Employee not found', 404);
        }

        $findEmployee->delete();

        return $this->successMessage($findEmployee, 'successfully deleted', 200);
    }
}
