# 22/04/26


# done
    - added: xPersonal Diversity
    - added: user role
    - added: submissionId xPersonalDiversity


# refactor the activity log

    - user management 
    
# added: permission user management

    - reportApplicantAccess
    - requestPublication
    - reportPlantillaAccess
    - viewApplicantAccess
    - modifyApplicantAccess
    - eportApplicantAccess
    

# 5/5/2026 

 # add
    - jobpostWithBei 
    - create on the applicant qs edit

    

    {{base_url_rsp}}/api/report/job-bei
     {
        "id": 3,
        "Office": "OFFICE OF THE CITY BUDGET OFFICER",
        "Position": "ADMINISTRATIVE ASSISTANT I (BOOKBINDER III)",
        "status": "Occupied",
        "post_date": "2026-03-24 00:00:00.000",
        "end_date": "2026-03-30",
        "criteria_ratings": [
            {
                "id": 2,
                "job_batches_rsp_id": "3",
                "status": "created",
                "created_at": "2026-03-25T07:56:02.093000Z",
                "updated_at": "2026-03-25T07:56:02.093000Z",
                "behaviorals": [
                    {
                        "id": 46,
                        "criteria_rating_id": "2",
                        "weight": "30",
                        "description": "Candidates' response contained all of the target behavior/competencies",
                        "created_at": "2026-04-13T02:48:52.683000Z",
                        "updated_at": "2026-04-13T02:48:52.683000Z",
                        "percentage": "30"
                    },
                    {
                        "id": 47,
                        "criteria_rating_id": "2",
                        "weight": "30",
                        "description": "Candidates' response contained many of the target behavior/competencies",
                        "created_at": "2026-04-13T02:48:52.690000Z",
                        "updated_at": "2026-04-13T02:48:52.690000Z",
                        "percentage": "25"
                    },
                    {
                        "id": 48,
                        "criteria_rating_id": "2",
                        "weight": "30",
                        "description": "Candidates' response contained some of the target behavior/competencies",
                        "created_at": "2026-04-13T02:48:52.697000Z",
                        "updated_at": "2026-04-13T02:48:52.697000Z",
                        "percentage": "20"
                    },
                    {
                        "id": 49,
                        "criteria_rating_id": "2",
                        "weight": "30",
                        "description": "Candidates' response contained very few of the target behavior/competencies",
                        "created_at": "2026-04-13T02:48:52.707000Z",
                        "updated_at": "2026-04-13T02:48:52.707000Z",
                        "percentage": "15"
                    }
                ]
            }
        ]
    },




# Update Api

    // METHOD: GET ADD APPLICANT NO
    http://192.168.8.182:8000/api/job-batches-rsp/applicant/view/15?page=2&per_page=5&search=
   {
    "status": true,
    "qualified_applicants": 1,
    "unqualified_applicants": 0,
    "assessed": "1/10",
    "total_applicants": 10,
    "internal_applicants": 2,
    "external_applicants": 8,
    "applicants": {
        "current_page": 1,
        "data": [
            {
                "applicantNo": "1",
                "submission_id": "113",
                "nPersonalInfo_id": "88",
                "ControlNo": null,
                "job_batches_rsp_id": "15",
                "status": "Qualified",
                "firstname": "JUMAW",
                "lastname": "GINUO",
                "application_date": "2026-04-21",
                "applicant_type": "external"
            },
            {
                "applicantNo": "2",
                "submission_id": "114",
                "nPersonalInfo_id": "89",
                "ControlNo": null,
                "job_batches_rsp_id": "15",
                "status": "pending",
                "firstname": "DENIEL",
                "lastname": "TOMENIO",
                "application_date": "2026-04-22",
                "applicant_type": "external"
            },
            {
                "applicantNo": "3",
                "submission_id": "115",
                "nPersonalInfo_id": "90",
                "ControlNo": null,
                "job_batches_rsp_id": "15",
                "status": "pending",
                "firstname": "JEREMIE",
                "lastname": "RUBIO",
                "application_date": "2026-04-22",
                "applicant_type": "external"
            },
            {
                "applicantNo": "4",
                "submission_id": "117",
                "nPersonalInfo_id": null,
                "ControlNo": "022395",
                "job_batches_rsp_id": "15",
                "status": "pending",
                "firstname": "DENIEL",
                "lastname": "TOMENIO",
                "application_date": "2026-04-28",
                "applicant_type": "internal"
            },
        
        ],
    }

    // METHOD: GET updated
    http://192.168.8.182:8000/api/dashboard


}



# Added 

// METHOD: DELETE
http://192.168.8.182:8000/api/library/remark/delete/{remakrsId}

// METHOD: POST
http://192.168.8.182:8000/api/library/remark/update/{remakrsId}


// METHOD:POST
http://192.168.8.182:8000/api/library/remark/store

// METHOD: GET
http://192.168.8.182:8000/api/library/remark/index





# update api 

 METHOD:POST
 // individual generate report on rater on admin
http://192.168.8.182:8000/api/report/rating-form

Sample{
    
job_batches_rsp_id : 49
raterId : 31

}




# unqulified 

METHOD:GET
// list of qualified on the jobpost only
http://192.168.8.182:8000/api/job-batches-rsp/applicant/unqualified/{jobPostId}


METHOD:GET
//qs qualification and remarks of the applicant
http://192.168.8.182:8000/api/job-batches-rsp/applicant/qualification/remarks/{jobPostId}/{submissionId}

