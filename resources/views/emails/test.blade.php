 
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Notification</title>
</head>

<body style="margin:0; padding:0; font-family:Arial, Helvetica, sans-serif; background-color:#f7f7f7;">

    <table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f7f7f7">
        <tr>
            <td align="center" style="padding:30px 15px;">

                <table width="600" border="0" cellspacing="0" cellpadding="0"
                    bgcolor="#ffffff" style="border-radius:6px; overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td align="center" bgcolor="#1084ef" style="padding:20px;">
                            <img src="{{ $logo ?? '' }}"
                                 alt="{{ config('app.name') }}"
                                 style="display:block;height:48px;" />
                        </td>
                    </tr>

                    <!-- Title -->
                    <tr>
                        <td align="center" bgcolor="#1084ef" style="padding:12px;">
                            <span style="color:#ffffff;font-size:18px;font-weight:bold;">
                                Test Notification
                            </span>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 25px;">

                            <p style="font-size:15px;color:#333;">
                                Hello,
                            </p>

                            <p style="font-size:14px;color:#555;line-height:22px;">
                                This is a test notification from
                                <strong>{{ $Sitename ?? config('app.name') }}</strong>.
                                If you have received this message, your notification
                                system is working.
                            </p>

                            <h3 style="color:#1084ef;font-size:16px;">
                                Customer Details
                            </h3>

                            <table width="100%" cellpadding="10" cellspacing="0"
                                style="border-collapse:collapse;font-size:14px;color:#333;">

                                <tr>
                                    <td style="border:1px solid #eeeeee;width:40%;">
                                        <strong>Customer Name</strong>
                                    </td>
                                    <td style="border:1px solid #eeeeee;">
                                        {{ $customer ?? 'N/A' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="border:1px solid #eeeeee;">
                                        <strong>Customer Phone</strong>
                                    </td>
                                    <td style="border:1px solid #eeeeee;">
                                        {{ $customer_phone ?? 'N/A' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="border:1px solid #eeeeee;">
                                        <strong>Company Name</strong>
                                    </td>
                                    <td style="border:1px solid #eeeeee;">
                                        {{ $companydetails->CompanyName ?? 'N/A' }}
                                    </td>
                                </tr>

                            </table>

                            <p style="font-size:13px;color:#555;line-height:22px;margin-top:25px;">
                                This is an automated test message. No action is required.
                            </p>

                            <p style="font-size:14px;color:#333;">
                                Warm Regards,<br>
                                <strong>{{ $Sitename ?? config('app.name') }} Team</strong>
                            </p>

                        </td>
                    </tr>

                </table>

                <div style="text-align:center;padding:10px;font-size:12px;color:#777;">
                    &copy; {{ date('Y') }} {{ config('app.name') }}.
                    All rights reserved.
                </div>

            </td>
        </tr>
    </table>

</body>
</html>
