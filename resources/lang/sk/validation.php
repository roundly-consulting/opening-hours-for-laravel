<?php

declare(strict_types=1);

return [
    'invalid_structure' => 'Hodnota :path má neplatnú štruktúru.',
    'invalid_time' => 'Čas :path je neplatný; použite HH:MM od 00:00 do 24:00.',
    'empty_range' => 'Hodnota :path je prázdny rozsah; začína a končí v rovnakom čase.',
    'start_at_24' => 'Hodnota :path nemôže začínať o 24:00.',
    'overlap' => 'Rozsah :path sa prekrýva s :other.',
    'invalid_date' => 'Hodnota :path je neplatná; použite skutočný dátum Y-m-d.',
    'invalid_month_day' => 'Deň :path je neplatný; použite m-d.',
    'window_inverted' => 'Hodnota :path končí skôr, ako začína.',
    'recurrence_mismatch' => 'Hodnota :path mieša jednorazové a každoročné dátumy; pre jednorazové dátumy použite Y-m-d, pre každoročné m-d.',
    'yearly_window_unbounded' => 'Hodnota :path je každoročná a potrebuje začiatok aj koniec.',
    'duplicate_base_schedule' => 'Hodnota :path nemá obdobie platnosti, no iný rozvrh už takýto je.',
    'ambiguous_schedule_window' => 'Obdobie :path sa prekrýva s :other s rovnakou prioritou.',
    'duplicate_exception' => 'Hodnota :path duplikuje :other.',
    'ambiguous_exception' => 'Obdobie :path sa prekrýva s :other s rovnakou dĺžkou, preto nemôže mať ani jedno prednosť.',
    'invalid_timezone' => 'Hodnota :path nie je platné IANA časové pásmo.',
    'timezone_required' => 'Časové pásmo je povinné.',
    'invalid_calendar_key' => 'Hodnota :path môže obsahovať najviac 64 malých písmen, číslic, pomlčiek a podčiarkovníkov.',
    'unknown_weekday' => 'Deň v týždni :path je neznámy.',
    'invalid_priority' => 'Hodnota :path musí byť medzi :min a :max.',
    'invalid_capacity' => 'Hodnota :path musí byť medzi :min a :max.',
    'limit_exceeded' => 'Zoznam :path môže mať najviac :limit položiek.',
    'label_too_long' => 'Hodnota :path môže mať najviac :max znakov.',
    'meta_too_large' => 'Hodnota :path môže mať najviac :max bajtov.',
    'unknown_id' => 'Hodnota :id v :path nepatrí tomuto kalendáru.',
    'duplicate_id' => 'Hodnota :id v :path je použitá viackrát.',
    'unsupported_week_array_feature' => 'Funkcia týždenného poľa „:feature“ nie je podporovaná.',
];
