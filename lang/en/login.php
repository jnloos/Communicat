<?php

/*
|--------------------------------------------------------------------------
| Login, registration, password reset, email verification
|--------------------------------------------------------------------------
*/

return [
    'layout_title' => 'Authentication',
    'email_placeholder' => 'email@example.com',

    'sign_in' => [
        'title' => 'Log in',
        'heading' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'forgot_password' => 'Forgot your password?',
        'remember_me' => 'Remember me',
        'submit' => 'Log in',
        'no_account' => 'Don\'t have an account?',
        'sign_up' => 'Sign up',
    ],

    'register' => [
        'title' => 'Sign up',
        'heading' => 'Create an account',
        'description' => 'Enter your details below to create your account',
        'full_name' => 'Full name',
        'confirm_password' => 'Confirm password',
        'submit' => 'Create account',
        'have_account' => 'Already have an account?',
        'log_in' => 'Log in',
    ],

    'forgot_password' => [
        'title' => 'Forgot password',
        'heading' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'submit' => 'Email password reset link',
        'return_to' => 'Or, return to',
        'log_in' => 'log in',
        'link_sent' => 'A reset link will be sent if the account exists.',
    ],

    'reset_password' => [
        'title' => 'Reset password',
        'heading' => 'Reset password',
        'description' => 'Please enter your new password below',
        'confirm_password' => 'Confirm password',
        'submit' => 'Reset password',
    ],

    'confirm_password' => [
        'title' => 'Confirm password',
        'heading' => 'Confirm password',
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
    ],

    'verify_email' => [
        'title' => 'Verify email address',
        'instructions' => 'Please verify your email address by clicking on the link we just emailed to you.',
        'link_sent' => 'A new verification link has been sent to the email address you provided during registration.',
        'resend' => 'Resend verification email',
        'log_out' => 'Log out',
    ],
];
