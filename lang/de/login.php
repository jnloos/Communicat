<?php

/*
|--------------------------------------------------------------------------
| Anmeldung, Registrierung, Passwort-Reset, E-Mail-Bestätigung
|--------------------------------------------------------------------------
*/

return [
    'layout_title' => 'Anmeldung',
    'email_placeholder' => 'name@beispiel.de',

    'sign_in' => [
        'title' => 'Anmelden',
        'heading' => 'Bei Ihrem Konto anmelden',
        'description' => 'Geben Sie unten Ihre E-Mail-Adresse und Ihr Passwort ein, um sich anzumelden',
        'forgot_password' => 'Passwort vergessen?',
        'remember_me' => 'Angemeldet bleiben',
        'submit' => 'Anmelden',
        'no_account' => 'Noch kein Konto?',
        'sign_up' => 'Registrieren',
    ],

    'register' => [
        'title' => 'Registrieren',
        'heading' => 'Konto erstellen',
        'description' => 'Geben Sie unten Ihre Daten ein, um ein Konto zu erstellen',
        'full_name' => 'Vollständiger Name',
        'confirm_password' => 'Passwort bestätigen',
        'submit' => 'Konto erstellen',
        'have_account' => 'Sie haben bereits ein Konto?',
        'log_in' => 'Anmelden',
    ],

    'forgot_password' => [
        'title' => 'Passwort vergessen',
        'heading' => 'Passwort vergessen',
        'description' => 'Geben Sie Ihre E-Mail-Adresse ein, um einen Link zum Zurücksetzen des Passworts zu erhalten',
        'submit' => 'Link zum Zurücksetzen senden',
        'return_to' => 'Oder zurück zur',
        'log_in' => 'Anmeldung',
        'link_sent' => 'Falls das Konto existiert, wird ein Link zum Zurücksetzen gesendet.',
    ],

    'reset_password' => [
        'title' => 'Passwort zurücksetzen',
        'heading' => 'Passwort zurücksetzen',
        'description' => 'Bitte geben Sie unten Ihr neues Passwort ein',
        'confirm_password' => 'Passwort bestätigen',
        'submit' => 'Passwort zurücksetzen',
    ],

    'confirm_password' => [
        'title' => 'Passwort bestätigen',
        'heading' => 'Passwort bestätigen',
        'description' => 'Dies ist ein geschützter Bereich der Anwendung. Bitte bestätigen Sie Ihr Passwort, bevor Sie fortfahren.',
    ],

    'verify_email' => [
        'title' => 'E-Mail-Adresse bestätigen',
        'instructions' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse über den Link, den wir Ihnen gerade per E-Mail gesendet haben.',
        'link_sent' => 'Ein neuer Bestätigungslink wurde an die bei der Registrierung angegebene E-Mail-Adresse gesendet.',
        'resend' => 'Bestätigungs-E-Mail erneut senden',
        'log_out' => 'Abmelden',
    ],
];
