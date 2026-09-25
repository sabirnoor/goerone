<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8" />
    <title>Account OTP Verification</title>
</head>

<body style="margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; background-color:#f7f7f7;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f7f7f7">
        <tr>
            <td align="center" style="padding:30px 15px;">
                <table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff"
                    style="border-radius:6px; overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td align="center" bgcolor="#1084ef" style="padding:20px;">
                            <img src="<?=$logo?>" alt="{{ config('app.name') }}"
                                style="display:block;height: 48px;" />
                        </td>
                    </tr>

                    <!-- Title -->
                    <tr>
                        <td align="center" bgcolor="#1084ef" style="padding:12px;">
                            <span
                                style="color:#ffffff; font-size:18px; font-weight:bold; font-family: Arial, Helvetica, sans-serif;">
                                Account OTP Verification
                            </span>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td align="center" style="padding:30px 20px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding-bottom:20px;">
                                        <img src="https://crm.goertrip.club/images/unnamed.png" width="130" alt="OTP" style="display:block;" />
                                    </td>
                                </tr>
                                <tr>
                                    <td
                                        style="font-size:14px; color:#333333; line-height:22px; text-align:left; padding-bottom:20px;">
                                        <strong>Dear Customer!</strong><br><br>
                                        Kindly use the below-mentioned OTP for access CRM into the {{ config('app.name') }} account.
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:15px 0;">
                                        <span
                                            style="display:inline-block; font-size:28px; font-weight:bold; color:#004a8f; letter-spacing:4px; border:2px dashed #66afe9; padding:12px 30px;">
                                            <?=$OTPS?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px; color:#555555; text-align:left; padding:20px 0 10px;">
                                        Please do not share OTP with anyone.
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px; color:#333333; text-align:left;">
                                        Warm Regards, <br>
                                        <strong>From {{ config('app.name') }} Team</strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
    <div class="footer" style="text-align: center; padding:10px">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>

</html>
