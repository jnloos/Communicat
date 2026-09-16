<?php

/*
|--------------------------------------------------------------------------
| Settings: profile, password, appearance, user management, account
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Settings',
    'subheading' => 'Manage your profile and account settings',

    'nav' => [
        'profile' => 'Profile',
        'password' => 'Password',
        'appearance' => 'Appearance',
        'users' => 'Users',
    ],

    'profile' => [
        'heading' => 'Profile',
        'subheading' => 'Update your name and email address',
        'email_unverified' => 'Your email address is unverified.',
        'resend_verification' => 'Click here to re-send the verification email.',
        'verification_sent' => 'A new verification link has been sent to your email address.',
    ],

    'password' => [
        'heading' => 'Update password',
        'subheading' => 'Ensure your account is using a long, random password to stay secure',
        'current' => 'Current password',
        'new' => 'New password',
        'confirm' => 'Confirm password',
    ],

    'appearance' => [
        'heading' => 'Appearance',
        'subheading' => 'Update the appearance settings for your account',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    'delete_account' => [
        'heading' => 'Delete account',
        'subheading' => 'Delete your account and all of its resources',
        'button' => 'Delete account',
        'confirm_title' => 'Are you sure you want to delete your account?',
        'confirm_message' => 'Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
    ],

    'users' => [
        'heading' => 'User Management',
        'subheading' => 'Create, edit and delete users',
        'column_admin' => 'Admin',
        'role_admin' => 'Admin',
        'role_user' => 'User',
        'create' => 'Create User',
        'edit' => 'Edit User',
        'password_keep' => 'Password (leave empty to keep)',
        'administrator' => 'Administrator',
    ],
];
