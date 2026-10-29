<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeUpdateCredentialsRequest;
use App\Services\TemporaryService;
use Illuminate\Http\Request;

class TemporaryController extends Controller
{
   

   
    protected TemporaryService $temporary_service;


    public function __construct(TemporaryService $temporary_service)
    {
        $this->temporary_service = $temporary_service;
      
    }


      //update tempreg and xservice and xpersonal  of the employee
    public function updateTemporaryReorgAndExt(EmployeeUpdateCredentialsRequest $request, string $ControlNo)
    {

        $validated = $request->validated();

        $result = $this->temporary_service->updateTemporaryReorgAndExt($ControlNo, $validated);

        return $result;
    }

}
