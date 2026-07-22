<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    protected array $reassignedControlNos;

    public function __construct($resource, array $reassignedControlNos = [])
    {
        parent::__construct($resource);
        $this->reassignedControlNos = $reassignedControlNos;
    }

    public function toArray($request)
    {
        return [
            'ControlNo'   => $this->ControlNo,
            'Office'      => $this->Office,
            'Designation' => $this->Designation,
            'Status'      => $this->Status,
            'Name4'       => $this->Name4,
            're_assign'   => in_array($this->ControlNo, $this->reassignedControlNos),
        ];
    }
}
