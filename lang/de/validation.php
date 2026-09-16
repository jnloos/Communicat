<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Standard-Fehlermeldungen der Validierung (deutsche Übersetzung der
    | Laravel-Standarddatei). Schlüssel identisch zu lang/en/validation.php.
    |
    */

    'accepted' => ':attribute muss akzeptiert werden.',
    'accepted_if' => ':attribute muss akzeptiert werden, wenn :other :value ist.',
    'active_url' => ':attribute muss eine gültige URL sein.',
    'after' => ':attribute muss ein Datum nach :date sein.',
    'after_or_equal' => ':attribute muss ein Datum nach oder gleich :date sein.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Binde- und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => ':attribute ist ungültig.',
    'array' => ':attribute muss ein Array sein.',
    'ascii' => ':attribute darf nur alphanumerische Einzelbyte-Zeichen und Symbole enthalten.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'before_or_equal' => ':attribute muss ein Datum vor oder gleich :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Elemente haben.',
        'file' => ':attribute muss zwischen :min und :max Kilobytes groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss wahr oder falsch sein.',
    'can' => ':attribute enthält einen nicht erlaubten Wert.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'contains' => ':attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => ':attribute muss ein gültiges Datum sein.',
    'date_equals' => ':attribute muss ein Datum gleich :date sein.',
    'date_format' => ':attribute muss dem Format :format entsprechen.',
    'decimal' => ':attribute muss :decimal Nachkommastellen haben.',
    'declined' => ':attribute muss abgelehnt werden.',
    'declined_if' => ':attribute muss abgelehnt werden, wenn :other :value ist.',
    'different' => ':attribute und :other müssen sich unterscheiden.',
    'digits' => ':attribute muss :digits Ziffern lang sein.',
    'digits_between' => ':attribute muss zwischen :min und :max Ziffern lang sein.',
    'dimensions' => ':attribute hat ungültige Bildabmessungen.',
    'distinct' => ':attribute enthält einen doppelten Wert.',
    'doesnt_contain' => ':attribute darf keinen der folgenden Werte enthalten: :values.',
    'doesnt_end_with' => ':attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => ':attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'encoding' => ':attribute muss in :encoding kodiert sein.',
    'ends_with' => ':attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Der gewählte Wert für :attribute ist ungültig.',
    'exists' => 'Der gewählte Wert für :attribute ist ungültig.',
    'extensions' => ':attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => ':attribute muss einen Wert haben.',
    'gt' => [
        'array' => ':attribute muss mehr als :value Elemente haben.',
        'file' => ':attribute muss größer als :value Kilobytes sein.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => ':attribute muss mindestens :value Elemente haben.',
        'file' => ':attribute muss größer oder gleich :value Kilobytes sein.',
        'numeric' => ':attribute muss größer oder gleich :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => ':attribute muss eine gültige hexadezimale Farbe sein.',
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'in_array' => ':attribute muss in :other vorkommen.',
    'in_array_keys' => ':attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':attribute muss ein gültiger JSON-String sein.',
    'list' => ':attribute muss eine Liste sein.',
    'lowercase' => ':attribute muss in Kleinbuchstaben geschrieben sein.',
    'lt' => [
        'array' => ':attribute muss weniger als :value Elemente haben.',
        'file' => ':attribute muss kleiner als :value Kilobytes sein.',
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => ':attribute darf nicht mehr als :value Elemente haben.',
        'file' => ':attribute muss kleiner oder gleich :value Kilobytes sein.',
        'numeric' => ':attribute muss kleiner oder gleich :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => ':attribute muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => ':attribute darf nicht mehr als :max Elemente haben.',
        'file' => ':attribute darf nicht größer als :max Kilobytes sein.',
        'numeric' => ':attribute darf nicht größer als :max sein.',
        'string' => ':attribute darf nicht länger als :max Zeichen sein.',
    ],
    'max_digits' => ':attribute darf nicht mehr als :max Ziffern haben.',
    'mimes' => ':attribute muss eine Datei vom Typ :values sein.',
    'mimetypes' => ':attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => ':attribute muss mindestens :min Elemente haben.',
        'file' => ':attribute muss mindestens :min Kilobytes groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => ':attribute muss mindestens :min Ziffern haben.',
    'missing' => ':attribute darf nicht vorhanden sein.',
    'missing_if' => ':attribute darf nicht vorhanden sein, wenn :other :value ist.',
    'missing_unless' => ':attribute darf nicht vorhanden sein, außer :other ist :value.',
    'missing_with' => ':attribute darf nicht vorhanden sein, wenn :values vorhanden ist.',
    'missing_with_all' => ':attribute darf nicht vorhanden sein, wenn :values vorhanden sind.',
    'multiple_of' => ':attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'not_regex' => 'Das Format von :attribute ist ungültig.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute ist in einem Datenleck aufgetaucht. Bitte wählen Sie ein anderes :attribute.',
    ],
    'present' => ':attribute muss vorhanden sein.',
    'present_if' => ':attribute muss vorhanden sein, wenn :other :value ist.',
    'present_unless' => ':attribute muss vorhanden sein, außer :other ist :value.',
    'present_with' => ':attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => ':attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => ':attribute ist nicht erlaubt.',
    'prohibited_if' => ':attribute ist nicht erlaubt, wenn :other :value ist.',
    'prohibited_if_accepted' => ':attribute ist nicht erlaubt, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => ':attribute ist nicht erlaubt, wenn :other abgelehnt wurde.',
    'prohibited_unless' => ':attribute ist nicht erlaubt, außer :other ist in :values enthalten.',
    'prohibits' => ':attribute verbietet die Angabe von :other.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => ':attribute ist ein Pflichtfeld.',
    'required_array_keys' => ':attribute muss Einträge für :values enthalten.',
    'required_if' => ':attribute ist erforderlich, wenn :other :value ist.',
    'required_if_accepted' => ':attribute ist erforderlich, wenn :other akzeptiert wurde.',
    'required_if_declined' => ':attribute ist erforderlich, wenn :other abgelehnt wurde.',
    'required_unless' => ':attribute ist erforderlich, außer :other ist in :values enthalten.',
    'required_with' => ':attribute ist erforderlich, wenn :values vorhanden ist.',
    'required_with_all' => ':attribute ist erforderlich, wenn :values vorhanden sind.',
    'required_without' => ':attribute ist erforderlich, wenn :values nicht vorhanden ist.',
    'required_without_all' => ':attribute ist erforderlich, wenn keiner der Werte :values vorhanden ist.',
    'same' => ':attribute und :other müssen übereinstimmen.',
    'size' => [
        'array' => ':attribute muss genau :size Elemente enthalten.',
        'file' => ':attribute muss :size Kilobytes groß sein.',
        'numeric' => ':attribute muss gleich :size sein.',
        'string' => ':attribute muss :size Zeichen lang sein.',
    ],
    'starts_with' => ':attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':attribute muss ein Text sein.',
    'timezone' => ':attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':attribute muss in Großbuchstaben geschrieben sein.',
    'url' => ':attribute muss eine gültige URL sein.',
    'ulid' => ':attribute muss eine gültige ULID sein.',
    'uuid' => ':attribute muss eine gültige UUID sein.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Lesbare Feldnamen für die Formulare der Anwendung.
    |
    */

    'attributes' => [
        'avatarUpload' => 'Avatar',
        'current_password' => 'Aktuelles Passwort',
        'description' => 'Beschreibung',
        'email' => 'E-Mail-Adresse',
        'frequency' => 'Gedächtnisreduktion',
        'importFile' => 'Importdatei',
        'is_admin' => 'Administrator',
        'job' => 'Beruf',
        'msgContent' => 'Nachricht',
        'name' => 'Name',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwortbestätigung',
        'title' => 'Titel',
        'token' => 'Token',
    ],

];
