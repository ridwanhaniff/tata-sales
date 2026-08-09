<?php

return [
    /*
     * Nilai estimated_value lead yang otomatis di-flag bernilai tinggi
     * saat handoff ke manusia (§6 trigger table).
     */
    'high_value_threshold' => (float) env('SALES_HIGH_VALUE_THRESHOLD', 500_000_000),
];
