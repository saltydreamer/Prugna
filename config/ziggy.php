<?php

return [
    /*
    | Routes exposed to the frontend through the @routes Blade directive.
    | Development tooling routes are hidden.
    */
    'except' => ['_boost.*', 'boost.*', 'inertia.*', 'storage.*', 'debugbar.*', 'ignition.*'],
];
