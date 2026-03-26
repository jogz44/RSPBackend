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
               Thank you for submitting your application for the position of <strong>{{ $position }},</strong> Item No.<strong>{{ $ItemNo }}</strong>
            under the <strong>{{ $office }}.</strong>
            </p>


             <p>
                This is to formally acknowledge receipt of your application. Your application is
                currently under review by our Human Resource Merit Promotion and Selection Board
                Secretariat. We highly appreciate your interest in joining our organization and your
                willingness to contribute your skills and expertise.
            </p>


           <p>
                This is to formally acknowledge receipt of your application. Your application is
                currently under review by our Human Resource Merit Promotion and Selection Board
                Secretariat. We highly appreciate your interest in joining our organization and your
                willingness to contribute your skills and expertise.
            </p>
            </p>


            <ul>
                <li>Duly accomplished and subscribed Personal Data Sheet (PDS).</li>
                <li>Work Experience Sheet</li>
                <li>Application Letter</li>

            </ul>

             <p>If you have applied for more than one position, a single submission of these documents
                 will suffice. You may forward the requirements via email at
                  <strong>lgutagumhrmo.recruitment@gmail.com </strong> or submit them in person at our office.
             </p>


            <p>Kindly await further updates regarding the status of your application. Should you have
                 any questions or require additional information, please feel free to contact us.</p>
            <p>Thank you, and we wish you the best of luck in the selection process.</p>

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
