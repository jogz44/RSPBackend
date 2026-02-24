<?php

namespace App\Services;

use App\Models\criteria\criteria_rating;
use App\Models\library\CriteriaLibrary;
use Illuminate\Support\Facades\Auth;

class   CriteriaService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }

    // creating a criteria per job post and if the job post already have criteria then try to create a new one criteria for that post it will be update the old criteria
    public function  store($validated,$request)
    {
        $user = Auth::user();

        $results = [];

        $jobId = $validated['job_batches_rsp_id'];


        $criteria = criteria_rating::updateOrCreate(
            ['job_batches_rsp_id' => $jobId],
            ['status' => 'created']
        );

        // DELETE old records
        $criteria->educations()->delete();
        $criteria->experiences()->delete();
        $criteria->trainings()->delete();
        $criteria->performances()->delete();
        $criteria->behaviorals()->delete();

        // INSERT new education
        foreach ($request->education as $item) {
            $criteria->educations()->create([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT new experience
        foreach ($request->experience as $item) {
            $criteria->experiences()->create([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT training
        foreach ($request->training as $item) {
            $criteria->trainings()->create([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT performance
        foreach ($request->performance as $item) {
            $criteria->performances()->create([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT behavioral
        foreach ($request->behavioral as $item) {
            $criteria->behaviorals()->create([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }
        // Log::info('BEHAVIORAL DATA RECEIVED:', $request->behavioral);
        // Fetch the job details
        $job = \App\Models\JobBatchesRsp::find($criteria->job_batches_rsp_id);

        $jobPosition = $job->Position ?? 'N/A';
        $jobOffice = $job->Office ?? 'N/A';

        // Log creation or update with user and job info
        activity('Criteria')
            ->causedBy($user)
            ->performedOn($criteria)
            ->withProperties([
                'name' => $user->name,
                'job_position' => $jobPosition,
                'job_office' => $jobOffice,
                'status' => $criteria->status,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log($criteria->wasRecentlyCreated
                ? "User '{$user->name}' created criteria for {$jobPosition} position in {$jobOffice}"
                : "User '{$user->name}' updated criteria for {$jobPosition} position in {$jobOffice}");

        return response()->json([
            'success' => true,
            'message' => "Criteria stored for  job",
            'criteria' => $results,
        ]);
    }



    // deleting the criteria of job_post
    public function delete($id, $request)
    {

        $user = Auth::user(); // Get the authenticated user

        $criteria = criteria_rating::find($id);

        if (!$criteria) {
            return response()->json([
                'status' => false,
                'message' => 'Criteria not found.'
            ], 404);
        }

        $job = \App\Models\JobBatchesRsp::find($criteria->job_batches_rsp_id);
        $jobPosition = $job->Position ?? 'N/A';
        $jobOffice = $job->Office ?? 'N/A';

        $criteria->delete();


        // Log the deletion
        activity('Criteria')
            ->causedBy($user)
            ->performedOn($criteria)
            ->withProperties([
                'name' => $user->name,
                'job_position' => $jobPosition,
                'job_office' => $jobOffice,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("User '{$user->name}' deleted criteria for {$jobPosition} position in {$jobOffice}");

        return response()->json([
            'status' => true,
            'message' => 'Criteria deleted successfully.'
        ]);
    }


    // this is for view criteria on admin to view the criteria of the job post
    public function view($job_batches_rsp_id)
    {
        // Find the criteria_rating record for this job_batches_rsp_id
        $criteria = criteria_rating::with([
            'educations',
            'experiences',
            'trainings',
            'performances',
            'behaviorals'
        ])->where('job_batches_rsp_id', $job_batches_rsp_id)->first();

        if (!$criteria) {
            return response()->json(['message' => 'No criteria found for this job'], 404);
        }
        return response()->json([
            'education'   => $criteria->educations,
            'experience'  => $criteria->experiences,
            'training'    => $criteria->trainings,
            'performance' => $criteria->performances,
            'behavioral'  => $criteria->behaviorals,
        ]);
    }



    //-------------------criteria library----------------------------------//

    public function libStore($validated,$request)
    {
        $user = Auth::user(); // Get the authenticated user
        // --------------------------
        // 1. VALIDATION
        // --------------------------


        // --------------------------
        // 2. FIND OR CREATE SG RANGE
        // --------------------------
        $sgMin = $validated['sg_min'];
        $sgMax = $validated['sg_max'];

        $criteriaRange = CriteriaLibrary::firstOrCreate(
            [
                'sg_min' => $sgMin,
                'sg_max' => $sgMax
            ],

        );

        // Clear old items if re-updating
        $criteriaRange->criteriaLibEducation()->delete();
        $criteriaRange->criteriaLibExperience()->delete();
        $criteriaRange->criteriaLibTraining()->delete();
        $criteriaRange->criteriaLibPerformance()->delete();
        $criteriaRange->criteriaLibBehavioral()->delete();

     
        // INSERT new education
        foreach ($request->education as $item) {
            $criteriaRange->criteriaLibEducation()->updateOrCreate([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT new experience
        foreach ($request->experience as $item) {
            $criteriaRange->criteriaLibExperience()->updateOrCreate([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT training
        foreach ($request->training as $item) {
            $criteriaRange->criteriaLibTraining()->updateOrCreate([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT performance
        foreach ($request->performance as $item) {
            $criteriaRange->criteriaLibPerformance()->updateOrCreate([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // INSERT behavioral
        foreach ($request->behavioral as $item) {
            $criteriaRange->criteriaLibBehavioral()->updateOrCreate([
                'weight' => $item['weight'],
                'description' => $item['description'],
                'percentage' => $item['percentage']
            ]);
        }

        // --------------------------
        // 8. RESPONSE
        // --------------------------

        activity('Criteria Library')
            ->causedBy($user)
            ->performedOn($criteriaRange)
            ->withProperties([
                'performed_by' => $user->name,
                'sg_min' => $criteriaRange->sg_min,
                'sg_max' => $criteriaRange->sg_max,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("User '{$user->name}' created a new SG range: {$criteriaRange->sg_min}-{$criteriaRange->sg_max}.");

        return response()->json([
            'message' => 'Salary Grade Range Criteria saved successfully',
            'criteria_range_id' => $criteriaRange->id
        ], 201);
    }



    public function libUpdate($validated, $criteriaId, $request)
    {

        $user = Auth::user(); // Get the authenticated user
        // --------------------------
        // 1. VALIDATION
        // --------------------------


        // --------------------------
        // 2. FIND EXISTING RANGE
        // --------------------------
        $criteriaRange = CriteriaLibrary::findOrFail($criteriaId);

        // Update SG range values
        $criteriaRange->update([
            'sg_min' => $validated['sg_min'],
            'sg_max' => $validated['sg_max']
        ]);

        // --------------------------
        // Helper function to save category items
        // --------------------------
        $saveItems = function ($relation, $items) use ($criteriaRange) {
            $existingIds = [];

            foreach ($items as $item) {
                $record = $criteriaRange->$relation()->updateOrCreate(
                    ['id' => $item['id'] ?? null],
                    [
                        'weight' => $item['weight'], // from the item
                        'description' => $item['description'],
                        'percentage' => $item['percentage'],
                    ]
                );
                $existingIds[] = $record->id;
            }

            // Delete removed items
            $criteriaRange->$relation()->whereNotIn('id', $existingIds)->delete();
        };

        // --------------------------
        // Save all categories
        // --------------------------
        $saveItems('criteriaLibEducation', $validated['education']);
        $saveItems('criteriaLibExperience', $validated['experience']);
        $saveItems('criteriaLibTraining', $validated['training']);
        $saveItems('criteriaLibPerformance', $validated['performance']);
        $saveItems('criteriaLibBehavioral', $validated['behavioral']);



        activity('Criteria Library')
            ->causedBy($user)
            ->performedOn($criteriaRange)
            ->withProperties([
                'name' => $user->name,
                'sg_min' => $criteriaRange->sg_min,
                'sg_max' => $criteriaRange->sg_max,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("User '{$user->name}' updated the SG range to {$criteriaRange->sg_min}-{$criteriaRange->sg_max} for this criteria library.");
        // --------------------------
        // 3. LOAD UPDATED RELATIONS
        // --------------------------
        $criteriaRange->load([
            'criteriaLibEducation',
            'criteriaLibExperience',
            'criteriaLibTraining',
            'criteriaLibPerformance',
            'criteriaLibBehavioral',
        ]);

        // --------------------------
        // 4. FORMAT RESPONSE
        // --------------------------
        return response()->json([
            'sg_min' => $criteriaRange->sg_min,
            'sg_max' => $criteriaRange->sg_max,
            'created_at' => $criteriaRange->created_at,
            'updated_at' => $criteriaRange->updated_at,
            'education' => $criteriaRange->criteriaLibEducation,
            'experience' => $criteriaRange->criteriaLibExperience,
            'training' => $criteriaRange->criteriaLibTraining,
            'performance' => $criteriaRange->criteriaLibPerformance,
            'behavioral' => $criteriaRange->criteriaLibBehavioral,
        ], 200);
    }



    public function details($criteriaId)
    {
        $lib = CriteriaLibrary::with([
            'criteriaLibEducation:id,criteria_library_id,description,weight,percentage',
            'criteriaLibExperience:id,criteria_library_id,description,weight,percentage',
            'criteriaLibTraining:id,criteria_library_id,description,weight,percentage',
            'criteriaLibPerformance:id,criteria_library_id,description,weight,percentage',
            'criteriaLibBehavioral:id,criteria_library_id,description,weight,percentage',
        ])->findOrFail($criteriaId);

        // Format output
        $formatted = [
            'id' => $lib->id,
            'sg_min' => $lib->sg_min,
            'sg_max' => $lib->sg_max,
            'created_at' => $lib->created_at,
            'updated_at' => $lib->updated_at,
            'education' => $lib->criteriaLibEducation,
            'experience' => $lib->criteriaLibExperience,
            'training' => $lib->criteriaLibTraining,
            'performance' => $lib->criteriaLibPerformance,
            'behavioral' => $lib->criteriaLibBehavioral,
        ];

        return response()->json($formatted);
    }

    // fetch criteria base on the sg if the  job post are no criteria yet
    public function CriteriaJob($sg)
    {
        $sg = (int) $sg; // force integer

        $lib = CriteriaLibrary::with([
            'criteriaLibEducation:id,criteria_library_id,description,weight,percentage',
            'criteriaLibExperience:id,criteria_library_id,description,weight,percentage',
            'criteriaLibTraining:id,criteria_library_id,description,weight,percentage',
            'criteriaLibPerformance:id,criteria_library_id,description,weight,percentage',
            'criteriaLibBehavioral:id,criteria_library_id,description,weight,percentage',
        ])
            ->where('sg_min', '<=', $sg)   // sg_min <= SG
            ->where('sg_max', '>=', $sg)   // sg_max >= SG
            ->first();

        if (!$lib) {
            return response()->json(['message' => 'Criteria not found'], 404);
        }

        return response()->json([
            'id' => $lib->id,
            'sg_min' => $lib->sg_min,
            'sg_max' => $lib->sg_max,
            'created_at' => $lib->created_at,
            'updated_at' => $lib->updated_at,
            'education' => $lib->criteriaLibEducation,
            'experience' => $lib->criteriaLibExperience,
            'training' => $lib->criteriaLibTraining,
            'performance' => $lib->criteriaLibPerformance,
            'behavioral' => $lib->criteriaLibBehavioral,
        ]);
    }




    public function libDelete($criteriaId, $request)
    {
        $user = Auth::user(); // Get the authenticated user
        $criteria = CriteriaLibrary::findOrFail($criteriaId);

        $criteria->delete();

        // Activity log
        activity('Criteria Library')
            ->causedBy($user)
            ->performedOn($criteria)
            ->withProperties([
                'name' => $user->name,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("User '{$user->name}' deleted a criteria.");

        return response()->json([
            'message' => 'Criteria deleted successfully',
            'criteria' => $criteria
        ]);
    }


}
