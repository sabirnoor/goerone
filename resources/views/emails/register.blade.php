<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to <?= $user->name ?? '' ?></title>
</head>

<body
    style="margin: 0; padding: 0; border-radius: 10px; background-color: #fcfbf9; font-family: Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

    <table width="100%" border="0" cellspacing="0" cellpadding="0"
        style="background-color: #fcfbf9; padding: 40px 20px;border-radius: 10px;">
        <tr>
            <td align="center">

                <table width="100%" id="emailContainer"
                    style="max-width: 700px; background-color: #ffffff; border: 0px solid #f0ede7; border-collapse: collapse; border-radius: 28px;">
                    <tr>
                        <td style="padding: 40px 30px;">

                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td
                                        style="border-left: 3px solid #1d4ed8; padding-left: 12px; height: 35px; vertical-align: middle;">
                                        <span
                                            style="font-size: 20px; font-weight: bold; color: #333333;"><?= $user->name ?? '' ?></span>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="margin-top: 40px;">
                                <tr>
                                    <td style="font-size: 28px; font-weight: 800; color: #1d4ed8; line-height: 1.2;">
                                        Welcome to <?= $user->name ?? '' ?>!
                                    </td>
                                </tr>
                                <tr>
                                    <td
                                        style="font-size: 13px; color: #555555; line-height: 1.5; padding-top: 15px; padding-bottom: 20px;">
                                        Now you can access our core functionalities to simplify your business travel
                                        management, all on a single platform.
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <a href="<?= $websiteSettings->b2bdomain ?? '#' ?>"
                                            style="background-color: #1d4ed8; color: #ffffff; font-size: 12px; font-weight: bold; text-decoration: none; padding: 12px 25px; display: inline-block; border-radius: 5px; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Explore <?= $user->name ?? '' ?>
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="margin-top: 45px;">

                                <tr>
                                    <td style="padding-bottom: 30px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td width="60" valign="top">
                                                    <div
                                                        style="width: 48px; height: 48px; background-color: #fdf2ee; border-radius: 50%; text-align: center; line-height: 48px; color: #ff5e43; font-size: 20px;">
                                                        🛡️
                                                    </div>
                                                </td>
                                                <td valign="top" style="padding-left: 10px;">
                                                    <div
                                                        style="font-size: 14px; font-weight: bold; color: #111111; margin-bottom: 4px;">
                                                        Verify Your Organisation</div>
                                                    <div
                                                        style="font-size: 12px; color: #666666; line-height: 1.4; margin-bottom: 6px;">
                                                        Get access to Admin-exclusive functionalities by verifying your
                                                        organisation using your GSTIN No.</div>
                                                    <a href="#"
                                                        style="font-size: 10px; font-weight: bold; color: #3b6ef6; text-decoration: none; text-transform: uppercase;">Verify
                                                        Now</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 30px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td width="60" valign="top">
                                                    <div
                                                        style="width: 48px; height: 48px; background-color: #fdf2ee; border-radius: 50%; text-align: center; line-height: 48px; color: #ff5e43; font-size: 20px;">
                                                        ✈️
                                                    </div>
                                                </td>
                                                <td valign="top" style="padding-left: 10px;">
                                                    <div
                                                        style="font-size: 14px; font-weight: bold; color: #111111; margin-bottom: 4px;">
                                                        Special Fares on Flights</div>
                                                    <div
                                                        style="font-size: 12px; color: #666666; line-height: 1.4; margin-bottom: 6px;">
                                                        Book flights with our special fares, to enjoy more
                                                        benefits by spending less</div>
                                                    <a href="#"
                                                        style="font-size: 10px; font-weight: bold; color: #3b6ef6; text-decoration: none; text-transform: uppercase;">Explore
                                                        Flights</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 35px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td width="60" valign="top">
                                                    <div
                                                        style="width: 48px; height: 48px; background-color: #fdf2ee; border-radius: 50%; text-align: center; line-height: 48px; color: #ff5e43; font-size: 20px;">
                                                        🏨
                                                    </div>
                                                </td>
                                                <td valign="top" style="padding-left: 10px;">
                                                    <div
                                                        style="font-size: 14px; font-weight: bold; color: #111111; margin-bottom: 4px;">
                                                        <?= $user->name ?? '' ?> assured hotels</div>
                                                    <div
                                                        style="font-size: 12px; color: #666666; line-height: 1.4; margin-bottom: 6px;">
                                                        Check-in in hotels rated high by business travellers and get
                                                        assured GST invoice as well as 'Best Price' guarantee</div>
                                                    <a href="#"
                                                        style="font-size: 10px; font-weight: bold; color: #3b6ef6; text-decoration: none; text-transform: uppercase;">Explore
                                                        Hotels</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                            </table>

                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="margin-top: 10px; margin-bottom: 40px;">
                                <tr>
                                    <td style="font-size: 11px; color: #777777; line-height: 1.5;">
                                        Warm Regards,<br>
                                        <strong style="color: #111111; font-size: 12px;"><?= $user->name ?? '' ?>
                                            Team</strong>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="border-top: 1px solid #f0ede7; padding-top: 20px;">
                                <tr>
                                    <td style="font-size: 10px; color: #777777; line-height: 1.5; text-align: left;">
                                        <strong>Note:</strong> Please do not reply to this mail. It has been sent from
                                        an email account that is not monitored. To receive communication from <a
                                            href="#"
                                            style="color: #3b6ef6; text-decoration: none; font-weight: bold;"><?= $websiteSettings->b2bdomain ?? 'NA' ?></a>,
                                        please add <a href="mailto:<?= $user->email ?? '' ?>"
                                            style="color: #3b6ef6; text-decoration: none; font-weight: bold;"><?= $user->email ?? '' ?></a>
                                        to your contact list and address book.
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>

</html>
