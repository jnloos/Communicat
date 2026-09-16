<?php

/*
|--------------------------------------------------------------------------
| Einstellungen: Profil, Passwort, Darstellung, Nutzerverwaltung, Konto
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Einstellungen',
    'subheading' => 'Verwalten Sie Ihr Profil und Ihre Kontoeinstellungen',

    'nav' => [
        'profile' => 'Profil',
        'password' => 'Passwort',
        'appearance' => 'Darstellung',
        'users' => 'Nutzer',
    ],

    'profile' => [
        'heading' => 'Profil',
        'subheading' => 'Aktualisieren Sie Ihren Namen und Ihre E-Mail-Adresse',
        'email_unverified' => 'Ihre E-Mail-Adresse ist nicht bestätigt.',
        'resend_verification' => 'Hier klicken, um die Bestätigungs-E-Mail erneut zu senden.',
        'verification_sent' => 'Ein neuer Bestätigungslink wurde an Ihre E-Mail-Adresse gesendet.',
    ],

    'password' => [
        'heading' => 'Passwort ändern',
        'subheading' => 'Verwenden Sie ein langes, zufälliges Passwort, damit Ihr Konto sicher bleibt',
        'current' => 'Aktuelles Passwort',
        'new' => 'Neues Passwort',
        'confirm' => 'Passwort bestätigen',
    ],

    'appearance' => [
        'heading' => 'Darstellung',
        'subheading' => 'Passen Sie die Darstellung für Ihr Konto an',
        'light' => 'Hell',
        'dark' => 'Dunkel',
        'system' => 'System',
    ],

    'delete_account' => [
        'heading' => 'Konto löschen',
        'subheading' => 'Löschen Sie Ihr Konto und alle zugehörigen Daten',
        'button' => 'Konto löschen',
        'confirm_title' => 'Möchten Sie Ihr Konto wirklich löschen?',
        'confirm_message' => 'Sobald Ihr Konto gelöscht ist, werden alle zugehörigen Daten dauerhaft entfernt. Bitte geben Sie Ihr Passwort ein, um die endgültige Löschung Ihres Kontos zu bestätigen.',
    ],

    'users' => [
        'heading' => 'Nutzerverwaltung',
        'subheading' => 'Nutzer anlegen, bearbeiten und löschen',
        'column_admin' => 'Admin',
        'role_admin' => 'Admin',
        'role_user' => 'Nutzer',
        'create' => 'Nutzer anlegen',
        'edit' => 'Nutzer bearbeiten',
        'password_keep' => 'Passwort (leer lassen, um es beizubehalten)',
        'administrator' => 'Administrator',
    ],
];
