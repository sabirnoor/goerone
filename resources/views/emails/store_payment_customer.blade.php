<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment confirmed at {{ $store_name ?? '' }}</title>
</head>

<body
    style="margin: 0; padding: 0; background-color: #f4f6fb; font-family: Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

    <table width="100%" border="0" cellspacing="0" cellpadding="0"
        style="background-color: #f4f6fb; padding: 40px 20px;">
        <tr>
            <td align="center">

                <table width="100%" border="0" cellspacing="0" cellpadding="0" id="emailContainer"
                    style="max-width: 640px; background-color: #ffffff; border-collapse: collapse; border-radius: 16px;">
                    <tr>
                        <td style="padding: 40px 30px;">

                            {{-- Logo --}}
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" style="padding-bottom: 28px; border-bottom: 1px solid #e8ecf5;">
                                        <img src="{{ asset('goerone_logo.png') }}" alt="GoerOne" height="40"
                                            style="display: block; height: 40px; width: auto; border: 0;">
                                    </td>
                                </tr>
                            </table>

                            {{-- Heading --}}
                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="margin-top: 32px;">
                                <tr>
                                    <td style="font-size: 26px; font-weight: 800; color: #1d4ed8; line-height: 1.25;">
                                        Payment Confirmed
                                    </td>
                                </tr>
                                <tr>
                                    <td
                                        style="font-size: 14px; color: #333333; line-height: 1.6; padding-top: 20px;">
                                        Hi {{ $customer_name ?? 'there' }},
                                    </td>
                                </tr>
                                <tr>
                                    <td
                                        style="font-size: 14px; color: #555555; line-height: 1.6; padding-top: 12px; padding-bottom: 24px;">
                                        Your payment to <strong style="color: #111111;">{{ $store_name ?? '' }}</strong>
                                        has been successfully confirmed through GoerOne. Please find your payment
                                        details below for your records.
                                    </td>
                                </tr>
                            </table>

                            {{-- Payment details --}}
                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="background-color: #f4f7ff; border: 1px solid #dbe4ff; border-radius: 10px; border-collapse: separate;">
                                <tr>
                                    <td style="padding: 20px 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="font-size: 12px; color: #777777; padding-bottom: 4px;">Paid
                                                    to</td>
                                                <td align="right"
                                                    style="font-size: 14px; font-weight: bold; color: #111111; padding-bottom: 4px;">
                                                    {{ $store_name ?? '' }}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="border-top: 1px solid #dbe4ff; height: 1px; line-height: 1px; font-size: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 12px; color: #777777; padding: 6px 0 4px;">Amount
                                                    paid</td>
                                                <td align="right"
                                                    style="font-size: 16px; font-weight: bold; color: #1d4ed8; padding: 6px 0 4px;">
                                                    {{ $payment_amount ?? '' }}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="border-top: 1px solid #dbe4ff; height: 1px; line-height: 1px; font-size: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 12px; color: #777777; padding-top: 10px;">Payment
                                                    reference</td>
                                                <td align="right"
                                                    style="font-size: 14px; font-weight: bold; color: #111111; padding-top: 10px;">
                                                    {{ $payment_ref ?? '' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- CTA --}}
                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="margin-top: 28px;">
                                <tr>
                                    <td style="font-size: 14px; color: #555555; line-height: 1.6; padding-bottom: 20px;">
                                        Open GoerOne to view the complete transaction details.
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <a href="{{ $app_url ?? config('app.url') }}"
                                            style="background-color: #1d4ed8; color: #ffffff; font-size: 12px; font-weight: bold; text-decoration: none; padding: 13px 28px; display: inline-block; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Open GoerOne
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            {{-- Sign-off --}}
                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="margin-top: 36px; margin-bottom: 32px;">
                                <tr>
                                    <td style="font-size: 13px; color: #555555; line-height: 1.6;">
                                        Thank you for choosing GoerOne.<br><br>
                                        Warm regards,<br>
                                        <strong style="color: #111111;">Team GoerOne</strong>
                                    </td>
                                </tr>
                            </table>

                            {{-- Footer --}}
                            <table width="100%" border="0" cellspacing="0" cellpadding="0"
                                style="border-top: 1px solid #e8ecf5;">
                                <tr>
                                    <td
                                        style="font-size: 11px; color: #888888; line-height: 1.5; padding-top: 20px; text-align: left;">
                                        <strong>Note:</strong> This is an automated message from an unmonitored email
                                        address. Please do not reply to this email.
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