<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Payment confirmed for {{ $store_name ?? '' }}</title>
    <style>
        body { margin: 0; padding: 0; }
        table, td { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; }
        @media only screen and (max-width: 620px) {
            .px { padding-left: 22px !important; padding-right: 22px !important; }
            .hero-amount { font-size: 30px !important; }
            .h1 { font-size: 22px !important; }
            .btn a { display: block !important; text-align: center !important; }
        }
    </style>
</head>

<body style="margin:0;padding:0;background-color:#eef2fb;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    {{-- Inbox preview text --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#eef2fb;">
        GoerOne confirmed a customer payment of {{ $payment_amount ?? '' }} for your store.
    </div>

    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#eef2fb" style="background-color:#eef2fb;">
        <tr>
            <td align="center" style="padding:32px 14px;">

                <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width:600px;">

                    {{-- Accent bar --}}
                    <tr>
                        <td height="6" style="height:6px;line-height:6px;font-size:0;background-color:#1d4ed8;background-image:linear-gradient(90deg,#1d4ed8,#3b82f6);border-radius:16px 16px 0 0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td bgcolor="#ffffff" class="px" style="background-color:#ffffff;padding:32px 36px 36px;border-left:1px solid #e3e9f7;border-right:1px solid #e3e9f7;border-bottom:1px solid #e3e9f7;border-radius:0 0 16px 16px;">

                            {{-- Logo --}}
                            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" style="padding-bottom:24px;border-bottom:1px solid #edf0f7;">
                                        <img src="{{ asset('goerone_logo.png') }}" alt="GoerOne" height="40" style="display:block;height:40px;width:auto;border:0;outline:none;text-decoration:none;">
                                    </td>
                                </tr>
                            </table>

                            {{-- Heading --}}
                            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:30px;">
                                <tr>
                                    <td>
                                        <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" valign="middle" width="44" height="44" style="width:44px;height:44px;background-color:#e8efff;border-radius:22px;color:#1d4ed8;font-size:22px;font-weight:bold;line-height:44px;">&#10003;</td>
                                                <td style="padding-left:12px;">
                                                    <span style="display:inline-block;background-color:#e7f8ee;color:#157347;font-size:11px;font-weight:bold;letter-spacing:0.8px;text-transform:uppercase;padding:5px 12px;border-radius:999px;">Confirmed</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="h1" style="font-size:26px;font-weight:800;color:#0f2a6b;line-height:1.25;padding-top:18px;">
                                        Customer Payment Confirmed
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:15px;color:#1f2937;line-height:1.6;padding-top:18px;">
                                        Hi {{ $store_name ?? '' }} Team,
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:15px;color:#4b5563;line-height:1.7;padding-top:10px;padding-bottom:26px;">
                                        We are pleased to inform you that GoerOne has successfully confirmed a customer payment for your store. The details are provided below.
                                    </td>
                                </tr>
                            </table>

                            {{-- Summary card --}}
                            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="border:1px solid #dbe4ff;border-radius:12px;border-collapse:separate;background-color:#f7f9ff;">
                                <tr>
                                    <td bgcolor="#1d4ed8" style="background-color:#1d4ed8;background-image:linear-gradient(135deg,#1d4ed8,#2f6df0);border-radius:11px 11px 0 0;padding:22px 24px;">
                                        <div style="font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#cfdcff;">Payment amount</div>
                                        <div class="hero-amount" style="font-size:34px;font-weight:800;color:#ffffff;line-height:1.2;padding-top:6px;">{{ $payment_amount ?? '' }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 24px 8px;">
                                        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td valign="top" style="padding:14px 0;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;width:42%;">Confirmed on</td>
                                                <td valign="top" align="right" style="padding:14px 0;font-size:14px;font-weight:bold;color:#111827;line-height:1.5;">{{ $payment_at ?? '' }}</td>
                                            </tr>
                                            <tr>
                                                <td valign="top" style="border-top:1px solid #e1e8fb;padding:14px 0;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;width:42%;">Payment reference</td>
                                                <td valign="top" align="right" style="border-top:1px solid #e1e8fb;padding:14px 0;font-size:14px;font-weight:bold;color:#111827;line-height:1.5;">{{ $payment_ref ?? '' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- CTA --}}
                            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:28px;">
                                <tr>
                                    <td style="font-size:14px;color:#4b5563;line-height:1.6;padding-bottom:18px;">
                                        Log in to your Partner Dashboard to view the complete transaction details.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="btn">
                                        <a href="{{ $dashboard_url ?? config('app.url') }}" style="background-color:#1d4ed8;color:#ffffff;font-size:13px;font-weight:bold;text-decoration:none;padding:14px 32px;display:inline-block;border-radius:8px;text-transform:uppercase;letter-spacing:0.6px;">Open Partner Dashboard</a>
                                    </td>
                                </tr>
                            </table>

                            {{-- Sign-off --}}
                            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:34px;">
                                <tr>
                                    <td style="font-size:14px;color:#4b5563;line-height:1.7;">
                                        Thank you for being a valued GoerOne partner.<br><br>
                                        Best regards,<br>
                                        <strong style="color:#0f2a6b;">Team GoerOne</strong>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>

                {{-- Footer --}}
                <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width:600px;">
                    <tr>
                        <td align="center" style="font-size:12px;color:#8a94a8;line-height:1.6;padding:22px 24px 0;">
                            <strong style="color:#6b7690;">Note:</strong> This is an automated message from an unmonitored email address. Please do not reply to this email.
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="font-size:12px;color:#a3acbf;line-height:1.6;padding:8px 24px 0;">
                            &copy; {{ date('Y') }} GoerOne. All rights reserved.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>

</html>