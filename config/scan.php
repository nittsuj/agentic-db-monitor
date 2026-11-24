<?php

return [

    /*
    | Pause after each table of a schema scan, in milliseconds. 0 in normal use;
    | raise it (e.g. 400) for a demo, so the progress bar visibly moves even on
    | a database with only a few tables.
    */
    'table_delay_ms' => (int) env('SCAN_TABLE_DELAY_MS', 0),

];
