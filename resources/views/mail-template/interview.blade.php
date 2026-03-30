<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- <title>{{ $mailSubject }}</title> --}}

    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background-color: #e9ecef;
            margin: 0;
            padding: 30px 0;
            color: #333;
        }

        /* Bond Paper Style Container */
        .container {
            max-width: 1500px;
            /* Wider like bond paper */
            background: #ffffff;
            margin: 0 auto;
            border: 1px solid #dcdcdc;
            /* Light paper border */
            padding: 50px;
            /* More spacing like a printed document */
            box-shadow: 0px 0px 12px rgba(0, 0, 0, 0.07);
        }

        .header {
            background-color: #1d8d07;
            color: #fff;
            text-align: center;
            padding: 25px;
            border-radius: 5px;
            margin-bottom: 40px;
        }

        .content p,
        .content li {
            font-size: 16px;
            line-height: 1.7;
            color: #333;
        }

        h3 {
            margin-top: 30px;
            margin-bottom: 10px;
            color: #1d8d07;
        }

        ul {
            padding-left: 20px;
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            padding: 15px;
            background: #f1f1f1;
            font-size: 13px;
            color: #666;
            border-radius: 5px;
        }
         .letterhead {
            text-align: center;
            margin-bottom: 30px;
        }

        .letterhead img {
            max-width: 200px;
            display: block;
            margin: 0 auto 10px;
        }

        .letterhead-text {
            color: #00703c;
            line-height: 1.3;
        }

        .letterhead-text div:nth-child(1),
        .letterhead-text div:nth-child(2) {
            font-size: 9pt;
            font-weight: 500;
        }

        .letterhead-text div:nth-child(3) {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header-image{

            width: 100%;
            height: 140px;
        }
    </style>
</head>

<body>
    <div class="container">
    <!-- Letterhead -->
        <div>

            <img src="{{ $message->embed(public_path('images/emailHeader.png')) }}" alt="Logo" class="header-image">

        </div>


        <div class="content">
            <p>Dear <strong>{{ $fullname }}</strong>,</p>
            <p>Greetings of Peace and Safety!</p>

            <p>
                This pertains to your application for the <strong>{{ $position }},</strong> Item No.<strong>{{ $ItemNo }}</strong>
              in the <strong>{{ $office }}.</strong>
            </p>


             <p>
                Having qualified to the position, please be informed that you are scheduled for a
                Behavioral Event Interview with the Human Resource Merit Promotion and Selection
                Board (HRMPSB) as one of the ecaluation assessment tool on our recruitment process.
                As such, kindly refer to the interview schedule below:
            </p>

            <p>
                <strong>Interview Details:</strong><br>
                <strong>Date:</strong> {{ $date }}<br>
                <strong>Time:</strong> {{ $time }}<br>
                <strong>Venue:</strong> {{ $venue }}<br>

            </p>

            <h3>Kindly be guided of the following reminders:</h3>
            <ul>
                <li>Please arrive (at least <strong>30 minutes</strong> before your scheduled time of interview).</li>
                <li>Bring a <strong>valid ID</strong> for identification purposes.</li>
                <li>Observe proper <strong>decent dress code</strong> in coming to an interview.</li>
                <li>Inform us in advance if you require special assistance (e.g., wheelchair or mobility support).</li>
                <li>Please be advised that HR personnel may take photos for documentation purposes only.
                    All data will be handled in accordance with data privacy principles.
                </li>
            </ul>

             <p>The City Goverment of Tagum thru the Human Resource Merit Promotion and Selection
                Board (HRMPSB) upholds the principle of Equal Employment Opportunity. All
                applicants are treated fairly and evaluated based on merit, fitness, and qualifications,
                without discrimination on the basis of gender, age, civil status, disability, religion, or
                other protected characteristics.
             </p>


            <ul>

                <li>If you have any clarifications, you may reach us through our mobile no. 09973962684</li>
            </ul>
            <p>Kindly reply to this email to <strong>confirm your attendance</strong></p>
            <p>Thank you.</p>

        </div>

              <!-- Signature -->
        <div class="signature-section">
            <p><strong>EDGARD C. DE GUZMAN</strong></p>
            <p>City Administrator</p>
            <p>Authorized Representative of the City Mayor</p>
            <p>Chairperson</p>
        </div>


    </div>
</body>

</html>
