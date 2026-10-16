<?php

return [
    // The first QR number Generate QR hands out (START_QR_POINT in .env);
    // every later one is the previous + 1 — see QrCodeNumberService.
    'start_point' => (int) env('START_QR_POINT', 10000000),
];
