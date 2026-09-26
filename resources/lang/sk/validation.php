<?php

declare(strict_types=1);

return [
    'invalid_structure' => 'Hodnota :path má neplatnú štruktúru.',
    'invalid_time' => 'Čas :path je neplatný; použite HH:MM od 00:00 do 24:00.',
    'empty_range' => 'Rozsah :path je prázdny; začína a končí v rovnakom čase.',
    'start_at_24' => 'Rozsah :path nemôže začínať o 24:00.',
    'overlap' => 'Rozsah :path sa prekrýva s :other.',
    'invalid_date' => 'Dátum :path je neplatný; použite skutočný dátum Y-m-d.',
    'invalid_month_day' => 'Deň :path je neplatný; použite m-d.',
    'window_inverted' => 'Obdobie :path končí skôr, ako začína.',
    'recurrence_mismatch' => 'Dátumy :path nezodpovedajú opakovaniu; pre jednorazové dátumy použite Y-m-d, pre každoročné m-d.',
    'yearly_window_unbounded' => 'Každoročné obdobie :path potrebuje začiatok aj koniec.',
    'duplicate_base_schedule' => 'Rozvrh :path nemá obdobie platnosti, no iný rozvrh už takýto je.',
    'ambiguous_schedule_window' => 'Rozvrh :path sa prekrýva s :other s rovnakou prioritou.',
    'duplicate_exception' => 'Výnimka :path duplikuje :other.',
    'ambiguous_exception' => 'Výnimka :path sa prekrýva s :other s rovnakou dĺžkou, preto nemôže mať ani jedna prednosť.',
    'invalid_timezone' => 'Časové pásmo :path nie je platné IANA časové pásmo.',
    'timezone_required' => 'Časové pásmo je povinné.',
    'invalid_calendar_key' => 'Kľúč kalendára :path môže obsahovať najviac 64 malých písmen, číslic, pomlčiek a podčiarkovníkov.',
    'unknown_weekday' => 'Deň v týždni :path je neznámy.',
    'invalid_priority' => 'Priorita :path musí byť medzi :min a :max.',
    'invalid_capacity' => 'Kapacita :path musí byť medzi :min a :max.',
    'limit_exceeded' => 'Zoznam :path môže mať najviac :limit položiek.',
    'label_too_long' => 'Popis :path môže mať najviac :max znakov.',
    'meta_too_large' => 'Meta údaje :path môžu mať najviac :max bajtov.',
    'unknown_id' => 'Identifikátor :id v :path nepatrí tomuto kalendáru.',
    'unsupported_week_array_feature' => 'Funkcia týždenného poľa „:feature“ nie je podporovaná.',
];
