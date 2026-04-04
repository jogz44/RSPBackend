<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background-color: #e9ecef;
            margin: 0;
            padding: 30px 0;
            color: #333;
        }

        .container {
            max-width: 1500px;
            background: #ffffff;
            margin: 0 auto;
            border: 1px solid #dcdcdc;
            padding: 50px;
            box-shadow: 0px 0px 12px rgba(0, 0, 0, 0.07);
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
            color: #c0392b;
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

        .header-image {
            width: 100%;
            height: 140px;
        }

        .notice-box {
            background-color: #fff5f5;
            border-left: 5px solid #c0392b;
            padding: 15px 20px;
            margin: 25px 0;
            border-radius: 3px;
        }

        .notice-box p {
            margin: 0;
            color: #c0392b;
            font-weight: bold;
            font-size: 15px;
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- Header Image -->
        <div>
            <img src="{{ $message->embed(public_path('images/emailHeader.png')) }}" alt="Logo" class="header-image">
        </div>

        <div class="content">
            <p>Dear <strong>{{ $fullname }}</strong>,</p>
            <p>Greetings of Peace and Safety!</p>

            <p>
                This pertains to your application for the <strong>{{ $position }}</strong>, Item No.
                <strong>{{ $ItemNo }}</strong> in the <strong>{{ $office }}</strong>.
            </p>

            <!-- ✅ Cancellation Notice Box -->
            <div class="notice-box">
                <p>⚠ NOTICE: Your scheduled interview has been CANCELLED.</p>
            </div>

            <p>
                Please be informed that the Behavioral Event Interview with the Human Resource
                Merit Promotion and Selection Board (HRMPSB) originally scheduled on:
            </p>

            <p>
                <strong>Cancelled Interview Details:</strong><br>
                <strong>Date:</strong> {{ $date }}<br>
                <strong>Time:</strong> {{ $time }}<br>
                <strong>Venue:</strong> {{ $venue }}<br>
            </p>

                <p>
                    has been <strong>cancelled</strong>. We sincerely apologize for any inconvenience this may have caused.
                    Please be patient and wait for the updated interview schedule Thank you.
                </p>

            <h3>Important Reminders:</h3>
            <ul>
                <li>Please <strong>disregard</strong> any previous notice regarding the above interview schedule.</li>
                <li>You will receive a <strong>new notification</strong> once a reschedule has been set.</li>
                <li>If you have any clarifications, you may reach us through our mobile no. <strong>09973962684</strong>.</li>
            </ul>

            <p>
                The City Government of Tagum through the Human Resource Merit Promotion and
                Selection Board (HRMPSB) upholds the principle of Equal Employment Opportunity.
                All applicants are treated fairly and evaluated based on merit, fitness, and
                qualifications, without discrimination of any kind.
            </p>

            <p>We appreciate your patience and understanding.</p>
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
