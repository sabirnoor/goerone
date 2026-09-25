<!DOCTYPE html>
<html>
<head>
    <title>Your Account Credentials</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            padding: 20px;
            background-color: #fff;
            border-left: 1px solid #eee;
            border-right: 1px solid #eee;
        }
        .footer {
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #777;
            background-color: #f8f9fa;
            border-radius: 0 0 5px 5px;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #3490dc;
            color: #fff !important;
            text-decoration: none;
            border-radius: 4px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>{{ $user->name }}</h2>
    </div>
    
    <div class="content">
        <h3>Hello {{ $user->fname }},</h3>
        
        @if($isNewAccount)
            <p>Your account has been created successfully. Here are your login credentials:</p>
        @else
            <p>Your password has been reset as requested. Here are your new login credentials:</p>
        @endif
        
        <p><strong>Email:</strong> {{ $user->email }}</p>
        <p><strong>Password:</strong> {{ $password }}</p>
        
        <p>For security reasons, we recommend that you change your password after logging in.</p>
        
        <p>
            <a href="{{ route('login') }}" class="button">Login to Your Account</a>
        </p>
        
        <p>If you didn't request this account, please ignore this email.</p>
    </div>
    
    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>