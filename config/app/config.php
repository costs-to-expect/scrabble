<?php
declare(strict_types=1);

return [
    'api_url' => env('API_URL', 'https://api.costs-to-expect.com'),
    'api_url_dev' => env('API_URL_DEV', 'https://api.costs-to-expect.com'),
    'dev' => env('APP_DEV', false),
    'item_type_id' => env('ITEM_TYPE_ID'),
    'item_subtype_id' => env('ITEM_SUBTYPE_ID'),
    'error_email' => env('ERROR_EMAIL'),
    'internal_key' => env('COSTS_TO_EXPECT_INTERNAL_API_KEY'),
    'cookie_user' => env('SESSION_NAME_USER'),
    'cookie_bearer' => env('SESSION_NAME_BEARER'),
    // Undo, change and remove a turn. Nothing is ever taken out of a stored score sheet, a removed turn is marked as
    // removed and every turn is always written in full, so corrections work whether the API replaces the sheet it is
    // sent or merges it into the stored one. Set this to false to lock every turn once it has been saved.
    'score_corrections' => (bool) env('SCORE_CORRECTIONS', true),
];
